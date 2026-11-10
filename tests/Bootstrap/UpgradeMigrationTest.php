<?php

declare(strict_types=1);

namespace Habi\Tests\Bootstrap;

use Habi\Bootstrap\Initializer;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;

/**
 * M6 upgrade boundary: a database created by an earlier milestone (only
 * 001_schema.sql applied, no login_attempts) must be upgraded in place on
 * the next Initializer::initialize() — login_attempts appears, existing
 * products, orders, stock_audit rows and the administrator are untouched,
 * and nothing is reseeded.
 */
final class UpgradeMigrationTest extends IsolatedDatabaseTestCase
{
    public function testPreM6DatabaseIsUpgradedInPlaceWithoutReseeding(): void
    {
        $pdo = $this->database();

        // Reproduce an earlier-milestone database: base schema only.
        $base = file_get_contents($this->migrationsDir() . '/001_schema.sql');
        self::assertNotFalse($base);
        $pdo->exec($base);
        self::assertFalse(Initializer::isInitialized($pdo), 'login_attempts must be missing before the upgrade.');

        // Seeded data as an earlier milestone would have left it: one admin,
        // one product, one paid order with an item and a stock change.
        $pdo->prepare(
            'INSERT INTO admins (email, password_hash, name) VALUES (?, ?, ?)'
        )->execute(['old-admin@example.test', password_hash('old-secret', PASSWORD_DEFAULT), 'Old Admin']);
        $pdo->prepare(
            "INSERT INTO products (sku, slug, name, category, price_centavos, stock, is_active)
             VALUES ('OLD-001', 'old-bag', 'Old Bag', 'Tote', 50000, 7, 1)"
        )->execute();
        $productId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO orders (order_number, status, customer_name, customer_email, subtotal_centavos,
                 total_centavos, idempotency_key)
             VALUES ('H-20260101-0001', 'paid', 'Old Customer', 'old@example.com', 50000, 50000, 'old-key')"
        )->execute();
        $orderId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, name_snapshot, price_centavos, qty, line_total_centavos)'
            . ' VALUES (?, ?, ?, 50000, 1, 50000)'
        )->execute([$orderId, $productId, 'Old Bag']);
        $pdo->prepare(
            "INSERT INTO stock_audit (product_id, admin_id, old_stock, new_stock, reason)
             VALUES (?, 1, 8, 7, 'Count correction')"
        )->execute([$productId]);

        // The next boot upgrades instead of reseeding.
        Initializer::initialize($pdo, $this->migrationsDir());

        self::assertTrue(Initializer::isInitialized($pdo), 'login_attempts must exist after the upgrade.');
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'login_attempts'"
        )->fetchColumn();
        self::assertSame(1, (int) $exists);

        // Existing data is preserved exactly; no demo catalog or admin appears.
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        self::assertSame(7, (int) $pdo->query('SELECT stock FROM products WHERE sku = \'OLD-001\'')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn());
        self::assertSame('Old Admin', (string) $pdo->query('SELECT name FROM admins')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame('H-20260101-0001', (string) $pdo->query('SELECT order_number FROM orders')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn());
        self::assertSame(
            'Count correction',
            (string) $pdo->query('SELECT reason FROM stock_audit')->fetchColumn()
        );

        // A second boot is a no-op and still preserves everything.
        Initializer::initialize($pdo, $this->migrationsDir());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
    }
}
