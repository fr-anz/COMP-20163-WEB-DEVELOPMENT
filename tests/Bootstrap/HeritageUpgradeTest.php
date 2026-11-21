<?php

declare(strict_types=1);

namespace Habi\Tests\Bootstrap;

use Habi\Bootstrap\Initializer;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use PDO;

/**
 * M9 upgrade boundary (SDD §15.9): a v1 database in the current production
 * shape (001 + 002, inventory, orders, audit rows, administrator) upgrades in
 * place to the v2 schema. Nothing is reseeded, every row survives, v1 order
 * statuses are mapped with one system event each, categories are backfilled,
 * products without a weaver go to the inactive placeholder weaver, and a
 * second boot is a no-op.
 */
final class HeritageUpgradeTest extends IsolatedDatabaseTestCase
{
    public function testV1DatabaseUpgradesInPlaceAndPreservesData(): void
    {
        $pdo = $this->v1Database();

        Initializer::initialize($pdo, $this->migrationsDir());

        self::assertTrue(Initializer::isInitialized($pdo));
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());

        // Nothing reseeded: same admin, products, images, items and audit rows.
        self::assertSame(['Old Admin'], $this->column($pdo, 'SELECT name FROM admins'));
        self::assertSame(['OLD-TOTE', 'HBI-CLU-001'], $this->column($pdo, 'SELECT sku FROM products ORDER BY id'));
        self::assertSame(['7', '2'], $this->column($pdo, 'SELECT stock FROM products ORDER BY id'));
        self::assertSame(2, $this->rows($pdo, 'product_images'));
        self::assertSame(3, $this->rows($pdo, 'order_items'));
        self::assertSame(['Count correction'], $this->column($pdo, 'SELECT reason FROM stock_audit'));
        self::assertSame(0, $this->rows($pdo, 'journal_articles'));

        // Listing status follows is_active; categories are backfilled.
        self::assertSame(
            ['active', 'archived'],
            $this->column($pdo, 'SELECT listing_status FROM products ORDER BY id')
        );
        self::assertSame(
            ['Tote', 'Clutch'],
            $this->column($pdo, 'SELECT c.name FROM products p JOIN categories c ON c.id = p.category_id ORDER BY p.id')
        );
        self::assertSame(
            ['Tote', 'Satchel', 'Sling', 'Clutch'],
            $this->column($pdo, 'SELECT name FROM categories ORDER BY sort_order')
        );

        // No sample weavers exist on an upgrade, so every product goes to the placeholder.
        $placeholder = $pdo->query("SELECT id, is_active FROM weavers WHERE slug = 'unassigned'")->fetch();
        self::assertIsArray($placeholder);
        self::assertSame(0, (int) $placeholder['is_active']);
        self::assertSame(1, $this->rows($pdo, 'weavers'));
        self::assertSame(
            [(string) $placeholder['id'], (string) $placeholder['id']],
            $this->column($pdo, 'SELECT weaver_id FROM products ORDER BY id')
        );
        self::assertSame(['other', 'pandan'], $this->column($pdo, 'SELECT fiber FROM products ORDER BY id'));

        // Status mapping, payment fields and one migration event per order.
        $orders = $pdo->query(
            'SELECT order_number, status, payment_method, payment_status FROM orders ORDER BY id'
        )->fetchAll(PDO::FETCH_NUM);
        self::assertSame([
            ['H-20260101-0001', 'confirmed', 'card', 'paid'],
            ['H-20260101-0002', 'shipped', 'card', 'paid'],
            ['H-20260101-0003', 'cancelled', 'card', 'unpaid'],
        ], $orders);
        $events = $pdo->query(
            "SELECT field, old_value, new_value, actor_role, note FROM order_events ORDER BY order_id"
        )->fetchAll(PDO::FETCH_NUM);
        self::assertSame([
            ['status', 'paid', 'confirmed', 'system', 'migrated from v1'],
            ['status', 'shipped', 'shipped', 'system', 'migrated from v1'],
            ['status', 'pending_payment', 'cancelled', 'system', 'migrated from v1'],
        ], $events);

        // The rebuilt tables accept v2 values and keep their constraints.
        $pdo->exec("UPDATE orders SET status = 'pending' WHERE order_number = 'H-20260101-0001'");
        $this->assertWriteFails($pdo, "UPDATE orders SET status = 'paid' WHERE order_number = 'H-20260101-0001'");
        $this->assertWriteFails($pdo, "UPDATE products SET listing_status = 'gone' WHERE sku = 'OLD-TOTE'");
        $this->assertWriteFails($pdo, "INSERT INTO order_items (order_id, product_id, name_snapshot, price_centavos,"
            . ' qty, line_total_centavos) VALUES (999, 1, \'x\', 1, 1, 1)');

        // A second boot changes nothing.
        $before = $this->snapshot($pdo);
        Initializer::initialize($pdo, $this->migrationsDir());
        self::assertSame($before, $this->snapshot($pdo));
    }

    public function testUpgradeFailureRollsBackToTheV1Schema(): void
    {
        $pdo = $this->v1Database();
        // A dangling item reference (possible in v1 when foreign keys were off)
        // must abort the upgrade instead of committing a broken v2 schema.
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec("INSERT INTO order_items (order_id, product_id, name_snapshot, price_centavos, qty,"
            . " line_total_centavos) VALUES (1, 999, 'Ghost', 1, 1, 1)");
        $pdo->exec('PRAGMA foreign_keys = ON');

        try {
            Initializer::initialize($pdo, $this->migrationsDir());
            self::fail('Expected the upgrade to fail on a foreign key violation.');
        } catch (\RuntimeException) {
        }

        self::assertFalse(Initializer::isInitialized($pdo));
        self::assertSame(0, $this->rows($pdo, 'sqlite_master WHERE name = \'weavers\''));
        self::assertSame(
            ['paid', 'shipped', 'pending_payment'],
            $this->column($pdo, 'SELECT status FROM orders ORDER BY id')
        );
        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }

    /** Builds the current production shape: 001 + 002 with representative rows. */
    private function v1Database(): PDO
    {
        $pdo = $this->database();
        foreach (['001_schema.sql', '002_login_attempts.sql'] as $file) {
            $sql = file_get_contents($this->migrationsDir() . '/' . $file);
            self::assertNotFalse($sql);
            $pdo->exec($sql);
        }

        $pdo->prepare('INSERT INTO admins (email, password_hash, name) VALUES (?, ?, ?)')
            ->execute(['old-admin@example.test', password_hash('old-secret', PASSWORD_DEFAULT), 'Old Admin']);
        $pdo->exec(
            "INSERT INTO products
                 (sku, slug, name, category, materials, price_centavos, stock, three_config, is_active)
             VALUES ('OLD-TOTE', 'old-tote', 'Old Tote', 'Tote', 'Cotton canvas', 50000, 7, '{}', 1),
                    ('HBI-CLU-001', 'maya-clutch', 'Maya Clutch', 'Clutch', 'Pandan weave', 98000, 2, '{}', 0)"
        );
        $pdo->exec(
            "INSERT INTO product_images (product_id, path) VALUES (1, '/assets/img/products/old.svg'),
                    (2, '/assets/img/products/maya-clutch.svg')"
        );
        $pdo->exec(
            "INSERT INTO orders (order_number, status, customer_name, customer_email, subtotal_centavos,
                 total_centavos, payment_last4, payment_brand, payment_result, idempotency_key)
             VALUES ('H-20260101-0001', 'paid', 'A', 'a@example.com', 50000, 50000, '4242', 'Visa', 'approved', 'k1'),
                    ('H-20260101-0002', 'shipped', 'B', 'b@example.com', 98000, 98000,
                     '4242', 'Visa', 'approved', 'k2'),
                    ('H-20260101-0003', 'pending_payment', 'C', 'c@example.com', 50000, 50000, NULL, NULL, NULL, 'k3')"
        );
        $pdo->exec(
            "INSERT INTO order_items (order_id, product_id, name_snapshot, price_centavos, qty, line_total_centavos)
             VALUES (1, 1, 'Old Tote', 50000, 1, 50000), (2, 2, 'Maya Clutch', 98000, 1, 98000),
                    (3, 1, 'Old Tote', 50000, 1, 50000)"
        );
        $pdo->exec(
            "INSERT INTO stock_audit (product_id, admin_id, old_stock, new_stock, reason)
             VALUES (1, 1, 8, 7, 'Count correction')"
        );
        self::assertFalse(Initializer::isInitialized($pdo));

        return $pdo;
    }

    /** @return list<string> */
    private function column(PDO $pdo, string $sql): array
    {
        return array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    private function rows(PDO $pdo, string $table): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function assertWriteFails(PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
            self::fail('Expected a constraint failure: ' . $sql);
        } catch (\PDOException) {
            self::addToAssertionCount(1);
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(PDO $pdo): array
    {
        $tables = ['admins', 'weavers', 'categories', 'products', 'product_images', 'orders', 'order_items',
            'order_events', 'stock_audit', 'journal_articles'];
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
        }

        return $snapshot;
    }
}
