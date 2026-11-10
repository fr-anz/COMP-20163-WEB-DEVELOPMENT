<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\AdminActionException;
use Habi\Services\StockService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use PDO;

/**
 * Boundary tests for administrator stock adjustments (PRD R18, R21): every
 * applied change writes exactly one stock_audit row with the acting admin
 * and old/new stock, a negative result or a stale expectedStock is rejected
 * without writing, invalid reasons and zero changes are rejected, and the
 * audit list is newest first with described reasons.
 */
final class StockServiceTest extends IsolatedDatabaseTestCase
{
    private StockService $stock;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->stock = new StockService($this->database());
        $this->adminId = (int) $this->database()->query('SELECT id FROM admins LIMIT 1')->fetchColumn();
    }

    public function testIncreaseWritesExactlyOneAuditRowWithAdminOldNewAndReasonNote(): void
    {
        $product = $this->createProduct('HBI-STK-1', 'Stock Tote', 10);

        // preview() shows the same numbers without writing anything.
        $preview = $this->stock->preview($product, 5, 'Restock received', 'PO 123');
        self::assertSame(10, $preview['old_stock']);
        self::assertSame(15, $preview['new_stock']);
        self::assertSame(0, $this->countAudit());

        $result = $this->stock->adjust($product, 10, 5, 'Restock received', 'PO 123', $this->adminId);
        self::assertSame(['old_stock' => 10, 'new_stock' => 15], $result);

        $this->assertStock($product, 15);
        self::assertSame(1, $this->countAudit());
        $row = $this->auditRows($product)[0];
        self::assertSame($this->adminId, (int) $row['admin_id']);
        self::assertSame(10, (int) $row['old_stock']);
        self::assertSame(15, (int) $row['new_stock']);
        self::assertSame('Restock received — PO 123', $row['reason']);
    }

    public function testDecreaseWithoutNoteStoresTheBareReason(): void
    {
        $product = $this->createProduct('HBI-STK-2', 'Stock Satchel', 10);

        $result = $this->stock->adjust($product, 10, -3, 'Count correction', '', $this->adminId);
        self::assertSame(['old_stock' => 10, 'new_stock' => 7], $result);

        $this->assertStock($product, 7);
        self::assertSame(1, $this->countAudit());
        $row = $this->auditRows($product)[0];
        self::assertSame(10, (int) $row['old_stock']);
        self::assertSame(7, (int) $row['new_stock']);
        self::assertSame('Count correction', $row['reason']);
    }

    public function testResultBelowZeroIsRejectedWithNoWrite(): void
    {
        $product = $this->createProduct('HBI-STK-3', 'Stock Sling', 10);

        try {
            $this->stock->adjust($product, 10, -11, 'Damaged in storage', '', $this->adminId);
            self::fail('Expected an AdminActionException for a negative result.');
        } catch (AdminActionException $exception) {
            self::assertSame('change', $exception->field);
        }

        $this->assertStock($product, 10);
        self::assertSame(0, $this->countAudit());
    }

    public function testStaleExpectedStockIsRejectedWithCurrentStockContextAndNoWrite(): void
    {
        $product = $this->createProduct('HBI-STK-4', 'Stock Clutch', 10);

        // Another adjustment moved stock to 12 before this form was sent.
        $this->stock->adjust($product, 10, 2, 'Returned item', '', $this->adminId);

        try {
            $this->stock->adjust($product, 10, 5, 'Restock received', '', $this->adminId);
            self::fail('Expected an AdminActionException for stale expected stock.');
        } catch (AdminActionException $exception) {
            self::assertSame(['current_stock' => 12], $exception->context);
        }

        $this->assertStock($product, 12);
        self::assertSame(1, $this->countAudit(), 'Only the first adjustment may be audited.');
    }

    public function testInvalidReasonZeroChangeAndOversizedChangeAreRejected(): void
    {
        $product = $this->createProduct('HBI-STK-5', 'Stock Tote Two', 10);

        $cases = [
            ['change' => 5, 'reason' => 'Made up reason', 'note' => '', 'field' => 'reason'],
            ['change' => 0, 'reason' => 'Restock received', 'note' => '', 'field' => 'change'],
            ['change' => '0', 'reason' => 'Restock received', 'note' => '', 'field' => 'change'],
            ['change' => 10000, 'reason' => 'Restock received', 'note' => '', 'field' => 'change'],
            ['change' => '2.5', 'reason' => 'Restock received', 'note' => '', 'field' => 'change'],
            [
                'change' => 5,
                'reason' => 'Restock received',
                'note' => str_repeat('x', 201),
                'field' => 'note',
            ],
        ];
        foreach ($cases as $case) {
            try {
                $this->stock->adjust(
                    $product,
                    10,
                    $case['change'],
                    $case['reason'],
                    $case['note'],
                    $this->adminId
                );
                self::fail('Expected an AdminActionException for field ' . $case['field']);
            } catch (AdminActionException $exception) {
                self::assertSame($case['field'], $exception->field);
            }
        }

        $this->assertStock($product, 10);
        self::assertSame(0, $this->countAudit());
    }

    public function testAuditIsNewestFirstPerProductWithActorAndDescribedReason(): void
    {
        $first = $this->createProduct('HBI-STK-6', 'Audit Tote', 10);
        $second = $this->createProduct('HBI-STK-7', 'Audit Satchel', 4);

        $this->stock->adjust($first, 10, 5, 'Restock received', '', $this->adminId);
        $this->stock->adjust($second, 4, 1, 'Returned item', '', $this->adminId);
        $this->stock->adjust($first, 15, -2, 'Damaged in storage', '', $this->adminId);

        $all = $this->stock->audit();
        self::assertSame(3, $all['total']);
        self::assertSame(
            [$first, $second, $first],
            array_column($all['entries'], 'product_id'),
            'Audit rows must be newest first.'
        );
        self::assertSame('Damaged in storage', $all['entries'][0]['reason']);
        self::assertSame('Returned item', $all['entries'][1]['reason']);
        self::assertSame('admin@example.test', $all['entries'][0]['actor']);

        $onlyFirst = $this->stock->audit(1, $first);
        self::assertSame(2, $onlyFirst['total']);
        self::assertSame([15, 10], array_column($onlyFirst['entries'], 'old_stock'));
        self::assertSame([-2, 5], array_column($onlyFirst['entries'], 'change'));

        self::assertSame(
            'Order H-20260101-0001 cancelled — stock restored',
            StockService::describeReason('order_cancelled: H-20260101-0001')
        );
        self::assertSame(
            'Initial stock (new product)',
            StockService::describeReason('initial_stock')
        );
    }

    private function createProduct(string $sku, string $name, int $stock): int
    {
        $insert = $this->database()->prepare(
            'INSERT INTO products (sku, slug, name, category, price_centavos, stock, is_active)'
            . " VALUES (?, ?, ?, 'Tote', 100000, ?, 1)"
        );
        $insert->execute([$sku, $sku, $name, $stock]);

        return (int) $this->database()->lastInsertId();
    }

    private function assertStock(int $productId, int $expectedStock): void
    {
        $statement = $this->database()->prepare('SELECT stock FROM products WHERE id = ?');
        $statement->execute([$productId]);

        self::assertSame($expectedStock, (int) $statement->fetchColumn());
    }

    private function countAudit(): int
    {
        return (int) $this->database()->query('SELECT COUNT(*) FROM stock_audit')->fetchColumn();
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
}
