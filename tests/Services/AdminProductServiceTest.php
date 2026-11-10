<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\AdminProductService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use PDO;

/**
 * Boundary tests for administrator product management (PRD R20, R23):
 * exact centavos parsing without floats, initial-stock auditing only when
 * stock > 0, unique slugs for duplicate names, updates that never touch
 * stock, and duplicate-SKU validation errors.
 */
final class AdminProductServiceTest extends IsolatedDatabaseTestCase
{
    private AdminProductService $products;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->products = new AdminProductService($this->database(), habi_repo_root() . '/public');
        $this->adminId = (int) $this->database()->query('SELECT id FROM admins LIMIT 1')->fetchColumn();
    }

    public function testParsePesosBoundaries(): void
    {
        self::assertSame(165050, AdminProductService::parsePesos('1,650.50'));
        self::assertSame(50, AdminProductService::parsePesos('0.5'));
        self::assertSame(0, AdminProductService::parsePesos('0'));
        self::assertSame(165000, AdminProductService::parsePesos('1650'));
        self::assertSame(100, AdminProductService::parsePesos('1'));
        self::assertSame(99999999, AdminProductService::parsePesos('999999.99'));
        self::assertSame(12345678, AdminProductService::parsePesos('123,456.78'));

        // More than two decimals, malformed commas, signs and junk are null.
        self::assertNull(AdminProductService::parsePesos('1.234'));
        self::assertNull(AdminProductService::parsePesos('1,65'));
        self::assertNull(AdminProductService::parsePesos('-5'));
        self::assertNull(AdminProductService::parsePesos('abc'));
        self::assertNull(AdminProductService::parsePesos('1,2345'));
        self::assertNull(AdminProductService::parsePesos('1000000.00'), 'Above the centavos cap.');
        self::assertNull(AdminProductService::parsePesos(''));
    }

    public function testCreateWithPositiveStockWritesTheInitialStockAuditAndPrimaryImage(): void
    {
        $validated = $this->products->validate(
            $this->input(['sku' => 'HBI-CRE-1', 'price' => '1,650.50', 'stock' => '5']),
            null
        );
        self::assertSame([], $validated['errors']);
        self::assertSame(165050, $validated['values']['price_centavos']);

        $id = $this->products->create($validated['values'], '/uploads/test.png', $this->adminId);

        $row = $this->productRow($id);
        self::assertSame('HBI-CRE-1', $row['sku']);
        self::assertSame(165050, (int) $row['price_centavos']);
        self::assertSame(5, (int) $row['stock']);
        self::assertSame(1, (int) $row['is_active']);

        $image = $this->database()->prepare(
            'SELECT path, is_primary FROM product_images WHERE product_id = ?'
        );
        $image->execute([$id]);
        self::assertSame(
            [['path' => '/uploads/test.png', 'is_primary' => 1]],
            $image->fetchAll(PDO::FETCH_ASSOC)
        );

        $audit = $this->auditRows($id);
        self::assertCount(1, $audit);
        self::assertSame($this->adminId, (int) $audit[0]['admin_id']);
        self::assertSame(0, (int) $audit[0]['old_stock']);
        self::assertSame(5, (int) $audit[0]['new_stock']);
        self::assertSame('initial_stock', $audit[0]['reason']);
    }

    public function testCreateWithZeroStockWritesNoAuditRow(): void
    {
        $validated = $this->products->validate($this->input(['sku' => 'HBI-CRE-2', 'stock' => '0']), null);
        self::assertSame([], $validated['errors']);

        $id = $this->products->create($validated['values'], '/uploads/zero.png', $this->adminId);

        self::assertSame(0, (int) $this->productRow($id)['stock']);
        self::assertSame([], $this->auditRows($id));
    }

    public function testTwoProductsWithTheSameNameGetDistinctSlugs(): void
    {
        $first = $this->products->validate($this->input(['sku' => 'HBI-SLG-1', 'name' => 'Sunrise Tote']), null);
        $second = $this->products->validate($this->input(['sku' => 'HBI-SLG-2', 'name' => 'Sunrise Tote']), null);

        $idOne = $this->products->create($first['values'], '/uploads/one.png', $this->adminId);
        $idTwo = $this->products->create($second['values'], '/uploads/two.png', $this->adminId);

        $slugOne = (string) $this->productRow($idOne)['slug'];
        $slugTwo = (string) $this->productRow($idTwo)['slug'];
        self::assertSame('sunrise-tote', $slugOne);
        self::assertSame('sunrise-tote-2', $slugTwo);
        self::assertNotSame($slugOne, $slugTwo);
    }

    public function testUpdateNeverChangesStockOrWritesAudit(): void
    {
        $created = $this->products->validate($this->input(['sku' => 'HBI-UPD-1', 'stock' => '7']), null);
        $id = $this->products->create($created['values'], '/uploads/before.png', $this->adminId);
        self::assertSame(7, (int) $this->productRow($id)['stock']);

        // A stale form even tries to submit a different stock value.
        $edited = $this->products->validate(
            $this->input(['sku' => 'HBI-UPD-1', 'name' => 'Renamed Tote', 'price' => '999.99', 'stock' => '99']),
            $id
        );
        self::assertSame([], $edited['errors']);
        $this->products->update($id, $edited['values'], '/uploads/after.png');

        $row = $this->productRow($id);
        self::assertSame('Renamed Tote', $row['name']);
        self::assertSame(99999, (int) $row['price_centavos']);
        self::assertSame(7, (int) $row['stock'], 'update() must never change stock.');
        self::assertSame(1, $this->countAudit($id), 'Only the initial_stock row may exist.');

        $primary = $this->database()->prepare(
            'SELECT path FROM product_images WHERE product_id = ? AND is_primary = 1'
        );
        $primary->execute([$id]);
        self::assertSame('/uploads/after.png', (string) $primary->fetchColumn());
    }

    public function testDuplicateSkuIsAValidationErrorAndKeepsItsOwnSkuWhenEditing(): void
    {
        $created = $this->products->validate($this->input(['sku' => 'HBI-DUP-1']), null);
        $id = $this->products->create($created['values'], '/uploads/dup.png', $this->adminId);

        $duplicate = $this->products->validate($this->input(['sku' => 'hbi-dup-1']), null);
        self::assertArrayHasKey('sku', $duplicate['errors']);

        // Editing the same product with its own SKU is fine.
        $own = $this->products->validate($this->input(['sku' => 'HBI-DUP-1']), $id);
        self::assertArrayNotHasKey('sku', $own['errors']);
    }

    public function testValidateRejectsBadPriceAndMissingName(): void
    {
        $result = $this->products->validate($this->input(['name' => '  ', 'price' => 'abc']), null);
        self::assertArrayHasKey('name', $result['errors']);
        self::assertArrayHasKey('price', $result['errors']);
        self::assertNull($result['values']['price_centavos']);
    }

    public function testHandleStyleMustBeOneTheViewerBuildsForTheShape(): void
    {
        $tote = $this->products->validate($this->input(['shape' => 'tote', 'handle_style' => 'top_handle']), null);
        self::assertArrayHasKey('handle_style', $tote['errors']);

        $clutch = $this->products->validate($this->input(['shape' => 'clutch', 'handle_style' => 'none']), null);
        self::assertArrayNotHasKey('handle_style', $clutch['errors']);

        $sling = $this->products->validate(
            $this->input(['shape' => 'sling', 'handle_style' => 'crossbody_strap']),
            null
        );
        self::assertArrayNotHasKey('handle_style', $sling['errors']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function input(array $overrides = []): array
    {
        return array_merge(
            [
                'name' => 'Test Tote',
                'sku' => 'HBI-TST-001',
                'category' => 'Tote',
                'description' => 'A test bag.',
                'materials' => 'Canvas',
                'place_of_make' => 'Manila',
                'price' => '1650',
                'stock' => '5',
                'low_stock_threshold' => '3',
                'width' => '30',
                'height' => '35',
                'depth' => '12',
                'shape' => 'tote',
                'handle_style' => 'double_handles',
                'material' => 'canvas',
                'lining' => 'cotton',
                'color_body' => '#C9A47A',
                'color_trim' => '#6D5A4B',
                'color_lining' => '#F3E7D3',
                'pockets' => ['interior_zip'],
                'demo_image' => '',
            ],
            $overrides
        );
    }

    /** @return array<string, mixed> */
    private function productRow(int $id): array
    {
        $statement = $this->database()->prepare('SELECT * FROM products WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        self::assertNotFalse($row);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(int $productId): array
    {
        $statement = $this->database()->prepare(
            'SELECT admin_id, old_stock, new_stock, reason FROM stock_audit WHERE product_id = ? ORDER BY id'
        );
        $statement->execute([$productId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function countAudit(int $productId): int
    {
        $statement = $this->database()->prepare('SELECT COUNT(*) FROM stock_audit WHERE product_id = ?');
        $statement->execute([$productId]);

        return (int) $statement->fetchColumn();
    }
}
