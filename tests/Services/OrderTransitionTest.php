<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\AdminActionException;
use Habi\Services\OrderService;
use Habi\Services\PaymentProofService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use PDO;
use PDOException;

/**
 * Merged order and payment transitions (PRD R22, R44; SDD §15.4): reject
 * from pending and cancel from confirmed/packed need a reason and restore
 * stock with audit rows; transfer payments are accepted or refused (reason
 * required); COD is marked paid only once shipped or delivered; each
 * transition writes one order_events row; a stale form ("expected"
 * state no longer current) changes nothing; mid-transition failures roll
 * everything back.
 */
final class OrderTransitionTest extends IsolatedDatabaseTestCase
{
    private OrderService $orders;
    private int $adminId;
    private string $proofDir;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->proofDir = sys_get_temp_dir() . '/habi-transition-proofs-' . getmypid() . '-' . uniqid();
        $this->orders = new OrderService($this->database(), new PaymentProofService($this->proofDir));
        $this->adminId = (int) $this->database()->query('SELECT id FROM admins LIMIT 1')->fetchColumn();
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->proofDir . '/tmp/*') ?: [], glob($this->proofDir . '/*') ?: []) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->proofDir . '/tmp');
        @rmdir($this->proofDir);
        parent::tearDown();
    }

    public function testRejectFromPendingRestoresStockWithAuditAndEventAndStoresTheReason(): void
    {
        $tote = $this->insertProduct('HBI-REJ-1', 'Reject Tote', 100000, 5);
        $clutch = $this->insertProduct('HBI-REJ-2', 'Reject Clutch', 50000, 3);
        $order = $this->place([[$tote, 2, 100000], [$clutch, 1, 50000]], 'cod', 'rej-1');
        $number = (string) $order['order_number'];
        $this->assertStock($tote, 3);
        $this->assertStock($clutch, 2);

        $this->orders->reject($number, $this->adminId, '  Out of delivery   area ');

        $row = $this->orderRow($number);
        self::assertSame(['rejected', 'Out of delivery area'], [$row['status'], $row['rejection_reason']]);
        $this->assertStock($tote, 5);
        $this->assertStock($clutch, 3);
        $audit = $this->auditRows('order_rejected: ' . $number);
        self::assertSame(
            [[$tote, $this->adminId, 3, 5], [$clutch, $this->adminId, 2, 3]],
            array_map(static fn (array $r): array => [
                (int) $r['product_id'], (int) $r['admin_id'], (int) $r['old_stock'], (int) $r['new_stock'],
            ], $audit)
        );
        self::assertSame(
            ['status', 'pending', 'rejected', 'admin', $this->adminId, 'Out of delivery area'],
            $this->lastEvent((int) $order['id'])
        );
    }

    public function testRejectAndCancelRequireAReasonAndChangeNothingWithoutOne(): void
    {
        $product = $this->insertProduct('HBI-REJ-3', 'Reason Tote', 100000, 5);
        $pending = $this->place([[$product, 1, 100000]], 'cod', 'reason-1');
        $confirmed = $this->place([[$product, 1, 100000]], 'cod', 'reason-2');
        $this->orders->advanceStatus((string) $confirmed['order_number'], 'confirmed', $this->adminId);
        $events = $this->countRows('order_events');

        foreach (
            [
                fn () => $this->orders->reject((string) $pending['order_number'], $this->adminId, '   '),
                fn () => $this->orders->cancel((string) $confirmed['order_number'], $this->adminId, ''),
                fn () => $this->orders->reject(
                    (string) $pending['order_number'],
                    $this->adminId,
                    str_repeat('x', OrderService::MAX_REASON_LENGTH + 1)
                ),
            ] as $attempt
        ) {
            try {
                $attempt();
                self::fail('Expected a missing-reason AdminActionException.');
            } catch (AdminActionException $refused) {
                self::assertSame('reason', $refused->field);
            }
        }
        self::assertSame('pending', $this->orderRow((string) $pending['order_number'])['status']);
        self::assertSame('confirmed', $this->orderRow((string) $confirmed['order_number'])['status']);
        $this->assertStock($product, 3);
        self::assertSame($events, $this->countRows('order_events'));
    }

    public function testRejectIsRefusedOutsidePendingAndCancelOutsideConfirmedOrPacked(): void
    {
        $product = $this->insertProduct('HBI-REJ-4', 'Guard Tote', 100000, 10);
        $order = $this->place([[$product, 1, 100000]], 'cod', 'guard-1');
        $number = (string) $order['order_number'];

        $this->assertRefused(fn () => $this->orders->cancel($number, $this->adminId, 'Changed mind'));
        $this->orders->advanceStatus($number, 'confirmed', $this->adminId);
        $this->assertRefused(fn () => $this->orders->reject($number, $this->adminId, 'Too late'));
        $this->orders->advanceStatus($number, 'packed', $this->adminId);
        $this->orders->advanceStatus($number, 'shipped', $this->adminId);
        $this->assertRefused(fn () => $this->orders->cancel($number, $this->adminId, 'Too late'));
        $this->assertRefused(fn () => $this->orders->reject($number, $this->adminId, 'Too late'));
        self::assertSame('shipped', $this->orderRow($number)['status']);
        $this->assertStock($product, 9);
        self::assertSame([], $this->auditRows('order_cancelled: ' . $number));
        self::assertSame([], $this->auditRows('order_rejected: ' . $number));
    }

    public function testCancelRecordsTheReasonOnTheEventAndRestoresStock(): void
    {
        $product = $this->insertProduct('HBI-CAN-1', 'Cancel Tote', 100000, 4);
        $order = $this->place([[$product, 2, 100000]], 'cod', 'cancel-1');
        $number = (string) $order['order_number'];
        $this->orders->advanceStatus($number, 'confirmed', $this->adminId);

        $this->orders->cancel($number, $this->adminId, 'Customer asked to cancel');

        self::assertSame('cancelled', $this->orderRow($number)['status']);
        self::assertNull($this->orderRow($number)['rejection_reason'], 'Only rejections show a reason to customers.');
        $this->assertStock($product, 4);
        self::assertCount(1, $this->auditRows('order_cancelled: ' . $number));
        self::assertSame(
            ['status', 'confirmed', 'cancelled', 'admin', $this->adminId, 'Customer asked to cancel'],
            $this->lastEvent((int) $order['id'])
        );
    }

    public function testRejectRollsBackWhenTheSecondAuditInsertFails(): void
    {
        $first = $this->insertProduct('HBI-RB-1', 'Rollback Tote', 100000, 5);
        $second = $this->insertProduct('HBI-RB-2', 'Rollback Sling', 100000, 5);
        $order = $this->place([[$first, 1, 100000], [$second, 1, 100000]], 'cod', 'rb-1');
        $number = (string) $order['order_number'];
        $events = $this->countRows('order_events');
        $this->database()->exec(
            'CREATE TRIGGER fail_reject_audit BEFORE INSERT ON stock_audit WHEN NEW.product_id = ' . $second
            . " AND NEW.reason LIKE 'order_rejected%' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END"
        );

        try {
            $this->orders->reject($number, $this->adminId, 'Fraud check');
            self::fail('Expected the trigger to abort the rejection.');
        } catch (PDOException) {
            // Expected.
        }

        $row = $this->orderRow($number);
        self::assertSame(['pending', null], [$row['status'], $row['rejection_reason']]);
        $this->assertStock($first, 4);
        $this->assertStock($second, 4);
        self::assertSame([], $this->auditRows('order_rejected: ' . $number));
        self::assertSame($events, $this->countRows('order_events'));
    }

    public function testTransferIsAcceptedThenConfirmableWithProofReviewAndEvents(): void
    {
        $product = $this->insertProduct('HBI-TRF-1', 'Transfer Tote', 100000, 5);
        $order = $this->place([[$product, 1, 100000]], 'transfer', 'trf-1');
        $number = (string) $order['order_number'];
        $this->assertRefused(fn () => $this->orders->advanceStatus($number, 'confirmed', $this->adminId));

        $this->orders->acceptPayment($number, $this->adminId);

        self::assertSame('paid', $this->orderRow($number)['payment_status']);
        self::assertSame(
            ['payment_status', 'for_verification', 'paid', 'admin', $this->adminId, null],
            $this->lastEvent((int) $order['id'])
        );
        $proof = $this->orders->proofFor((int) $order['id']);
        self::assertNotNull($proof);
        self::assertSame($this->adminId, $proof['reviewed_by_admin_id']);
        self::assertNotNull($proof['reviewed_at']);
        $this->assertRefused(fn () => $this->orders->acceptPayment($number, $this->adminId));
        $this->orders->advanceStatus($number, 'confirmed', $this->adminId);
        self::assertSame('confirmed', $this->orderRow($number)['status']);
    }

    public function testRefusingATransferNeedsAReasonBlocksConfirmAndLeavesRejectOpen(): void
    {
        $product = $this->insertProduct('HBI-TRF-2', 'Refuse Tote', 100000, 5);
        $order = $this->place([[$product, 2, 100000]], 'transfer', 'trf-2');
        $number = (string) $order['order_number'];

        try {
            $this->orders->refusePayment($number, $this->adminId, ' ');
            self::fail('Expected a missing-reason refusal.');
        } catch (AdminActionException $refused) {
            self::assertSame('reason', $refused->field);
        }
        $this->orders->refusePayment($number, $this->adminId, 'Amount does not match');

        self::assertSame(['pending', 'rejected'], [
            $this->orderRow($number)['status'], $this->orderRow($number)['payment_status'],
        ]);
        self::assertSame(
            ['payment_status', 'for_verification', 'rejected', 'admin', $this->adminId, 'Amount does not match'],
            $this->lastEvent((int) $order['id'])
        );
        self::assertSame('Amount does not match', $this->orders->proofFor((int) $order['id'])['review_note']);
        $this->assertRefused(fn () => $this->orders->advanceStatus($number, 'confirmed', $this->adminId));
        $this->assertRefused(fn () => $this->orders->acceptPayment($number, $this->adminId));

        $this->orders->reject($number, $this->adminId, 'Payment refused');
        $this->assertStock($product, 5);
    }

    public function testPaymentActionsAreRefusedForOtherMethodsAndTerminalOrders(): void
    {
        $product = $this->insertProduct('HBI-TRF-3', 'Method Tote', 100000, 9);
        $cod = $this->place([[$product, 1, 100000]], 'cod', 'm-1');
        $card = $this->place([[$product, 1, 100000]], 'card', 'm-2');
        $transfer = $this->place([[$product, 1, 100000]], 'transfer', 'm-3');
        $this->orders->reject((string) $transfer['order_number'], $this->adminId, 'Duplicate order');

        foreach ([$cod, $card, $transfer] as $order) {
            $number = (string) $order['order_number'];
            $this->assertRefused(fn () => $this->orders->acceptPayment($number, $this->adminId));
            $this->assertRefused(fn () => $this->orders->refusePayment($number, $this->adminId, 'No'));
        }
        self::assertSame('for_verification', $this->orderRow((string) $transfer['order_number'])['payment_status']);
    }

    public function testCodIsMarkedPaidOnlyFromShippedOrDelivered(): void
    {
        $product = $this->insertProduct('HBI-COD-1', 'Cod Tote', 100000, 9);
        $order = $this->place([[$product, 1, 100000]], 'cod', 'cod-1');
        $number = (string) $order['order_number'];

        foreach (['confirmed', 'packed'] as $step) {
            $this->assertRefused(fn () => $this->orders->markCodPaid($number, $this->adminId));
            $this->orders->advanceStatus($number, $step, $this->adminId);
        }
        $this->assertRefused(fn () => $this->orders->markCodPaid($number, $this->adminId));
        $this->orders->advanceStatus($number, 'shipped', $this->adminId);
        $this->orders->advanceStatus($number, 'delivered', $this->adminId);

        $this->orders->markCodPaid($number, $this->adminId);

        self::assertSame('paid', $this->orderRow($number)['payment_status']);
        self::assertSame(
            ['payment_status', 'unpaid', 'paid', 'admin', $this->adminId, null],
            $this->lastEvent((int) $order['id'])
        );
        $this->assertRefused(fn () => $this->orders->markCodPaid($number, $this->adminId));

        $card = $this->place([[$product, 1, 100000]], 'card', 'cod-2');
        $this->assertRefused(fn () => $this->orders->markCodPaid((string) $card['order_number'], $this->adminId));
    }

    public function testAStaleFormChangesNothing(): void
    {
        $product = $this->insertProduct('HBI-STL-1', 'Stale Tote', 100000, 9);
        $order = $this->place([[$product, 1, 100000]], 'transfer', 'stale-1');
        $number = (string) $order['order_number'];
        $events = $this->countRows('order_events');

        // Another admin accepted the payment after this form was rendered.
        $this->database()->prepare("UPDATE orders SET payment_status = 'paid' WHERE order_number = ?")
            ->execute([$number]);
        foreach (
            [
                fn () => $this->orders->reject($number, $this->adminId, 'Stale', 'pending/for_verification'),
                fn () => $this->orders->advanceStatus($number, 'confirmed', $this->adminId, 'pending/for_verification'),
                fn () => $this->orders->refusePayment($number, $this->adminId, 'Stale'),
            ] as $attempt
        ) {
            try {
                $attempt();
                self::fail('Expected a stale-form refusal.');
            } catch (AdminActionException $stale) {
                self::assertStringContainsString('changed', $stale->getMessage());
            }
        }
        self::assertSame(['pending', 'paid'], [
            $this->orderRow($number)['status'], $this->orderRow($number)['payment_status'],
        ]);
        $this->assertStock($product, 8);
        self::assertSame($events, $this->countRows('order_events'));

        // The current state still works.
        $this->orders->advanceStatus($number, 'confirmed', $this->adminId, 'pending/paid');
        self::assertSame('confirmed', $this->orderRow($number)['status']);
    }

    public function testEventsAreReturnedOldestFirstWithNotes(): void
    {
        $product = $this->insertProduct('HBI-EVT-1', 'Event Tote', 100000, 9);
        $order = $this->place([[$product, 1, 100000]], 'cod', 'evt-1');
        $this->orders->reject((string) $order['order_number'], $this->adminId, 'Address incomplete');

        $events = $this->orders->events((int) $order['id']);

        self::assertSame(
            [['status', null, 'pending'], ['payment_status', null, 'unpaid'], ['status', 'pending', 'rejected']],
            array_map(static fn (array $e): array => [$e['field'], $e['old_value'], $e['new_value']], $events)
        );
        self::assertSame('Address incomplete', $events[2]['note']);
        self::assertSame('admin', $events[2]['actor_role']);
    }

    public function testListFiltersByPaymentStatusAndMethod(): void
    {
        $product = $this->insertProduct('HBI-LST-1', 'List Tote', 100000, 20);
        $cod = $this->place([[$product, 1, 100000]], 'cod', 'lst-1');
        $transfer = $this->place([[$product, 1, 100000]], 'transfer', 'lst-2');
        $card = $this->place([[$product, 1, 100000]], 'card', 'lst-3');
        $numbers = static fn (array $result): array => array_column($result['orders'], 'order_number');

        self::assertSame(
            [$transfer['order_number']],
            $numbers($this->orders->listForAdmin(['payment' => 'for_verification']))
        );
        self::assertSame([$card['order_number']], $numbers($this->orders->listForAdmin(['method' => 'card'])));
        self::assertSame(
            [$cod['order_number']],
            $numbers($this->orders->listForAdmin(['method' => 'cod', 'payment' => 'unpaid', 'status' => 'pending']))
        );
        self::assertSame([], $numbers($this->orders->listForAdmin(['method' => 'cod', 'payment' => 'paid'])));
        self::assertSame(3, $this->orders->listForAdmin(['method' => 'cash', 'payment' => 'x'])['total']);
        $listed = $this->orders->listForAdmin(['method' => 'transfer'])['orders'][0];
        self::assertSame(['transfer', 'for_verification'], [$listed['payment_method'], $listed['payment_status']]);
    }

    public function testAdminSummaryCountsOrdersToConfirmPaymentsToVerifyAndSales(): void
    {
        $product = $this->insertProduct('HBI-SUM-1', 'Summary Tote', 100000, 20);
        $cod = $this->place([[$product, 1, 100000]], 'cod', 'sum-1');
        $transfer = $this->place([[$product, 2, 100000]], 'transfer', 'sum-2');
        $rejected = $this->place([[$product, 1, 100000]], 'transfer', 'sum-3');
        $this->orders->reject((string) $rejected['order_number'], $this->adminId, 'Duplicate');
        $old = $this->place([[$product, 1, 100000]], 'card', 'sum-4');
        $this->database()->prepare("UPDATE orders SET created_at = '2020-01-15 08:00:00' WHERE id = ?")
            ->execute([(int) $old['id']]);

        $summary = $this->orders->adminSummary();

        self::assertSame(3, $summary['to_confirm'], 'Pending orders; the rejected one is closed.');
        self::assertSame(1, $summary['payments_to_verify'], 'Rejected orders no longer wait for verification.');
        $today = (int) $cod['total_centavos'] + (int) $transfer['total_centavos'];
        self::assertSame($today, $summary['sales_today'], 'Rejected and cancelled orders are not sales.');
        self::assertSame($today, $summary['sales_month']);
        self::assertSame($today + (int) $old['total_centavos'], $summary['sales_total']);
        self::assertSame(3, $summary['by_status']['pending']);
        self::assertSame(1, $summary['by_status']['rejected']);
        self::assertSame(0, $summary['by_status']['delivered']);
    }

    /**
     * @param list<array{0: int, 1: int, 2: int}> $lines product id, quantity, unit centavos
     *
     * @return array<string, mixed>
     */
    private function place(array $lines, string $method, string $key): array
    {
        $payment = match ($method) {
            'cod' => ['method' => 'cod'],
            'card' => ['method' => 'card', 'result' => 'approved', 'last4' => '4242', 'brand' => 'Visa'],
            default => ['method' => 'transfer', 'proof' => $this->stagedProof(), 'sender_reference' => 'REF-1'],
        };

        return $this->orders->placeOrder(
            array_map(static fn (array $l): array => [
                'product_id' => $l[0], 'quantity' => $l[1], 'price_centavos' => $l[2],
            ], $lines),
            [
                'name' => 'Maria Clara', 'email' => 'maria@example.com', 'phone' => '+63 917 123 4567',
                'address1' => '12 Katipunan Ave', 'address2' => '', 'city' => 'Quezon City',
                'postal_code' => '1108', 'region' => 'metro_manila',
            ],
            $payment,
            $key
        );
    }

    private function stagedProof(): string
    {
        $name = bin2hex(random_bytes(16)) . '.png';
        if (!is_dir($this->proofDir . '/tmp')) {
            mkdir($this->proofDir . '/tmp', 0770, true);
        }
        file_put_contents(
            $this->proofDir . '/tmp/' . $name,
            (string) file_get_contents(habi_repo_root() . '/tests/Fixtures/images/tiny.png')
        );

        return $name;
    }

    private function assertRefused(callable $attempt): void
    {
        try {
            $attempt();
            self::fail('Expected an AdminActionException.');
        } catch (AdminActionException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string, mixed> */
    private function orderRow(string $number): array
    {
        $statement = $this->database()->prepare('SELECT * FROM orders WHERE order_number = ?');
        $statement->execute([$number]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function assertStock(int $productId, int $expected): void
    {
        $statement = $this->database()->prepare('SELECT stock FROM products WHERE id = ?');
        $statement->execute([$productId]);
        self::assertSame($expected, (int) $statement->fetchColumn());
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $reason): array
    {
        $statement = $this->database()->prepare(
            'SELECT product_id, admin_id, old_stock, new_stock FROM stock_audit WHERE reason = ? ORDER BY id'
        );
        $statement->execute([$reason]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0: string, 1: ?string, 2: string, 3: string, 4: ?int, 5: ?string} */
    private function lastEvent(int $orderId): array
    {
        $statement = $this->database()->prepare(
            'SELECT field, old_value, new_value, actor_role, actor_id, note FROM order_events
             WHERE order_id = ? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$orderId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            $row['field'], $row['old_value'], $row['new_value'], $row['actor_role'],
            $row['actor_id'] === null ? null : (int) $row['actor_id'], $row['note'],
        ];
    }

    private function countRows(string $table): int
    {
        return (int) $this->database()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
