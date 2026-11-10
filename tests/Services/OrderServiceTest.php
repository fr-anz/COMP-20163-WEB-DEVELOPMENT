<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\CheckoutException;
use Habi\Services\OrderService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use PDO;
use RuntimeException;

/**
 * Boundary tests for order placement (PRD R10/R14/R15, SDD §8): the
 * atomic multi-line transaction with rollback after a previous decrement,
 * reviewed-price protection, active/stock guards, unapproved-payment
 * rejection, paid totals and snapshots, stock_audit rows, the daily
 * H-YYYYMMDD-NNNN sequence and idempotent repeat submissions.
 */
final class OrderServiceTest extends IsolatedDatabaseTestCase
{
    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->orders = new OrderService($this->database());
    }

    public function testPlacesMultiLinePaidOrderWithSnapshotsFeesAndAudit(): void
    {
        $tote = $this->createProduct('tote-a', 'Sinag Tote', 150000, 10);
        $clutch = $this->createProduct('clutch-a', 'Maya Clutch', 25000, 5);

        $order = $this->orders->placeOrder(
            [
                ['product_id' => $tote, 'quantity' => 2, 'price_centavos' => 150000],
                ['product_id' => $clutch, 'quantity' => 1, 'price_centavos' => 25000],
            ],
            $this->shipping(),
            $this->payment(),
            'key-1',
        );

        // Subtotal 325000 <= 500000, so Metro Manila adds its 12000 fee.
        self::assertSame('paid', $order['status']);
        self::assertMatchesRegularExpression('/^H-\d{8}-\d{4}$/', (string) $order['order_number']);
        self::assertSame(325000, (int) $order['subtotal_centavos']);
        self::assertSame(12000, (int) $order['shipping_fee_centavos']);
        self::assertSame(337000, (int) $order['total_centavos']);
        self::assertSame('Maria Clara', $order['customer_name']);
        self::assertSame('maria@example.com', $order['customer_email']);
        self::assertSame('12 Katipunan Ave', $order['shipping_address1']);
        self::assertSame('metro_manila', $order['shipping_region']);
        self::assertSame('approved', $order['payment_result']);
        self::assertSame('4242', $order['payment_last4']);
        self::assertSame('Visa', $order['payment_brand']);
        self::assertSame('key-1', $order['idempotency_key']);

        self::assertCount(2, $order['items']);
        self::assertSame(
            ['Sinag Tote', 'Maya Clutch'],
            array_column($order['items'], 'name_snapshot')
        );
        self::assertSame([150000, 25000], array_column($order['items'], 'price_centavos'));
        self::assertSame([2, 1], array_column($order['items'], 'qty'));
        self::assertSame([300000, 25000], array_column($order['items'], 'line_total_centavos'));

        $this->assertStock($tote, 8);
        $this->assertStock($clutch, 4);
        $this->assertAudit($tote, 10, 8);
        $this->assertAudit($clutch, 5, 4);
    }

    public function testRollsBackEveryLineWhenALaterLineFails(): void
    {
        $available = $this->createProduct('tote-b', 'Alon Tote', 100000, 10);
        $scarce = $this->createProduct('sling-b', 'Tala Sling', 200000, 1);

        try {
            $this->orders->placeOrder(
                [
                    ['product_id' => $available, 'quantity' => 2, 'price_centavos' => 100000],
                    ['product_id' => $scarce, 'quantity' => 5, 'price_centavos' => 200000],
                ],
                $this->shipping(),
                $this->payment(),
                'key-rollback',
            );
            self::fail('Expected a stock CheckoutException.');
        } catch (CheckoutException $exception) {
            self::assertSame(CheckoutException::REASON_STOCK, $exception->reason);
        }

        // Insufficient stock in any line leaves the entire order untouched.
        $this->assertStock($available, 10);
        $this->assertStock($scarce, 1);
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame(0, $this->countRows('order_items'));
        self::assertSame(0, $this->countRows('stock_audit'));
    }

    public function testDatabaseFailureAfterWritesRollsBackStockAuditAndOrderThenAllowsRetry(): void
    {
        $product = $this->createProduct('rollback-write', 'Rollback Tote', 100000, 3);
        $lines = [['product_id' => $product, 'quantity' => 2, 'price_centavos' => 100000]];
        $this->database()->exec(
            "CREATE TRIGGER reject_item BEFORE INSERT ON order_items"
            . " BEGIN SELECT RAISE(ABORT, 'fixture failure after writes'); END"
        );
        try {
            $this->orders->placeOrder($lines, $this->shipping(), $this->payment(), 'retry-after-failure');
            self::fail('Expected the database to reject the item insert.');
        } catch (\PDOException) {
            $this->assertStock($product, 3);
            self::assertSame(0, $this->countRows('orders'));
            self::assertSame(0, $this->countRows('order_items'));
            self::assertSame(0, $this->countRows('stock_audit'));
        }
        $this->database()->exec('DROP TRIGGER reject_item');
        $order = $this->orders->placeOrder($lines, $this->shipping(), $this->payment(), 'retry-after-failure');
        self::assertSame('paid', $order['status']);
        $this->assertStock($product, 1);
        self::assertSame(1, $this->countRows('orders'));
        self::assertSame(1, $this->countRows('order_items'));
        self::assertSame(1, $this->countRows('stock_audit'));
    }

    public function testRejectsAChangedPriceAndPlacesNothing(): void
    {
        $product = $this->createProduct('tote-c', 'Lupa Satchel', 100000, 10);

        try {
            $this->orders->placeOrder(
                [['product_id' => $product, 'quantity' => 1, 'price_centavos' => 99999]],
                $this->shipping(),
                $this->payment(),
                'key-price',
            );
            self::fail('Expected a changed-price CheckoutException.');
        } catch (CheckoutException $exception) {
            self::assertSame(CheckoutException::REASON_CHANGED, $exception->reason);
        }

        $this->assertStock($product, 10);
        self::assertSame(0, $this->countRows('orders'));
    }

    public function testRejectsAnInactiveProductAndPlacesNothing(): void
    {
        $product = $this->createProduct('tote-d', 'Sierra Satchel', 100000, 10, active: 0);

        try {
            $this->orders->placeOrder(
                [['product_id' => $product, 'quantity' => 1, 'price_centavos' => 100000]],
                $this->shipping(),
                $this->payment(),
                'key-inactive',
            );
            self::fail('Expected an invalid CheckoutException.');
        } catch (CheckoutException $exception) {
            self::assertSame(CheckoutException::REASON_INVALID, $exception->reason);
        }

        $this->assertStock($product, 10);
        self::assertSame(0, $this->countRows('orders'));
    }

    public function testRejectsAnUnapprovedPaymentAndPlacesNothing(): void
    {
        $product = $this->createProduct('tote-e', 'Ulap Sling', 100000, 10);

        try {
            $this->orders->placeOrder(
                [['product_id' => $product, 'quantity' => 1, 'price_centavos' => 100000]],
                $this->shipping(),
                ['result' => 'declined', 'last4' => '0002', 'brand' => 'Visa'],
                'key-declined',
            );
            self::fail('Expected an invalid CheckoutException.');
        } catch (CheckoutException $exception) {
            self::assertSame(CheckoutException::REASON_INVALID, $exception->reason);
        }

        $this->assertStock($product, 10);
        self::assertSame(0, $this->countRows('orders'));
    }

    public function testRejectsPaymentDisplayFieldsThatCouldCarryAPan(): void
    {
        $product = $this->createProduct('tote-f', 'Gabi Clutch', 100000, 10);

        foreach (
            [
                ['result' => 'approved', 'last4' => '4242424242424242', 'brand' => 'Visa'],
                ['result' => 'approved', 'last4' => '42', 'brand' => 'Visa'],
                ['result' => 'approved', 'last4' => '4242', 'brand' => ''],
            ] as $payment
        ) {
            try {
                $this->orders->placeOrder(
                    [['product_id' => $product, 'quantity' => 1, 'price_centavos' => 100000]],
                    $this->shipping(),
                    $payment,
                    'key-payload-' . $payment['last4'],
                );
                self::fail('Expected an invalid CheckoutException.');
            } catch (CheckoutException $exception) {
                self::assertSame(CheckoutException::REASON_INVALID, $exception->reason);
            }
        }

        $this->assertStock($product, 10);
        self::assertSame(0, $this->countRows('orders'));
    }

    public function testRepeatSameKeyReturnsTheSameCommittedOrderOnce(): void
    {
        $product = $this->createProduct('tote-g', 'Araw Tote', 100000, 10);
        $lines = [['product_id' => $product, 'quantity' => 3, 'price_centavos' => 100000]];

        $first = $this->orders->placeOrder($lines, $this->shipping(), $this->payment(), 'key-repeat');
        $second = $this->orders->placeOrder($lines, $this->shipping(), $this->payment(), 'key-repeat');

        self::assertSame($first['id'], $second['id']);
        self::assertSame($first['order_number'], $second['order_number']);
        $this->assertStock($product, 7);
        self::assertSame(1, $this->countRows('orders'));
        self::assertSame(1, $this->countRows('stock_audit', 'product_id = ' . $product));
    }

    public function testCommittedRetryWinsOverEmptyOrChangedInput(): void
    {
        $product = $this->createProduct('tote-h', 'Dahon Sling', 100000, 10);

        $first = $this->orders->placeOrder(
            [['product_id' => $product, 'quantity' => 1, 'price_centavos' => 100000]],
            $this->shipping(),
            $this->payment(),
            'key-empty',
        );

        // A duplicated submission that lost its payload must still return
        // the committed order, not fail validation.
        $retry = $this->orders->placeOrder([], [], [], 'key-empty');

        self::assertSame($first['id'], $retry['id']);
        $this->assertStock($product, 9);
    }

    public function testOrderNumbersUseTheDailyFourDigitSequence(): void
    {
        $product = $this->createProduct('tote-i', 'Maya Clutch', 100000, 10);
        $lines = [['product_id' => $product, 'quantity' => 1, 'price_centavos' => 100000]];

        $first = $this->orders->placeOrder($lines, $this->shipping(), $this->payment(), 'key-seq-1');
        $second = $this->orders->placeOrder($lines, $this->shipping(), $this->payment(), 'key-seq-2');

        $prefix = 'H-' . date('Ymd') . '-';
        self::assertSame($prefix . '0001', $first['order_number']);
        self::assertSame($prefix . '0002', $second['order_number']);
    }

    public function testLookupsReturnItemSnapshotsAndNullForUnknowns(): void
    {
        $product = $this->createProduct('tote-j', 'Sinag Tote', 100000, 10);
        $order = $this->orders->placeOrder(
            [['product_id' => $product, 'quantity' => 2, 'price_centavos' => 100000]],
            $this->shipping(),
            $this->payment(),
            'key-lookup',
        );

        $byNumber = $this->orders->findByNumber((string) $order['order_number']);
        self::assertNotNull($byNumber);
        self::assertSame($order['id'], $byNumber['id']);
        self::assertCount(1, $byNumber['items']);
        self::assertSame('Sinag Tote', $byNumber['items'][0]['name_snapshot']);

        $byKey = $this->orders->findByKey('key-lookup');
        self::assertNotNull($byKey);
        self::assertSame($order['id'], $byKey['id']);

        self::assertNull($this->orders->findByNumber('H-19990101-9999'));
        self::assertNull($this->orders->findByKey('no-such-key'));
    }

    public function testDailySequenceFailsSafelyAtCapacity(): void
    {
        // Seed the day's full four-digit sequence, then refuse to widen
        // the format.
        $this->database()->prepare(
            'INSERT INTO orders (order_number, status, customer_name, customer_email,'
            . ' subtotal_centavos, total_centavos, idempotency_key)'
            . " VALUES (?, 'paid', 'Seed', 'seed@example.com', 0, 0, ?)"
        )->execute(['H-' . date('Ymd') . '-9999', 'seed-key']);

        $product = $this->createProduct('tote-k', 'Arko Tote', 100000, 10);

        try {
            $this->orders->placeOrder(
                [['product_id' => $product, 'quantity' => 1, 'price_centavos' => 100000]],
                $this->shipping(),
                $this->payment(),
                'key-capacity',
            );
            self::fail('Expected a capacity RuntimeException.');
        } catch (RuntimeException) {
            // Expected: the H-YYYYMMDD-NNNN format is never widened.
        }

        $this->assertStock($product, 10);
        self::assertSame(1, $this->countRows('orders'));
    }

    /**
     * @return array<string, string>
     */
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

    /**
     * @return array{result: string, last4: string, brand: string}
     */
    private function payment(): array
    {
        return ['result' => 'approved', 'last4' => '4242', 'brand' => 'Visa'];
    }

    private function createProduct(
        string $sku,
        string $name,
        int $priceCentavos,
        int $stock,
        int $active = 1,
    ): int {
        $insert = $this->database()->prepare(
            'INSERT INTO products (sku, slug, name, category, price_centavos, stock, is_active)'
            . " VALUES (?, ?, ?, 'Tote', ?, ?, ?)"
        );
        $insert->execute([$sku, $sku, $name, $priceCentavos, $stock, $active]);

        return (int) $this->database()->lastInsertId();
    }

    private function assertStock(int $productId, int $expectedStock): void
    {
        $statement = $this->database()->prepare('SELECT stock FROM products WHERE id = ?');
        $statement->execute([$productId]);

        self::assertSame($expectedStock, (int) $statement->fetchColumn());
    }

    private function assertAudit(int $productId, int $oldStock, int $newStock): void
    {
        $statement = $this->database()->prepare(
            'SELECT old_stock, new_stock, reason FROM stock_audit WHERE product_id = ?'
        );
        $statement->execute([$productId]);

        self::assertSame(
            [['old_stock' => $oldStock, 'new_stock' => $newStock, 'reason' => 'order_placed']],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function countRows(string $table, string $where = '1 = 1'): int
    {
        return (int) $this->database()
            ->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")
            ->fetchColumn();
    }
}
