<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

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
        $result = $this->products->list(['q' => 'leather']);
        self::assertEqualsCanonicalizing(['sinag-tote', 'lupa-satchel'], array_column($result['products'], 'slug'));

        $result = $this->products->list(['q' => 'leather', 'category' => 'Satchel']);
        self::assertSame(1, $result['total']);
        self::assertSame(['lupa-satchel'], array_column($result['products'], 'slug'));

        $result = $this->products->list(['q' => 'GEOMETRIC']);
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
        $this->database()->prepare('UPDATE products SET is_active = 0 WHERE slug = ?')->execute(['maya-clutch']);
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

    public function testByMaterialsReturnsActiveProductsInIdOrderUpToTheLimit(): void
    {
        $this->addFixtureBags(1, 'Rattan-style weave');

        self::assertSame(
            ['sinag-tote', 'maya-clutch', 'fixture-1'],
            array_column($this->products->byMaterials(['%straw%', '%raffia%', '%rattan%']), 'slug')
        );
        self::assertSame(
            ['sinag-tote', 'maya-clutch'],
            array_column($this->products->byMaterials(['%straw%', '%raffia%', '%rattan%'], 2), 'slug')
        );

        $this->database()->prepare('UPDATE products SET is_active = 0 WHERE slug = ?')->execute(['maya-clutch']);
        self::assertSame(
            ['sinag-tote', 'fixture-1'],
            array_column($this->products->byMaterials(['%straw%', '%raffia%', '%rattan%']), 'slug')
        );

        self::assertSame([], $this->products->byMaterials([]));
        self::assertSame([], array_column($this->products->byMaterials(['%no-such-material%']), 'slug'));
    }

    public function testInspectionConfigRemainsAvailableWhenSoldOutButNotInactive(): void
    {
        $config = $this->products->get3dConfig('maya-clutch');
        self::assertIsArray($config);
        $this->database()->prepare('UPDATE products SET stock = 0 WHERE slug = ?')->execute(['maya-clutch']);
        self::assertSame($config, $this->products->get3dConfig('maya-clutch'));
        $this->database()->prepare('UPDATE products SET is_active = 0 WHERE slug = ?')->execute(['maya-clutch']);
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

    /** Extra active Tote rows priced ₱2,010…, so listings span more than one page. */
    private function addFixtureBags(int $count, string $materials = ''): void
    {
        $insert = $this->database()->prepare(
            'INSERT INTO products (sku, slug, name, category, materials, price_centavos, stock)'
            . " VALUES (?, ?, ?, 'Tote', ?, ?, 5)"
        );
        for ($n = 1; $n <= $count; $n++) {
            $insert->execute(["FIX-$n", "fixture-$n", "Fixture $n", $materials, 200000 + $n * 1000]);
        }
    }
}
