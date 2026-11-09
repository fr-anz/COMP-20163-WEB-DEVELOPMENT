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
        $first = $this->products->list();
        $last = $this->products->list(['page' => 2]);

        self::assertSame(10, $first['total']);
        self::assertSame(2, $first['pages']);
        self::assertSame(8, $first['per_page']);
        self::assertSame('featured', $first['sort']);
        self::assertSame(
            [
                'sinag-tote', 'alon-tote', 'lupa-satchel', 'sierra-satchel',
                'tala-sling', 'ulap-sling', 'maya-clutch', 'gabi-clutch',
            ],
            array_column($first['products'], 'slug')
        );
        self::assertSame(
            ['araw-tote', 'dahon-sling'],
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

        $first = $this->products->list(['sort' => 'newest']);
        $last = $this->products->list(['sort' => 'newest', 'page' => 2]);

        self::assertSame('newest', $first['sort']);
        self::assertSame(
            [
                'sinag-tote', 'dahon-sling', 'araw-tote', 'gabi-clutch',
                'maya-clutch', 'ulap-sling', 'tala-sling', 'sierra-satchel',
            ],
            array_column($first['products'], 'slug')
        );
        self::assertSame(
            ['lupa-satchel', 'alon-tote'],
            array_column($last['products'], 'slug')
        );
    }

    public function testSearchAndCategoryCombineIncludingMaterialDescriptions(): void
    {
        $result = $this->products->list(['q' => 'canvas', 'category' => 'Tote']);
        self::assertSame(1, $result['total']);
        self::assertSame(['alon-tote'], array_column($result['products'], 'slug'));

        $result = $this->products->list(['q' => 'GEOMETRIC']);
        self::assertEqualsCanonicalizing(['araw-tote', 'sinag-tote'], array_column($result['products'], 'slug'));
        self::assertSame(['Clutch', 'Satchel', 'Sling', 'Tote'], $this->products->categories());
    }

    public function testBothPriceSortsRemainOrderedAcrossPages(): void
    {
        $ascending = [
            'maya-clutch', 'gabi-clutch', 'tala-sling', 'ulap-sling', 'alon-tote',
            'dahon-sling', 'sinag-tote', 'araw-tote', 'lupa-satchel', 'sierra-satchel',
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
        $this->database()->prepare('UPDATE products SET stock = 0 WHERE slug = ?')->execute(['gabi-clutch']);

        $default = $this->products->list();
        self::assertSame(10, $default['total']);
        self::assertFalse($default['hide_soldout']);
        self::assertContains('gabi-clutch', array_column($default['products'], 'slug'));

        $hidden = $this->products->list(['hide_soldout' => true]);
        self::assertSame(9, $hidden['total']);
        self::assertTrue($hidden['hide_soldout']);
        self::assertNotContains('gabi-clutch', array_column(array_merge(
            $hidden['products'],
            $this->products->list(['hide_soldout' => true, 'page' => 2])['products']
        ), 'slug'));
        self::assertSame(
            ['dahon-sling'],
            array_column($this->products->list(['hide_soldout' => true, 'page' => 2])['products'], 'slug')
        );

        $combined = $this->products->list(['category' => 'Clutch', 'hide_soldout' => true]);
        self::assertSame(['maya-clutch'], array_column($combined['products'], 'slug'));
    }

    public function testInactiveProductsAreHiddenButSoldOutProductsStayBrowsable(): void
    {
        $this->database()->prepare('UPDATE products SET is_active = 0 WHERE slug = ?')->execute(['maya-clutch']);
        $this->database()->prepare('UPDATE products SET stock = 0 WHERE slug = ?')->execute(['gabi-clutch']);

        $result = $this->products->list(['category' => 'Clutch']);
        self::assertSame(['gabi-clutch'], array_column($result['products'], 'slug'));
        self::assertSame(0, $this->products->getBySlug('gabi-clutch')['stock']);
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
        self::assertSame(
            ['sinag-tote', 'maya-clutch', 'araw-tote'],
            array_column($this->products->byMaterials(['%straw%', '%raffia%', '%rattan%']), 'slug')
        );
        self::assertSame(
            ['sinag-tote', 'maya-clutch'],
            array_column($this->products->byMaterials(['%straw%', '%raffia%', '%rattan%'], 2), 'slug')
        );

        $this->database()->prepare('UPDATE products SET is_active = 0 WHERE slug = ?')->execute(['maya-clutch']);
        self::assertSame(
            ['sinag-tote', 'araw-tote', 'dahon-sling'],
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
}
