<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\AdminActionException;
use Habi\Services\OrderService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use PDO;
use PDOException;

/**
 * Boundary tests for the administrator order workflow (PRD R22): advancing
 * one fulfilment step at a time, rejecting skipped steps, cancelling from
 * paid/packed with per-item stock restoration audited as
 * "order_cancelled: <number>", rejecting cancellation after shipping, full
 * rollback when a mid-cancel database failure occurs, and the admin list
 * status/date filters.
 */
final class AdminOrderServiceTest extends IsolatedDatabaseTestCase
{
    private OrderService $orders;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->orders = new OrderService($this->database());
        $this->adminId = (int) $this->database()->query('SELECT id FROM admins LIMIT 1')->fetchColumn();
    }

    public function testAdvanceWalksPaidThroughPackedShippedDeliveredOneStepAtATime(): void
    {
        $product = $this->createProduct('HBI-ORD-1', 'Flow Tote', 100000, 10);
        $order = $this->placeOrder(
            [['product_id' => $product, 'quantity' => 1, 'price_centavos' => 100000]],
            'key-adv-1'
        );
        $number = (string) $order['order_number'];

        foreach (['packed', 'shipped', 'delivered'] as $target) {
            $this->orders->advanceStatus($number, $target);
            self::assertSame($target, $this->statusOf($number));
        }

        // A delivered order has no forward step left.
        try {
            $this->orders->advanceStatus($number, 'packed');
            self::fail('Expected an AdminActionException when advancing a delivered order.');
        } catch (AdminActionException) {
            self::assertSame('delivered', $this->statusOf($number));
        }
    }

    public function testAdvanceRejectsSkippingAStep(): void
    {
        $product = $this->createProduct('HBI-ORD-2', 'Skip Tote', 100000, 10);
        $order = $this->placeOrder(
            [['product_id' => $product, 'quantity' => 1, 'price_centavos' => 100000]],
            'key-adv-2'
        );
        $number = (string) $order['order_number'];
        try {
            $this->orders->advanceStatus($number, 'shipped');
            self::fail('Expected an AdminActionException for paid → shipped.');
        } catch (AdminActionException) {
            self::assertSame('paid', $this->statusOf($number));
        }

        try {
            $this->orders->advanceStatus('H-19990101-0001', 'packed');
            self::fail('Expected an AdminActionException for an unknown order.');
        } catch (AdminActionException) {
            self::assertSame('paid', $this->statusOf($number));
        }
    }

    public function testCancelFromPaidRestoresEveryItemStockWithOneAuditRowEach(): void
    {
        $tote = $this->createProduct('HBI-ORD-3', 'Cancel Tote', 150000, 10);
        $clutch = $this->createProduct('HBI-ORD-4', 'Cancel Clutch', 25000, 5);
        $order = $this->placeOrder(
            [
                ['product_id' => $tote, 'quantity' => 2, 'price_centavos' => 150000],
                ['product_id' => $clutch, 'quantity' => 1, 'price_centavos' => 25000],
            ],
            'key-cancel-1'
        );
        $number = (string) $order['order_number'];
        $this->assertStock($tote, 8);
        $this->assertStock($clutch, 4);

        $this->orders->cancel($number, $this->adminId);

        self::assertSame('cancelled', $this->statusOf($number));
        $this->assertStock($tote, 10);
        $this->assertStock($clutch, 5);

        $rows = $this->cancelAuditRows($number);
        self::assertCount(2, $rows, 'One restoration audit row per item.');
        self::assertSame(
            [$tote, $clutch],
            array_map(static fn (array $row): int => (int) $row['product_id'], $rows)
        );
        foreach ($rows as $row) {
            self::assertSame($this->adminId, (int) $row['admin_id']);
            self::assertSame('order_cancelled: ' . $number, $row['reason']);
        }
        self::assertSame([8, 10], [(int) $rows[0]['old_stock'], (int) $rows[0]['new_stock']]);
        self::assertSame([4, 5], [(int) $rows[1]['old_stock'], (int) $rows[1]['new_stock']]);
    }

    public function testCancelFromPackedWorksButShippedAndCancelledAreRejectedWithStockUnchanged(): void
    {
        $product = $this->createProduct('HBI-ORD-5', 'Late Tote', 100000, 6);
        $order = $this->placeOrder(
            [['product_id' => $product, 'quantity' => 2, 'price_centavos' => 100000]],
            'key-cancel-2'
        );
        $number = (string) $order['order_number'];
        $this->assertStock($product, 4);

        // packed is still cancellable.
        $this->orders->advanceStatus($number, 'packed');
        $this->orders->cancel($number, $this->adminId);
        self::assertSame('cancelled', $this->statusOf($number));
        $this->assertStock($product, 6);
        self::assertCount(1, $this->cancelAuditRows($number));

        // A cancelled order can no longer be cancelled again.
        try {
            $this->orders->cancel($number, $this->adminId);
            self::fail('Expected an AdminActionException when cancelling a cancelled order.');
        } catch (AdminActionException) {
            self::assertSame('cancelled', $this->statusOf($number));
        }

        // shipped can no longer be cancelled and stock stays decremented.
        $order = $this->placeOrder(
            [['product_id' => $product, 'quantity' => 1, 'price_centavos' => 100000]],
            'key-cancel-3'
        );
        $number = (string) $order['order_number'];
        $this->orders->advanceStatus($number, 'packed');
        $this->orders->advanceStatus($number, 'shipped');
        $this->assertStock($product, 5);

        try {
            $this->orders->cancel($number, $this->adminId);
            self::fail('Expected an AdminActionException when cancelling a shipped order.');
        } catch (AdminActionException) {
            self::assertSame('shipped', $this->statusOf($number));
            $this->assertStock($product, 5);
            self::assertCount(0, $this->cancelAuditRows($number));
        }
    }

    public function testCancelIsAtomicWhenTheSecondAuditInsertFails(): void
    {
        $first = $this->createProduct('HBI-ORD-6', 'Atomic Tote', 100000, 10);
        $second = $this->createProduct('HBI-ORD-7', 'Atomic Satchel', 200000, 5);
        $order = $this->placeOrder(
            [
                ['product_id' => $first, 'quantity' => 2, 'price_centavos' => 100000],
                ['product_id' => $second, 'quantity' => 1, 'price_centavos' => 200000],
            ],
            'key-cancel-atomic'
        );
        $number = (string) $order['order_number'];
        $this->assertStock($first, 8);
        $this->assertStock($second, 4);

        // Fail the stock_audit insert for the second item only, after the
        // first item's stock was already restored inside the transaction.
        $this->database()->exec(
            'CREATE TRIGGER fail_second_cancel_audit BEFORE INSERT ON stock_audit'
            . ' WHEN NEW.product_id = ' . $second . " AND NEW.reason LIKE 'order_cancelled%'"
            . " BEGIN SELECT RAISE(ABORT, 'fixture failure mid-cancel'); END"
        );

        try {
            $this->orders->cancel($number, $this->adminId);
            self::fail('Expected the trigger to abort the cancellation.');
        } catch (PDOException) {
            // Expected: the mid-cancel failure must roll everything back.
        }

        self::assertSame('paid', $this->statusOf($number));
        $this->assertStock($first, 8);
        $this->assertStock($second, 4);
        self::assertCount(0, $this->cancelAuditRows($number));
        self::assertSame(2, $this->countRows('stock_audit'), 'Only the two order_placed rows remain.');
    }

    public function testListForAdminFiltersByStatusAndInclusiveDateRange(): void
    {
        $product = $this->createProduct('HBI-ORD-8', 'List Tote', 100000, 20);
        $line = ['product_id' => $product, 'quantity' => 1, 'price_centavos' => 100000];
        $paid = $this->placeOrder([$line], 'key-list-1');
        $packed = $this->placeOrder([$line], 'key-list-2');
        $this->orders->advanceStatus((string) $packed['order_number'], 'packed');

        $numbers = static fn (array $result): array => array_column($result['orders'], 'order_number');

        self::assertSame([$packed['order_number']], $numbers($this->orders->listForAdmin(['status' => 'packed'])));
        self::assertSame([$paid['order_number']], $numbers($this->orders->listForAdmin(['status' => 'paid'])));
        self::assertSame(
            [$packed['order_number'], $paid['order_number']],
            $numbers($this->orders->listForAdmin([])),
            'Newest first when unfiltered.'
        );
        self::assertSame([], $numbers($this->orders->listForAdmin(['status' => 'cancelled'])));
        self::assertSame(
            2,
            $this->orders->listForAdmin(['status' => 'not-a-status'])['total'],
            'An unknown status filter is ignored rather than matching nothing.'
        );

        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        self::assertSame(2, $this->orders->listForAdmin(['from' => $today, 'to' => $today])['total']);
        self::assertSame(0, $this->orders->listForAdmin(['from' => $tomorrow])['total']);
        self::assertSame(0, $this->orders->listForAdmin(['from' => '2000-01-01', 'to' => '2000-01-02'])['total']);
        self::assertSame(2, $this->orders->listForAdmin(['to' => $today])['total']);
        self::assertSame(2, $this->orders->listForAdmin(['from' => '2000-01-01'])['total']);
        self::assertSame(
            2,
            $this->orders->listForAdmin(['from' => '2026-13-40'])['total'],
            'A malformed date filter is ignored.'
        );
    }

    public function testAdminSummaryCountsPaidAndPackedAsToFulfil(): void
    {
        $product = $this->createProduct('HBI-ORD-9', 'Summary Tote', 100000, 20);
        $line = ['product_id' => $product, 'quantity' => 1, 'price_centavos' => 100000];
        $first = $this->placeOrder([$line], 'key-sum-1');
        $second = $this->placeOrder([$line], 'key-sum-2');
        $this->orders->advanceStatus((string) $first['order_number'], 'packed');
        $this->orders->advanceStatus((string) $first['order_number'], 'shipped');

        $summary = $this->orders->adminSummary();

        self::assertSame(1, $summary['to_fulfil'], 'Only the still-paid order remains to fulfil.');
        self::assertSame(2, $summary['today']);
        self::assertCount(2, $summary['recent']);
        self::assertSame($second['order_number'], $summary['recent'][0]['order_number']);
    }

    /**
     * @param list<array{product_id: int, quantity: int, price_centavos: int}> $lines
     *
     * @return array<string, mixed>
     */
    private function placeOrder(array $lines, string $key): array
    {
        return $this->orders->placeOrder($lines, $this->shipping(), $this->payment(), $key);
    }

    /** @return array<string, string> */
    private function shipping(): array
    {
        return [
            'name' => 'Maria Clara',
            'email' => 'maria@example.com',
            'phone' => '+63 917 123 4567',
            'address1' => '12 Katipunan Ave',
            'address2' => 'Barangay Silangan',
            'city' => 'Quezon City',
            'postal_code' => '1108',
            'region' => 'metro_manila',
        ];
    }

    /** @return array{result: string, last4: string, brand: string} */
    private function payment(): array
    {
        return ['result' => 'approved', 'last4' => '4242', 'brand' => 'Visa'];
    }

    private function createProduct(string $sku, string $name, int $priceCentavos, int $stock): int
    {
        $insert = $this->database()->prepare(
            'INSERT INTO products (sku, slug, name, category, price_centavos, stock, is_active)'
            . " VALUES (?, ?, ?, 'Tote', ?, ?, 1)"
        );
        $insert->execute([$sku, $sku, $name, $priceCentavos, $stock]);

        return (int) $this->database()->lastInsertId();
    }

    private function statusOf(string $orderNumber): string
    {
        $order = $this->orders->findByNumber($orderNumber);
        self::assertNotNull($order);

        return (string) $order['status'];
    }

    private function assertStock(int $productId, int $expectedStock): void
    {
        $statement = $this->database()->prepare('SELECT stock FROM products WHERE id = ?');
        $statement->execute([$productId]);

        self::assertSame($expectedStock, (int) $statement->fetchColumn());
    }

    /** @return list<array<string, mixed>> */
    private function cancelAuditRows(string $orderNumber): array
    {
        $statement = $this->database()->prepare(
            "SELECT product_id, admin_id, old_stock, new_stock, reason FROM stock_audit"
            . " WHERE reason = ? ORDER BY id"
        );
        $statement->execute(['order_cancelled: ' . $orderNumber]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function countRows(string $table): int
    {
        return (int) $this->database()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
