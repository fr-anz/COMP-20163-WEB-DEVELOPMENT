<?php

declare(strict_types=1);

namespace Habi\Bootstrap;

use PDO;
use RuntimeException;

/**
 * v1 → v2 schema upgrade (SDD §15.2, §15.9). Runs inside Initializer's
 * BEGIN IMMEDIATE transaction with foreign keys switched off, as SQLite's
 * table-rebuild procedure requires; the caller checks foreign keys before
 * committing.
 *
 * products and orders are rebuilt because their v1 CHECK constraints reject
 * v2 values. Every row keeps its id, so images, items, audit rows and
 * sessions stay valid. On a fresh install the same steps run on empty
 * tables. Each step checks the live schema, so the upgrade is idempotent.
 */
final class HeritageUpgrade
{
    /** Canonical categories in display order (N7). */
    public const CATEGORIES = ['Tote', 'Satchel', 'Sling', 'Clutch'];

    /** Controlled fiber list (SDD §15.2). */
    public const FIBERS = ['buri', 'pandan', 'tikog', 'abaca', 'other'];

    public const PLACEHOLDER_WEAVER_SLUG = 'unassigned';

    public static function isApplied(PDO $pdo): bool
    {
        return self::hasColumn($pdo, 'products', 'listing_status')
            && self::hasColumn($pdo, 'orders', 'payment_status')
            && self::hasColumn($pdo, 'order_items', 'weaver_id')
            && self::hasColumn($pdo, 'stock_audit', 'weaver_id');
    }

    public static function apply(PDO $pdo): void
    {
        self::insertCategories($pdo);

        if (!self::hasColumn($pdo, 'products', 'listing_status')) {
            self::rebuildProducts($pdo);
        }
        if (!self::hasColumn($pdo, 'orders', 'payment_status')) {
            self::rebuildOrders($pdo);
        }
        if (!self::hasColumn($pdo, 'order_items', 'weaver_id')) {
            $pdo->exec('ALTER TABLE order_items ADD COLUMN weaver_id INTEGER REFERENCES weavers(id)');
            $pdo->exec('ALTER TABLE order_items ADD COLUMN weaver_name_snapshot TEXT');
        }
        if (!self::hasColumn($pdo, 'stock_audit', 'weaver_id')) {
            $pdo->exec(
                'ALTER TABLE stock_audit ADD COLUMN weaver_id INTEGER REFERENCES weavers(id) ON DELETE SET NULL'
            );
        }

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_products_weaver ON products (weaver_id);
             CREATE INDEX IF NOT EXISTS idx_products_category_listing ON products (category_id, listing_status);
             CREATE INDEX IF NOT EXISTS idx_products_fiber ON products (fiber);
             CREATE INDEX IF NOT EXISTS idx_products_region ON products (region);
             CREATE INDEX IF NOT EXISTS idx_orders_status_created ON orders (status, created_at);
             CREATE INDEX IF NOT EXISTS idx_orders_payment_created ON orders (payment_status, created_at);
             CREATE INDEX IF NOT EXISTS idx_orders_customer_email ON orders (customer_email);
             CREATE INDEX IF NOT EXISTS idx_order_items_weaver ON order_items (weaver_id);'
        );
    }

    /**
     * The inactive weaver that owns products without a real weaver until an
     * administrator reassigns them. Created on first use; it cannot sign in.
     */
    public static function placeholderWeaverId(PDO $pdo): int
    {
        $pdo->prepare(
            "INSERT OR IGNORE INTO weavers (email, password_hash, slug, display_name, is_active)
             VALUES ('unassigned@weavers.invalid', '!', ?, 'Unassigned weaver', 0)"
        )->execute([self::PLACEHOLDER_WEAVER_SLUG]);
        $statement = $pdo->prepare('SELECT id FROM weavers WHERE slug = ?');
        $statement->execute([self::PLACEHOLDER_WEAVER_SLUG]);

        return (int) $statement->fetchColumn();
    }

    /** Fails the upgrade when any foreign key is dangling. */
    public static function assertForeignKeys(PDO $pdo): void
    {
        $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
        if ($violations !== []) {
            throw new RuntimeException(sprintf(
                'Schema upgrade aborted: %d foreign key violation(s), first in table %s.',
                count($violations),
                (string) $violations[0]['table']
            ));
        }
    }

    private static function insertCategories(PDO $pdo): void
    {
        $insert = $pdo->prepare('INSERT OR IGNORE INTO categories (slug, name, sort_order) VALUES (?, ?, ?)');
        foreach (self::CATEGORIES as $index => $name) {
            $insert->execute([strtolower($name), $name, $index + 1]);
        }
    }

    private static function rebuildProducts(PDO $pdo): void
    {
        // v1 rows may carry categories outside the canonical list.
        $pdo->exec(
            "INSERT OR IGNORE INTO categories (slug, name, sort_order)
             SELECT DISTINCT lower(category), category, 99 FROM products"
        );

        $pdo->exec(<<<'SQL'
            CREATE TABLE products_v2 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku TEXT NOT NULL UNIQUE,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                weaver_id INTEGER NOT NULL REFERENCES weavers(id),
                category_id INTEGER NOT NULL REFERENCES categories(id),
                category TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                description_fil TEXT,
                materials TEXT NOT NULL DEFAULT '',
                place_of_make TEXT NOT NULL DEFAULT '',
                fiber TEXT NOT NULL DEFAULT 'other',
                community TEXT NOT NULL DEFAULT '',
                region TEXT NOT NULL DEFAULT '',
                technique TEXT NOT NULL DEFAULT '',
                story TEXT NOT NULL DEFAULT '',
                story_fil TEXT,
                price_centavos INTEGER NOT NULL CHECK (price_centavos >= 0),
                stock INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
                low_stock_threshold INTEGER NOT NULL DEFAULT 3 CHECK (low_stock_threshold >= 0),
                dimensions TEXT NOT NULL DEFAULT '{}',
                three_config TEXT,
                listing_status TEXT NOT NULL DEFAULT 'active'
                    CHECK (listing_status IN ('pending_review', 'active', 'archived', 'deactivated')),
                is_available INTEGER NOT NULL DEFAULT 1 CHECK (is_available IN (0, 1)),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
            SQL);

        $hasProducts = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() > 0;
        $placeholder = $hasProducts ? self::placeholderWeaverId($pdo) : 0;
        $fiber = 'CASE';
        foreach (array_diff(self::FIBERS, ['other']) as $name) {
            $fiber .= " WHEN lower(p.materials) LIKE '%{$name}%' THEN '{$name}'";
        }
        $fiber .= " ELSE 'other' END";

        $pdo->prepare(<<<SQL
            INSERT INTO products_v2 (id, sku, slug, name, weaver_id, category_id, category, description,
                materials, place_of_make, fiber, price_centavos, stock, low_stock_threshold, dimensions,
                three_config, listing_status, created_at, updated_at)
            SELECT p.id, p.sku, p.slug, p.name, ?, c.id, p.category, p.description, p.materials,
                p.place_of_make, {$fiber}, p.price_centavos, p.stock, p.low_stock_threshold, p.dimensions,
                p.three_config, CASE p.is_active WHEN 1 THEN 'active' ELSE 'archived' END,
                p.created_at, p.updated_at
            FROM products p JOIN categories c ON c.name = p.category
            SQL)->execute([$placeholder]);

        self::swap($pdo, 'products');
    }

    private static function rebuildOrders(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE orders_v2 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_number TEXT NOT NULL UNIQUE,
                status TEXT NOT NULL CHECK (status IN (
                    'pending', 'confirmed', 'packed', 'shipped', 'delivered', 'rejected', 'cancelled'
                )),
                payment_method TEXT NOT NULL CHECK (payment_method IN ('cod', 'transfer', 'card')),
                payment_status TEXT NOT NULL CHECK (payment_status IN (
                    'unpaid', 'for_verification', 'paid', 'rejected'
                )),
                customer_name TEXT NOT NULL,
                customer_email TEXT NOT NULL,
                customer_phone TEXT NOT NULL DEFAULT '',
                shipping_address1 TEXT NOT NULL DEFAULT '',
                shipping_address2 TEXT NOT NULL DEFAULT '',
                shipping_city TEXT NOT NULL DEFAULT '',
                shipping_postal_code TEXT NOT NULL DEFAULT '',
                shipping_region TEXT NOT NULL DEFAULT '',
                shipping_fee_centavos INTEGER NOT NULL DEFAULT 0 CHECK (shipping_fee_centavos >= 0),
                subtotal_centavos INTEGER NOT NULL DEFAULT 0 CHECK (subtotal_centavos >= 0),
                total_centavos INTEGER NOT NULL DEFAULT 0 CHECK (total_centavos >= 0),
                payment_last4 TEXT,
                payment_brand TEXT,
                payment_result TEXT,
                rejection_reason TEXT,
                idempotency_key TEXT UNIQUE,
                status_changed_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
            SQL);

        // v1 checkout only created paid card orders; pending_payment/failed
        // were never used and map to cancelled (SDD §15.9).
        $pdo->exec(<<<'SQL'
            INSERT INTO orders_v2 (id, order_number, status, payment_method, payment_status, customer_name,
                customer_email, customer_phone, shipping_address1, shipping_address2, shipping_city,
                shipping_postal_code, shipping_region, shipping_fee_centavos, subtotal_centavos,
                total_centavos, payment_last4, payment_brand, payment_result, idempotency_key,
                status_changed_at, created_at, updated_at)
            SELECT id, order_number,
                CASE status WHEN 'paid' THEN 'confirmed' WHEN 'pending_payment' THEN 'cancelled'
                    WHEN 'failed' THEN 'cancelled' ELSE status END,
                'card',
                CASE WHEN status IN ('pending_payment', 'failed') THEN 'unpaid' ELSE 'paid' END,
                customer_name, customer_email, customer_phone, shipping_address1, shipping_address2,
                shipping_city, shipping_postal_code, shipping_region, shipping_fee_centavos,
                subtotal_centavos, total_centavos, payment_last4, payment_brand, payment_result,
                idempotency_key, updated_at, created_at, updated_at
            FROM orders
            SQL);
        $pdo->exec(<<<'SQL'
            INSERT INTO order_events (order_id, field, old_value, new_value, actor_role, note, created_at)
            SELECT o.id, 'status', o.status, v.status, 'system', 'migrated from v1', datetime('now')
            FROM orders o JOIN orders_v2 v ON v.id = o.id ORDER BY o.id
            SQL);

        self::swap($pdo, 'orders');
    }

    /** Replaces $table with its rebuilt {$table}_v2 copy (foreign keys must be off). */
    private static function swap(PDO $pdo, string $table): void
    {
        $pdo->exec("DROP TABLE {$table}");
        $pdo->exec("ALTER TABLE {$table}_v2 RENAME TO {$table}");
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $columns = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);

        return in_array($column, $columns, true);
    }
}
