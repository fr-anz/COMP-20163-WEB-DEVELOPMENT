<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\AdminActionException;
use Habi\Services\ProductService;
use Habi\Services\WeaverProductService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use Habi\Tests\TestSupport\WeaverFixtures;

/**
 * Weaver listings (R36, R37, N10): new listings start pending_review and
 * stay off the storefront, weavers never set 3D configs, SKUs or owners,
 * every read and write is filtered by the signed-in weaver in SQL, and an
 * admin-deactivated listing is read-only for its weaver.
 */
final class WeaverProductServiceTest extends IsolatedDatabaseTestCase
{
    use WeaverFixtures;

    private WeaverProductService $products;
    private int $lita;
    private int $rosa;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->products = new WeaverProductService($this->database());
        $this->lita = $this->insertWeaver('lita');
        $this->rosa = $this->insertWeaver('rosa');
    }

    public function testNewListingStartsPendingReviewHiddenFromTheStorefrontWithAWeaverAudit(): void
    {
        $result = $this->products->validate($this->validInput() + [
            'listing_status' => 'active',
            'weaver_id' => (string) $this->rosa,
            'sku' => 'HBI-TOT-001',
            'three_config' => '{"shape":"sling"}',
        ], true);
        self::assertSame([], $result['errors']);

        $id = $this->products->create($this->lita, $result['values'], '/assets/img/products/sinag-tote.svg');

        $row = $this->database()->query('SELECT * FROM products WHERE id = ' . $id)->fetch();
        self::assertSame('pending_review', $row['listing_status']);
        self::assertSame($this->lita, (int) $row['weaver_id']);
        self::assertNull($row['three_config']);
        self::assertNotSame('HBI-TOT-001', $row['sku']);
        self::assertSame(185000, (int) $row['price_centavos']);
        self::assertSame('tikog', $row['fiber']);
        self::assertSame('Banig plaiting', $row['technique']);
        self::assertSame(1, (int) $row['is_available']);

        $audit = $this->database()->query('SELECT * FROM stock_audit WHERE product_id = ' . $id)->fetchAll();
        self::assertCount(1, $audit);
        self::assertSame($this->lita, (int) $audit[0]['weaver_id']);
        self::assertNull($audit[0]['admin_id']);
        self::assertSame('initial_stock', $audit[0]['reason']);

        $storefront = new ProductService($this->database());
        self::assertNull($storefront->getBySlug((string) $row['slug']));
        $names = array_column($storefront->list(['q' => 'Tikog Market'])['products'], 'name');
        self::assertNotContains('Tikog Market Tote', $names);
    }

    public function testValidationRejectsBadFields(): void
    {
        $result = $this->products->validate([
            'name' => '', 'category' => 'Backpack', 'fiber' => 'leather', 'price' => 'free',
            'stock' => '-1', 'low_stock_threshold' => 'x', 'width' => '0', 'height' => '201', 'depth' => '',
            'materials' => '', 'place_of_make' => '', 'story' => str_repeat('a', 4001),
        ], true);

        foreach (
            ['name', 'category', 'fiber', 'price', 'stock', 'low_stock_threshold', 'width', 'height', 'depth',
                'materials', 'place_of_make', 'story'] as $field
        ) {
            self::assertArrayHasKey($field, $result['errors'], $field);
        }
        // Stock is only accepted on creation.
        self::assertArrayNotHasKey('stock', $this->products->validate(['stock' => 'x'], false)['errors']);
    }

    public function testEveryReadAndWriteIsScopedToTheOwner(): void
    {
        $rosaBag = $this->insertWeaverProduct($this->rosa, 'ROSA-1', 4);
        $litaBag = $this->insertWeaverProduct($this->lita, 'LITA-1', 4);

        self::assertNull($this->products->find($this->lita, $rosaBag));
        self::assertSame($litaBag, $this->products->find($this->lita, $litaBag)['id']);
        self::assertSame([$litaBag], array_column($this->products->list($this->lita), 'id'));

        $values = $this->products->validate($this->validInput(), false)['values'];
        foreach (
            [
                fn () => $this->products->update($this->lita, $rosaBag, $values, null),
                fn () => $this->products->archive($this->lita, $rosaBag),
                fn () => $this->products->setAvailable($this->lita, $rosaBag, false),
            ] as $write
        ) {
            try {
                $write();
                self::fail('A write on another weaver\'s product must fail.');
            } catch (AdminActionException) {
                // Expected.
            }
        }
        $row = $this->database()->query('SELECT * FROM products WHERE id = ' . $rosaBag)->fetch();
        self::assertSame('Bag ROSA-1', $row['name']);
        self::assertSame('active', $row['listing_status']);
        self::assertSame(1, (int) $row['is_available']);
    }

    public function testEditKeepsThreeConfigAndStatusAndAddsANewPrimaryImage(): void
    {
        $id = $this->insertWeaverProduct($this->lita, 'LITA-2', 4);
        $values = $this->products->validate($this->validInput(['name' => 'Renamed Tote']), false)['values'];

        $this->products->update($this->lita, $id, $values, '/uploads/' . str_repeat('a', 32) . '.png');

        $row = $this->database()->query('SELECT * FROM products WHERE id = ' . $id)->fetch();
        self::assertSame('Renamed Tote', $row['name']);
        self::assertSame('{"shape":"tote"}', $row['three_config']);
        self::assertSame('active', $row['listing_status']);
        self::assertSame(4, (int) $row['stock']);
        $primary = $this->database()->query(
            'SELECT path FROM product_images WHERE is_primary = 1 AND product_id = ' . $id
        )->fetchAll();
        self::assertCount(1, $primary);
    }

    public function testArchiveAndAvailabilityAffectTheStorefront(): void
    {
        $id = $this->insertWeaverProduct($this->lita, 'LITA-3', 4);
        $storefront = new ProductService($this->database());

        $this->products->setAvailable($this->lita, $id, false);
        self::assertSame(0, $storefront->getBySlug('lita-3')['stock']);
        self::assertNotContains('Bag LITA-3', array_column(
            $storefront->list(['hide_soldout' => true, 'q' => 'LITA-3'])['products'],
            'name'
        ));

        $this->products->setAvailable($this->lita, $id, true);
        self::assertSame(4, $storefront->getBySlug('lita-3')['stock']);

        $this->products->archive($this->lita, $id);
        self::assertNull($storefront->getBySlug('lita-3'));
        self::assertSame('archived', $this->products->find($this->lita, $id)['listing_status']);
    }

    public function testDeactivatedListingIsReadOnlyForTheWeaver(): void
    {
        $id = $this->insertWeaverProduct($this->lita, 'LITA-4', 4, 'deactivated');
        $values = $this->products->validate($this->validInput(), false)['values'];

        self::assertSame('deactivated', $this->products->find($this->lita, $id)['listing_status']);
        foreach (
            [
                fn () => $this->products->update($this->lita, $id, $values, null),
                fn () => $this->products->archive($this->lita, $id),
                fn () => $this->products->setAvailable($this->lita, $id, false),
            ] as $write
        ) {
            try {
                $write();
                self::fail('A deactivated listing must refuse weaver writes.');
            } catch (AdminActionException $refused) {
                self::assertStringContainsString('deactivated', $refused->getMessage());
            }
        }
        $row = $this->database()->query('SELECT * FROM products WHERE id = ' . $id)->fetch();
        self::assertSame('deactivated', $row['listing_status']);
        self::assertSame('Bag LITA-4', $row['name']);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Tikog Market Tote',
            'category' => 'Tote',
            'fiber' => 'tikog',
            'materials' => 'Tikog with cotton lining',
            'place_of_make' => 'Basey, Samar',
            'community' => 'Basey',
            'region' => 'Eastern Visayas (Region VIII)',
            'technique' => 'Banig plaiting',
            'description' => 'A roomy market tote.',
            'story' => 'Plaited over three afternoons.',
            'price' => '1,850',
            'stock' => '4',
            'low_stock_threshold' => '2',
            'width' => '34',
            'height' => '28',
            'depth' => '12',
        ], $overrides);
    }
}
