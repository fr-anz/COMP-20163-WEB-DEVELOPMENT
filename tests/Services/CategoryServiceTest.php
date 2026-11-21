<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\AdminActionException;
use Habi\Services\CategoryService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;

/**
 * Category management (PRD R41): create and rename (product category labels
 * follow), deactivate instead of delete, and the rule that a category with
 * live products (active or pending review) cannot be deactivated.
 */
final class CategoryServiceTest extends IsolatedDatabaseTestCase
{
    private CategoryService $categories;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->categories = new CategoryService($this->database());
    }

    public function testCreateAddsAnActiveCategoryWithASlug(): void
    {
        $result = $this->categories->validate(['name' => '  Market   Basket '], null);
        self::assertSame([], $result['errors']);

        $id = $this->categories->create($result['values']);

        $row = $this->categories->find($id);
        self::assertSame(['Market Basket', 'market-basket', true], [$row['name'], $row['slug'], $row['is_active']]);
        self::assertSame('Market Basket', $this->categories->activeOptions()[$id]);
    }

    public function testNamesMustBeUniquePlainAndShort(): void
    {
        self::assertArrayHasKey('name', $this->categories->validate(['name' => 'tote'], null)['errors']);
        self::assertArrayHasKey('name', $this->categories->validate(['name' => '<b>Bag</b>'], null)['errors']);
        self::assertArrayHasKey('name', $this->categories->validate(['name' => ''], null)['errors']);
        self::assertArrayHasKey('name', $this->categories->validate(['name' => str_repeat('a', 41)], null)['errors']);
        $tote = $this->idOf('tote');
        self::assertSame([], $this->categories->validate(['name' => 'Tote'], $tote)['errors'], 'Own name is fine.');
    }

    public function testRenameUpdatesProductCategoryLabels(): void
    {
        $tote = $this->idOf('tote');
        $product = $this->insertProduct('HBI-CAT-1', 'Cat Tote', 100000, 3);

        $this->categories->rename($tote, $this->categories->validate(['name' => 'Tote Bag'], $tote)['values']);

        self::assertSame('Tote Bag', $this->categories->find($tote)['name']);
        self::assertSame('tote', $this->categories->find($tote)['slug'], 'The filter slug stays stable.');
        $label = $this->database()->prepare('SELECT category FROM products WHERE id = ?');
        $label->execute([$product]);
        self::assertSame('Tote Bag', $label->fetchColumn());
    }

    public function testACategoryWithLiveProductsCannotBeDeactivated(): void
    {
        $tote = $this->idOf('tote');
        $this->database()->exec("UPDATE products SET listing_status = 'archived' WHERE category_id = " . $tote);
        $pending = $this->insertProduct('HBI-CAT-2', 'Pending Tote', 100000, 3, 'pending_review');

        try {
            $this->categories->deactivate($tote);
            self::fail('Expected the live-products rule to refuse.');
        } catch (AdminActionException $refused) {
            self::assertStringContainsString('1', $refused->getMessage());
        }
        self::assertTrue($this->categories->find($tote)['is_active']);

        $this->database()->exec("UPDATE products SET listing_status = 'deactivated' WHERE id = " . $pending);
        $this->categories->deactivate($tote);
        self::assertFalse($this->categories->find($tote)['is_active']);
        self::assertArrayNotHasKey($tote, $this->categories->activeOptions());
        self::assertArrayHasKey($tote, $this->categories->activeOptions($tote), 'A product keeps its own category.');

        $this->categories->reactivate($tote);
        self::assertTrue($this->categories->find($tote)['is_active']);
    }

    public function testListCountsLiveProducts(): void
    {
        $this->insertProduct('HBI-CAT-3', 'Count Tote', 100000, 3);
        $tote = array_values(array_filter(
            $this->categories->list(),
            static fn (array $c): bool => $c['slug'] === 'tote'
        ))[0];

        self::assertGreaterThanOrEqual(1, $tote['live']);
        self::assertGreaterThanOrEqual($tote['live'], $tote['products']);
    }

    private function idOf(string $slug): int
    {
        $statement = $this->database()->prepare('SELECT id FROM categories WHERE slug = ?');
        $statement->execute([$slug]);

        return (int) $statement->fetchColumn();
    }
}
