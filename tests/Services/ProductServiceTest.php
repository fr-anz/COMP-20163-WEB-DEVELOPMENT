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

    public function testPaginationBrowsesEverySeedWithoutDuplicatesAndNewestUsesCreationDate(): void
    {
        $this->database()->prepare('UPDATE products SET created_at = ? WHERE slug = ?')
            ->execute(['2099-01-01 00:00:00', 'sinag-tote']);

        $first = $this->products->list();
        $last = $this->products->list(['page' => 2]);

        self::assertSame(10, $first['total']);
        self::assertSame(2, $first['pages']);
        self::assertSame(
            ['sinag-tote', 'dahon-sling', 'araw-tote', 'gabi-clutch', 'maya-clutch', 'ulap-sling'],
            array_column($first['products'], 'slug')
        );
        self::assertSame(
            ['tala-sling', 'sierra-satchel', 'lupa-satchel', 'alon-tote'],
            array_column($last['products'], 'slug')
        );
        self::assertSame($last['products'], $this->products->list(['page' => 3])['products']);
    }

    public function testSearchAndCategoryCombineIncludingMaterialDescriptions(): void
    {
        $result = $this->products->list(['q' => 'canvas', 'category' => 'Tote']);
        self::assertSame(1, $result['total']);
        self::assertSame(['alon-tote'], array_column($result['products'], 'slug'));

        $result = $this->products->list(['q' => 'GEOMETRIC']);
        self::assertSame(['araw-tote', 'sinag-tote'], array_column($result['products'], 'slug'));
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
}
