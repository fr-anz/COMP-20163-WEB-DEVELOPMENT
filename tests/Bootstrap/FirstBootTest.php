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

        // N7: 4 products in 4 categories, >= 4 weavers, >= 3 published articles.
        self::assertSame(4, $this->scalar($pdo, 'SELECT COUNT(*) FROM categories WHERE is_active = 1'));
        self::assertGreaterThanOrEqual(4, $this->scalar($pdo, 'SELECT COUNT(*) FROM weavers WHERE is_active = 1'));
        self::assertGreaterThanOrEqual(
            4,
            $this->scalar($pdo, 'SELECT COUNT(DISTINCT community) FROM weavers WHERE is_active = 1')
        );
        self::assertGreaterThanOrEqual(
            3,
            $this->scalar($pdo, "SELECT COUNT(*) FROM journal_articles WHERE status = 'published'")
        );
        self::assertSame(0, $this->scalar($pdo, "SELECT COUNT(*) FROM weavers WHERE slug = 'unassigned'"));
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());

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
                    dimensions, three_config, listing_status, fiber, weaver_id, category_id,
                    (SELECT c.name FROM categories c WHERE c.id = products.category_id) AS category_name,
                    (SELECT w.is_active FROM weavers w WHERE w.id = products.weaver_id) AS weaver_active
             FROM products ORDER BY slug'
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(4, $products);
        self::assertCount(4, array_unique(array_column($products, 'fiber')), 'One fiber per bag.');
        self::assertCount(4, array_unique(array_column($products, 'weaver_id')), 'One sample weaver per bag.');

        foreach ($products as $product) {
            self::assertSame('active', $product['listing_status'], $product['slug']);
            self::assertSame($product['category'], $product['category_name'], $product['slug']);
            self::assertSame(1, (int) $product['weaver_active'], $product['slug']);
            self::assertContains($product['fiber'], ['buri', 'pandan', 'tikog', 'abaca'], $product['slug']);
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
            self::assertSame($product['fiber'], $config['material'] ?? null, $product['slug']);
            self::assertIsArray($config['pockets'] ?? null, $product['slug']);
        }
    }

    public function testSeededContentIsSourcedPlainTextWithoutDraftWording(): void
    {
        $pdo = $this->database();
        Initializer::initialize($pdo, $this->migrationsDir());

        $articles = $pdo->query(
            "SELECT slug, title, excerpt, body, sources FROM journal_articles WHERE status = 'published'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $weavers = $pdo->query(
            'SELECT slug, display_name, community, region, fibers, techniques, story FROM weavers WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_ASSOC);
        $products = $pdo->query(
            'SELECT slug, name, description, materials, place_of_make, community, region, technique, story
             FROM products'
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertGreaterThanOrEqual(3, count($articles));
        // Shared format: one source per line, "Author. Title. Publisher, Year. URL".
        $sourceLine = '/^\S.*\. \S.*\. \S.*, (?:\d{4}\.|n\.d\.) https?:\/\/\S+$/u';
        foreach ($articles as $article) {
            $lines = array_values(array_filter(
                array_map('trim', explode("\n", (string) $article['sources'])),
                static fn (string $line): bool => $line !== ''
            ));
            self::assertGreaterThanOrEqual(2, count($lines), $article['slug']);
            foreach ($lines as $line) {
                self::assertMatchesRegularExpression($sourceLine, $line, $article['slug']);
            }
        }

        $longText = array_merge(
            array_map(static fn (array $a): array => [$a['slug'], $a['body']], $articles),
            array_map(static fn (array $a): array => [$a['slug'], $a['excerpt']], $articles),
            array_map(static fn (array $w): array => [$w['slug'], $w['story']], $weavers),
            array_map(static fn (array $p): array => [$p['slug'], $p['story']], $products)
        );
        foreach ($longText as [$slug, $text]) {
            $text = (string) $text;
            self::assertNotSame('', trim($text), $slug);
            self::assertDoesNotMatchRegularExpression('/[<>]/', $text, $slug . ': no HTML');
            // Paragraphs are separated by exactly one blank line; no hard-wrapped lines.
            self::assertDoesNotMatchRegularExpression('/(?<!\n)\n(?!\n)|\n{3,}/', $text, $slug);
        }

        self::assertCount(
            count($weavers),
            array_unique(array_column($weavers, 'community')),
            'Weaver communities are distinct.'
        );

        $customerFacing = [];
        foreach ([$articles, $weavers, $products] as $rows) {
            foreach ($rows as $row) {
                foreach ($row as $field => $value) {
                    $customerFacing[] = [$row['slug'] . '.' . $field, (string) $value];
                }
            }
        }
        foreach ($customerFacing as [$label, $value]) {
            self::assertDoesNotMatchRegularExpression(
                '/placeholder|sample|demo|fictional|simulat/i',
                $value,
                $label
            );
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
