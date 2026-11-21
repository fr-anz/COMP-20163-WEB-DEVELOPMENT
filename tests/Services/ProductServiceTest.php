<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\HeritageUpgrade;
use Habi\Bootstrap\Initializer;
use Habi\Services\ProductService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;

final class ProductServiceTest extends IsolatedDatabaseTestCase
{
    private ProductService $products;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->products = new ProductService($this->database());
    }

    public function testFeaturedIsTheDefaultSortInDeterministicIdOrderWithEightPerPage(): void
    {
        $this->addFixtureBags(6);

        $first = $this->products->list();
        $last = $this->products->list(['page' => 2]);

        self::assertSame(10, $first['total']);
        self::assertSame(2, $first['pages']);
        self::assertSame(8, $first['per_page']);
        self::assertSame('featured', $first['sort']);
        self::assertSame(
            [
                'sinag-tote', 'lupa-satchel', 'tala-sling', 'maya-clutch',
                'fixture-1', 'fixture-2', 'fixture-3', 'fixture-4',
            ],
            array_column($first['products'], 'slug')
        );
        self::assertSame(
            ['fixture-5', 'fixture-6'],
            array_column($last['products'], 'slug')
        );
        self::assertSame(
            $last['products'],
            $this->products->list(['sort' => 'featured', 'page' => 2])['products']
        );
    }

    public function testNewestSortUsesCreationDate(): void
    {
        $this->database()->prepare('UPDATE products SET created_at = ? WHERE slug = ?')
            ->execute(['2099-01-01 00:00:00', 'sinag-tote']);

        $result = $this->products->list(['sort' => 'newest']);

        self::assertSame('newest', $result['sort']);
        self::assertSame(
            ['sinag-tote', 'maya-clutch', 'tala-sling', 'lupa-satchel'],
            array_column($result['products'], 'slug')
        );
    }

    public function testSearchAndCategoryCombineIncludingMaterialDescriptions(): void
    {
        $result = $this->products->list(['q' => 'lining']);
        self::assertEqualsCanonicalizing(
            ['sinag-tote', 'lupa-satchel', 'tala-sling'],
            array_column($result['products'], 'slug')
        );

        $result = $this->products->list(['q' => 'lining', 'category' => 'Satchel']);
        self::assertSame(1, $result['total']);
        self::assertSame(['lupa-satchel'], array_column($result['products'], 'slug'));

        $result = $this->products->list(['q' => 'MARKET']);
        self::assertSame(['sinag-tote'], array_column($result['products'], 'slug'));
        self::assertSame(['Clutch', 'Satchel', 'Sling', 'Tote'], $this->products->categories());
    }

    public function testBothPriceSortsRemainOrderedAcrossPages(): void
    {
        $this->addFixtureBags(6);
        $ascending = [
            'maya-clutch', 'tala-sling', 'sinag-tote', 'fixture-1', 'fixture-2',
            'fixture-3', 'fixture-4', 'fixture-5', 'fixture-6', 'lupa-satchel',
        ];

        foreach (['price_asc' => $ascending, 'price_desc' => array_reverse($ascending)] as $sort => $expected) {
            $first = $this->products->list(['sort' => $sort]);
            $second = $this->products->list(['sort' => $sort, 'page' => 2]);
            self::assertSame(
                $expected,
                array_column(array_merge($first['products'], $second['products']), 'slug')
            );
        }
    }

    public function testHideSoldOutFlagExcludesSoldOutStockFromListingAndCount(): void
    {
        $this->addFixtureBags(6);
        $this->database()->prepare('UPDATE products SET stock = 0 WHERE slug = ?')->execute(['maya-clutch']);

        $default = $this->products->list();
        self::assertSame(10, $default['total']);
        self::assertFalse($default['hide_soldout']);
        self::assertContains('maya-clutch', array_column($default['products'], 'slug'));

        $hidden = $this->products->list(['hide_soldout' => true]);
        self::assertSame(9, $hidden['total']);
        self::assertTrue($hidden['hide_soldout']);
        self::assertNotContains('maya-clutch', array_column(array_merge(
            $hidden['products'],
            $this->products->list(['hide_soldout' => true, 'page' => 2])['products']
        ), 'slug'));
        self::assertSame(
            ['fixture-6'],
            array_column($this->products->list(['hide_soldout' => true, 'page' => 2])['products'], 'slug')
        );

        $combined = $this->products->list(['category' => 'Clutch', 'hide_soldout' => true]);
        self::assertSame([], array_column($combined['products'], 'slug'));
        $combined = $this->products->list(['category' => 'Clutch']);
        self::assertSame(['maya-clutch'], array_column($combined['products'], 'slug'));
    }

    public function testInactiveProductsAreHiddenButSoldOutProductsStayBrowsable(): void
    {
        $this->database()
            ->prepare("UPDATE products SET listing_status = 'archived' WHERE slug = ?")
            ->execute(['maya-clutch']);
        $this->database()->prepare('UPDATE products SET stock = 0 WHERE slug = ?')->execute(['tala-sling']);

        $result = $this->products->list();
        self::assertSame(['sinag-tote', 'lupa-satchel', 'tala-sling'], array_column($result['products'], 'slug'));
        self::assertSame([], $this->products->list(['category' => 'Clutch'])['products']);
        self::assertSame(0, $this->products->getBySlug('tala-sling')['stock']);
        self::assertNull($this->products->getBySlug('maya-clutch'));
        self::assertNull($this->products->getBySlug('missing-bag'));
    }

    public function testNoResultsReturnAnEmptyPageAndSearchTreatsWildcardsAsText(): void
    {
        foreach (['no-such-bag', '%', '_'] as $query) {
            $result = $this->products->list(['q' => $query, 'page' => 2]);
            self::assertSame([], $result['products']);
            self::assertSame(0, $result['total']);
            self::assertSame(1, $result['page']);
            self::assertSame(1, $result['pages']);
        }
    }

    public function testByFiberReturnsActiveProductsOfThatFiberInIdOrderUpToTheLimit(): void
    {
        $this->addFixtureBags(2);
        $this->setFiber(
            ['sinag-tote' => 'buri', 'fixture-1' => 'buri', 'fixture-2' => 'buri', 'maya-clutch' => 'pandan']
        );

        self::assertSame(
            ['sinag-tote', 'fixture-1', 'fixture-2'],
            array_column($this->products->byFiber('buri'), 'slug')
        );
        self::assertSame(['sinag-tote'], array_column($this->products->byFiber('buri', 1), 'slug'));

        $this->database()
            ->prepare("UPDATE products SET listing_status = 'pending_review' WHERE slug = ?")
            ->execute(['sinag-tote']);
        self::assertSame(['fixture-1'], array_column($this->products->byFiber('buri', 1), 'slug'));
        self::assertSame(['maya-clutch'], array_column($this->products->byFiber('pandan'), 'slug'));
        self::assertSame([], $this->products->byFiber('not-a-fiber'));
    }

    public function testFiberAndRegionFiltersAreAllowlistedAndCombineWithTheOtherFilters(): void
    {
        $this->addFixtureBags(9);
        $this->setFiber(
            ['sinag-tote' => 'buri', 'lupa-satchel' => 'tikog', 'tala-sling' => 'abaca', 'maya-clutch' => 'pandan']
        );
        $this->database()
            ->exec("UPDATE products SET fiber = 'buri', region = 'Bicol Region' WHERE slug LIKE 'fixture-%'");
        $region = $this->database()->prepare('UPDATE products SET region = ? WHERE slug = ?');
        $region->execute(['Bicol Region', 'sinag-tote']);
        $region->execute(['Central Visayas', 'maya-clutch']);
        $region->execute(['', 'lupa-satchel']);
        $this->database()->prepare('UPDATE products SET stock = 0 WHERE slug = ?')->execute(['fixture-9']);

        $buri = $this->products->list(['fiber' => 'buri']);
        self::assertSame('buri', $buri['fiber']);
        self::assertSame(10, $buri['total']);
        self::assertSame(2, $buri['pages']);

        $combined = $this->products->list([
            'fiber' => 'buri', 'region' => 'Bicol Region', 'hide_soldout' => true, 'sort' => 'price_desc', 'page' => 2,
        ]);
        self::assertSame(9, $combined['total']);
        self::assertSame(2, $combined['page']);
        self::assertSame('Bicol Region', $combined['region']);
        self::assertSame(['sinag-tote'], array_column($combined['products'], 'slug'));

        $visayas = $this->products->list(['region' => 'Central Visayas']);
        self::assertSame(['maya-clutch'], array_column($visayas['products'], 'slug'));
        self::assertSame([], $this->products->list(['region' => 'Central Visayas', 'category' => 'Tote'])['products']);
        $abaca = $this->products->list(['fiber' => 'abaca', 'q' => 'sling']);
        self::assertSame(['tala-sling'], array_column($abaca['products'], 'slug'));

        // Unknown or malformed values are ignored, never passed to SQL as filters.
        foreach (['leather', "buri' OR 1=1 --", ['buri']] as $bad) {
            $result = $this->products->list(['fiber' => $bad, 'region' => $bad]);
            self::assertSame('', $result['fiber']);
            self::assertSame('', $result['region']);
            self::assertSame(13, $result['total']);
        }

        // Options list only what active products carry; empty regions are skipped.
        $this->database()
            ->prepare("UPDATE products SET listing_status = 'archived' WHERE slug = ?")
            ->execute(['tala-sling']);
        self::assertSame(['buri' => 'Buri', 'pandan' => 'Pandan', 'tikog' => 'Tikog'], $this->products->fibers());
        self::assertSame(['Bicol Region', 'Central Visayas'], $this->products->regions());
        self::assertSame('', $this->products->list(['fiber' => 'abaca'])['fiber']);
    }

    public function testDetailCarriesTheStoryAndOnlyAnActivePublicWeaver(): void
    {
        $this->database()->prepare(
            "UPDATE products SET fiber = 'abaca', community = 'Virac', region = 'Bicol Region',
                 technique = 'Loom weaving', story = ? WHERE slug = 'tala-sling'"
        )->execute(["First paragraph.\n\nSecond paragraph."]);

        $product = $this->products->getBySlug('tala-sling');
        self::assertSame('abaca', $product['fiber']);
        self::assertSame('Abaca', $product['fiber_label']);
        self::assertSame('Virac', $product['community']);
        self::assertSame('Bicol Region', $product['region']);
        self::assertSame('Loom weaving', $product['technique']);
        self::assertSame("First paragraph.\n\nSecond paragraph.", $product['story']);
        self::assertTrue($product['has_3d']);
        self::assertIsArray($product['weaver']);
        $weaverSlug = $product['weaver']['slug'];
        self::assertNotSame(HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG, $weaverSlug);
        self::assertNotSame('', $product['weaver']['display_name']);

        // A deactivated weaver's bag leaves the storefront entirely.
        $this->database()->prepare('UPDATE weavers SET is_active = 0 WHERE slug = ?')->execute([$weaverSlug]);
        self::assertNull($this->products->getBySlug('tala-sling'));
        $this->database()->prepare('UPDATE weavers SET is_active = 1 WHERE slug = ?')->execute([$weaverSlug]);

        $placeholder = HeritageUpgrade::placeholderWeaverId($this->database());
        $this->database()->prepare('UPDATE weavers SET is_active = 1 WHERE id = ?')->execute([$placeholder]);
        $this->database()->prepare('UPDATE products SET weaver_id = ? WHERE slug = ?')
            ->execute([$placeholder, 'tala-sling']);
        self::assertNull($this->products->getBySlug('tala-sling')['weaver']);
    }

    public function testProductsWithoutAThreeDConfigHaveNoConfigAndNoViewerFlag(): void
    {
        foreach (["NULL", "''", "'   '"] as $value) {
            $this->database()->exec("UPDATE products SET three_config = $value WHERE slug = 'maya-clutch'");
            self::assertNull($this->products->get3dConfig('maya-clutch'));
            self::assertFalse($this->products->getBySlug('maya-clutch')['has_3d']);
        }
        self::assertTrue($this->products->getBySlug('sinag-tote')['has_3d']);
        self::assertIsArray($this->products->get3dConfig('sinag-tote'));
    }

    public function testByWeaverListsOnlyThatWeaversActiveProducts(): void
    {
        $weaverId = (int) $this->database()->query("SELECT weaver_id FROM products WHERE slug = 'sinag-tote'")
            ->fetchColumn();
        $other = (int) $this->database()->query("SELECT weaver_id FROM products WHERE slug = 'maya-clutch'")
            ->fetchColumn();
        self::assertNotSame($weaverId, $other);
        $this->addFixtureBags(2);
        $this->database()->prepare("UPDATE products SET weaver_id = ? WHERE slug LIKE 'fixture-%'")
            ->execute([$weaverId]);
        $status = $this->database()->prepare('UPDATE products SET listing_status = ? WHERE slug = ?');
        $status->execute(['pending_review', 'fixture-1']);

        self::assertSame(['sinag-tote', 'fixture-2'], array_column($this->products->byWeaver($weaverId), 'slug'));
        $status->execute(['deactivated', 'fixture-2']);
        self::assertSame(['sinag-tote'], array_column($this->products->byWeaver($weaverId), 'slug'));
    }

    public function testInspectionConfigRemainsAvailableWhenSoldOutButNotInactive(): void
    {
        $config = $this->products->get3dConfig('maya-clutch');
        self::assertIsArray($config);
        $this->database()->prepare('UPDATE products SET stock = 0 WHERE slug = ?')->execute(['maya-clutch']);
        self::assertSame($config, $this->products->get3dConfig('maya-clutch'));
        $this->database()
            ->prepare("UPDATE products SET listing_status = 'archived' WHERE slug = ?")
            ->execute(['maya-clutch']);
        self::assertNull($this->products->get3dConfig('maya-clutch'));
        self::assertNull($this->products->get3dConfig('missing-bag'));
    }

    public function testDetailGalleryUsesPrimaryThenSortOrderWithoutMixingProducts(): void
    {
        $product = $this->products->getBySlug('sinag-tote');
        $insert = $this->database()->prepare(
            'INSERT INTO product_images (product_id, path, sort_order, is_primary) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$product['id'], '/side.svg', 2, 0]);
        $insert->execute([$product['id'], '/back.svg', 1, 0]);

        self::assertSame(
            [
                ['path' => $product['image_path']],
                ['path' => '/back.svg'],
                ['path' => '/side.svg'],
            ],
            $this->products->getBySlug('sinag-tote')['images']
        );
    }

    /** @param array<string, string> $fibers slug => fiber */
    private function setFiber(array $fibers): void
    {
        $update = $this->database()->prepare('UPDATE products SET fiber = ? WHERE slug = ?');
        foreach ($fibers as $slug => $fiber) {
            $update->execute([$fiber, $slug]);
        }
    }

    /** Extra active Tote rows priced ₱2,010…, so listings span more than one page. */
    private function addFixtureBags(int $count, string $materials = ''): void
    {
        $insert = $this->database()->prepare(
            'INSERT INTO products (sku, slug, name, weaver_id, category_id, category, materials, price_centavos, stock)'
            . " VALUES (?, ?, ?, (SELECT MIN(id) FROM weavers), (SELECT id FROM categories WHERE name = 'Tote'),"
            . " 'Tote', ?, ?, 5)"
        );
        for ($n = 1; $n <= $count; $n++) {
            $insert->execute(["FIX-$n", "fixture-$n", "Fixture $n", $materials, 200000 + $n * 1000]);
        }
    }
}
