<?php

declare(strict_types=1);

namespace Habi\Tests\Bootstrap;

use Habi\Bootstrap\Initializer;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use PDO;

final class FirstBootTest extends IsolatedDatabaseTestCase
{
    public function testFirstBootCreatesSddSchemaAndSeedsCatalogExactlyOnce(): void
    {
        $pdo = $this->database();

        Initializer::initialize($pdo, $this->migrationsDir());

        self::assertSame(4, $this->scalar($pdo, 'SELECT COUNT(*) FROM products'));
        self::assertSame(
            4,
            (int) $pdo->query('SELECT COUNT(DISTINCT category) FROM products')->fetchColumn()
        );

        self::assertSame(1, $this->scalar($pdo, 'SELECT COUNT(*) FROM admins'));
        self::assertSame(
            4,
            $this->scalar($pdo, 'SELECT COUNT(*) FROM product_images WHERE is_primary = 1')
        );

        // A second initialize on the same database must not add anything.
        Initializer::initialize($pdo, $this->migrationsDir());
        self::assertSame(4, $this->scalar($pdo, 'SELECT COUNT(*) FROM products'));
        self::assertSame(1, $this->scalar($pdo, 'SELECT COUNT(*) FROM admins'));
    }

    public function testSeededProductsHaveValidMoneyStockAndThreeConfig(): void
    {
        $pdo = $this->database();
        Initializer::initialize($pdo, $this->migrationsDir());

        $products = $pdo->query(
            'SELECT slug, category, price_centavos, stock, low_stock_threshold,
                    dimensions, three_config, is_active
             FROM products ORDER BY slug'
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(4, $products);

        foreach ($products as $product) {
            self::assertSame(1, (int) $product['is_active'], $product['slug']);
            self::assertGreaterThan(0, (int) $product['price_centavos'], $product['slug']);
            self::assertGreaterThanOrEqual(0, (int) $product['stock'], $product['slug']);
            self::assertGreaterThanOrEqual(0, (int) $product['low_stock_threshold'], $product['slug']);

            $dimensions = json_decode((string) $product['dimensions'], true);
            self::assertIsArray($dimensions, $product['slug']);
            foreach (['width', 'height', 'depth'] as $key) {
                self::assertArrayHasKey($key, $dimensions, $product['slug']);
                self::assertGreaterThan(0, $dimensions[$key], $product['slug']);
            }

            $config = json_decode((string) $product['three_config'], true);
            self::assertIsArray($config, $product['slug']);
            self::assertSame(strtolower($product['category']), $config['shape'] ?? null, $product['slug']);
            self::assertSame($dimensions, $config['dimensions'] ?? null, $product['slug']);
            foreach (['body', 'trim', 'lining'] as $key) {
                self::assertMatchesRegularExpression(
                    '/^#[0-9a-fA-F]{6}$/',
                    (string) ($config['colors'][$key] ?? ''),
                    $product['slug']
                );
            }
            self::assertContains($config['material'] ?? '', ['canvas', 'leather', 'rattan'], $product['slug']);
            self::assertIsArray($config['pockets'] ?? null, $product['slug']);
        }
    }

    public function testSeededAdminMatchesEnvironmentCredentials(): void
    {
        $pdo = $this->database();
        Initializer::initialize($pdo, $this->migrationsDir());

        $admin = $pdo->query('SELECT email, password_hash, name FROM admins')->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($admin);
        self::assertSame('admin@example.test', $admin['email']);
        self::assertSame('Demo administrator', $admin['name']);
        self::assertTrue(
            password_verify('habi-demo-password', (string) $admin['password_hash']),
            'Seeded admin hash must verify against the configured password.'
        );
        self::assertSame(
            1,
            (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn(),
            'Foreign keys must be enabled on the connection.'
        );
    }

    private function scalar(PDO $pdo, string $sql): int
    {
        return (int) $pdo->query($sql)->fetchColumn();
    }
}
