<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\HeritageUpgrade;
use Habi\Bootstrap\Initializer;
use Habi\Services\AdminActionException;
use Habi\Services\AdminProductService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use Habi\Tests\TestSupport\WeaverFixtures;

/**
 * Product form v2 (PRD R20, R29, R40, R41): a real active weaver is
 * required (never the placeholder for new products), the category comes
 * from active categories, heritage fields are plain text, the 3D config is
 * optional (NULL when off), reassigned products leave the dashboard's
 * "without a weaver" list, and pending listings are approved or deactivated.
 */
final class AdminProductV2Test extends IsolatedDatabaseTestCase
{
    use WeaverFixtures;

    private AdminProductService $products;
    private int $adminId;
    private int $weaver;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->products = new AdminProductService($this->database(), habi_repo_root() . '/public');
        $this->adminId = (int) $this->database()->query('SELECT id FROM admins LIMIT 1')->fetchColumn();
        $this->weaver = $this->insertWeaver('ana');
    }

    public function testANewProductNeedsAnActiveRealWeaver(): void
    {
        $placeholder = HeritageUpgrade::placeholderWeaverId($this->database());
        $inactive = $this->insertWeaver('gone', 'x', false);
        foreach (['', 'abc', (string) $placeholder, (string) $inactive, '999999'] as $weaverId) {
            $result = $this->products->validate($this->input(['weaver_id' => $weaverId]), null);
            self::assertArrayHasKey('weaver_id', $result['errors'], 'weaver ' . $weaverId);
        }
        self::assertArrayNotHasKey((string) $placeholder, $this->products->weaverOptions(null));

        $result = $this->products->validate($this->input(), null);
        self::assertSame([], $result['errors']);
        $id = $this->products->create($result['values'], '/uploads/a.png', $this->adminId);
        self::assertSame($this->weaver, (int) $this->row($id)['weaver_id']);
    }

    public function testHeritageFieldsAreSavedAsPlainText(): void
    {
        $result = $this->products->validate($this->input([
            'fiber' => 'tikog', 'community' => ' Basey ', 'region' => 'Eastern Visayas (Region VIII)',
            'technique' => 'Banig plaiting', 'story' => "One.\r\n\r\n\r\nTwo.",
        ]), null);
        self::assertSame([], $result['errors']);
        $id = $this->products->create($result['values'], '/uploads/a.png', $this->adminId);

        $row = $this->row($id);
        self::assertSame(['tikog', 'Basey', 'Eastern Visayas (Region VIII)', 'Banig plaiting', "One.\n\nTwo."], [
            $row['fiber'], $row['community'], $row['region'], $row['technique'], $row['story'],
        ]);

        $bad = $this->products->validate($this->input(['fiber' => 'silk', 'story' => '<p>Hi</p>']), null);
        self::assertArrayHasKey('fiber', $bad['errors']);
        self::assertArrayHasKey('story', $bad['errors']);
    }

    public function testTheCategoryMustBeActiveUnlessTheProductAlreadyUsesIt(): void
    {
        $id = $this->products->create(
            $this->products->validate($this->input(), null)['values'],
            '/uploads/a.png',
            $this->adminId
        );
        $this->database()->prepare(
            "INSERT INTO categories (slug, name, sort_order, is_active) VALUES ('old', 'Old', 9, 0)"
        )->execute();
        $old = (int) $this->database()->lastInsertId();
        $refused = $this->products->validate($this->input(['category_id' => (string) $old]), null);
        self::assertArrayHasKey('category_id', $refused['errors']);

        $this->database()->exec('UPDATE products SET category_id = ' . $old . ", category = 'Old' WHERE id = " . $id);
        $edit = $this->products->validate($this->input(['category_id' => (string) $old]), $id);
        self::assertArrayNotHasKey('category_id', $edit['errors']);
        $this->products->update($id, $edit['values'], null);
        self::assertSame('Old', $this->row($id)['category']);
    }

    public function testThe3dConfigIsOptional(): void
    {
        $without = $this->products->validate(
            $this->input(['has_3d' => '', 'shape' => 'nonsense', 'color_body' => 'red']),
            null
        );
        self::assertSame([], $without['errors'], '3D fields are ignored when the model is off.');
        $id = $this->products->create($without['values'], '/uploads/a.png', $this->adminId);
        self::assertNull($this->row($id)['three_config']);
        self::assertFalse($this->products->formValues($this->products->find($id))['has_3d']);

        $with = $this->products->validate($this->input(['has_3d' => '1']), $id);
        self::assertSame([], $with['errors']);
        $this->products->update($id, $with['values'], null);
        $config = json_decode((string) $this->row($id)['three_config'], true);
        self::assertSame('tote', $config['shape']);

        $off = $this->products->validate($this->input(['has_3d' => '']), $id);
        $this->products->update($id, $off['values'], null);
        self::assertNull($this->row($id)['three_config']);
    }

    public function testReassigningOffThePlaceholderRemovesItFromTheDashboardList(): void
    {
        $id = $this->products->create(
            $this->products->validate($this->input(), null)['values'],
            '/uploads/a.png',
            $this->adminId
        );
        $placeholder = HeritageUpgrade::placeholderWeaverId($this->database());
        $this->database()->exec('UPDATE products SET weaver_id = ' . $placeholder . ' WHERE id = ' . $id);
        self::assertContains($id, array_column($this->products->metrics()['unassigned'], 'id'));

        $kept = $this->products->validate($this->input(['weaver_id' => (string) $placeholder]), $id);
        self::assertArrayNotHasKey('weaver_id', $kept['errors'], 'An existing product may stay unassigned.');
        $this->products->update($id, $this->products->validate($this->input(), $id)['values'], null);

        self::assertNotContains($id, array_column($this->products->metrics()['unassigned'], 'id'));
        self::assertSame($this->weaver, (int) $this->row($id)['weaver_id']);
    }

    public function testPendingListingsAreApprovedOrDeactivated(): void
    {
        $first = $this->insertWeaverProduct($this->weaver, 'W-PEND-1', 2, 'pending_review');
        $second = $this->insertWeaverProduct($this->weaver, 'W-PEND-2', 2, 'pending_review');
        self::assertSame(
            ['W-PEND-1', 'W-PEND-2'],
            array_column($this->products->list(['stock' => 'pending'])['products'], 'sku')
        );

        $this->products->approve($first);
        $this->products->deactivateListing($second);

        self::assertSame('active', $this->row($first)['listing_status']);
        self::assertSame('deactivated', $this->row($second)['listing_status']);
        self::assertSame([], $this->products->list(['stock' => 'pending'])['products']);
        $archived = $this->products->list(['stock' => 'archived'])['products'];
        self::assertContains('W-PEND-2', array_column($archived, 'sku'));
        foreach ([fn () => $this->products->approve($first), fn () => $this->products->approve($second)] as $attempt) {
            try {
                $attempt();
                self::fail('Only pending listings can be approved.');
            } catch (AdminActionException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->products->setActive($second, true);
        self::assertSame('active', $this->row($second)['listing_status']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Heritage Tote', 'sku' => 'HBI-V2-001', 'weaver_id' => (string) $this->weaver,
            'category_id' => (string) $this->database()->query("SELECT id FROM categories WHERE slug = 'tote'")
                ->fetchColumn(),
            'description' => 'A tote.', 'materials' => 'Tikog', 'place_of_make' => 'Basey, Samar',
            'fiber' => 'tikog', 'community' => 'Basey', 'region' => 'Eastern Visayas (Region VIII)',
            'technique' => 'Plaiting', 'story' => 'Woven by hand.',
            'price' => '1650', 'stock' => '5', 'low_stock_threshold' => '3',
            'width' => '30', 'height' => '35', 'depth' => '12', 'has_3d' => '1',
            'shape' => 'tote', 'handle_style' => 'double_handles', 'material' => 'tikog', 'lining' => 'cotton',
            'color_body' => '#C9A47A', 'color_trim' => '#6D5A4B', 'color_lining' => '#F3E7D3',
            'pockets' => ['interior_zip'], 'demo_image' => '',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $statement = $this->database()->prepare('SELECT * FROM products WHERE id = ?');
        $statement->execute([$id]);

        return $statement->fetch();
    }
}
