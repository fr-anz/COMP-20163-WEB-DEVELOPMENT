<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\CheckoutException;
use Habi\Services\OrderService;
use Habi\Services\PaymentProofService;
use Habi\Support\Env;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PDO;

/**
 * Boundary tests for checkout v2 placement (PRD R13, R15, R44, N4, N11;
 * SDD §15.4–15.6): COD, transfer with a staged proof and card orders are
 * placed as pending with the method's payment status, item weaver
 * snapshots and opening order events; transfer proofs move into the proof
 * store only when the order commits; and concurrent placements never
 * oversell for any method.
 */
final class PlacementV2Test extends IsolatedDatabaseTestCase
{
    private string $proofDirectory;
    private PaymentProofService $proofs;
    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->proofDirectory = sys_get_temp_dir() . '/habi-proofs-' . getmypid() . '-' . uniqid();
        $this->proofs = new PaymentProofService($this->proofDirectory);
        $this->orders = new OrderService($this->database(), $this->proofs);
    }

    protected function tearDown(): void
    {
        foreach ([$this->proofDirectory . '/tmp', $this->proofDirectory] as $directory) {
            foreach (glob($directory . '/*.*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
        parent::tearDown();
    }

    public function testCashOnDeliveryIsPendingUnpaidWithWeaverSnapshotsAndEvents(): void
    {
        $product = $this->insertProduct('cod-1', 'Sinag Tote', 150000, 4);
        $order = $this->place($product, 2, ['method' => 'cod'], 'key-cod');

        self::assertSame(
            ['pending', 'cod', 'unpaid'],
            [$order['status'], $order['payment_method'], $order['payment_status']]
        );
        self::assertNull($order['payment_last4']);
        self::assertNull($order['payment_brand']);
        self::assertNull($order['payment_result']);

        $weaver = $this->database()->query(
            "SELECT w.id, w.display_name FROM products p JOIN weavers w ON w.id = p.weaver_id WHERE p.id = {$product}"
        )->fetch();
        self::assertSame((int) $weaver['id'], (int) $order['items'][0]['weaver_id']);
        self::assertSame($weaver['display_name'], $order['items'][0]['weaver_name_snapshot']);
        self::assertSame(
            [
                ['field' => 'status', 'old_value' => null, 'new_value' => 'pending', 'actor_role' => 'customer'],
                ['field' => 'payment_status', 'old_value' => null, 'new_value' => 'unpaid', 'actor_role' => 'customer'],
            ],
            $this->events((int) $order['id'])
        );
        $this->assertStock($product, 2);
    }

    public function testTransferStoresTheProofOnlyWithTheCommittedOrder(): void
    {
        $product = $this->insertProduct('transfer-1', 'Lupa Satchel', 200000, 3);
        $staged = $this->proofs->stage($this->upload('png'));
        self::assertTrue($this->proofs->isStaged($staged));
        self::assertNull($this->proofs->locate($staged), 'A staged proof is not stored yet.');

        $order = $this->place(
            $product,
            1,
            ['method' => 'transfer', 'proof' => $staged, 'sender_reference' => '  GC-778812  '],
            'key-transfer'
        );

        self::assertSame(
            ['pending', 'transfer', 'for_verification'],
            [$order['status'], $order['payment_method'], $order['payment_status']]
        );
        $proof = $this->database()->query(
            'SELECT order_id, path, sender_reference FROM payment_proofs'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(
            [['order_id' => (int) $order['id'], 'path' => $staged, 'sender_reference' => 'GC-778812']],
            $proof
        );
        self::assertNotNull($this->proofs->locate($staged));
        self::assertFalse($this->proofs->isStaged($staged), 'The staged copy is released after commit.');
        self::assertSame('for_verification', $this->events((int) $order['id'])[1]['new_value']);
    }

    public function testTransferWithoutAStagedProofOrSenderReferencePlacesNothing(): void
    {
        $product = $this->insertProduct('transfer-2', 'Tala Sling', 90000, 3);
        $staged = $this->proofs->stage($this->upload('jpeg'));
        $attempts = [
            ['method' => 'transfer', 'sender_reference' => 'REF-1'],
            ['method' => 'transfer', 'proof' => 'deadbeef.png', 'sender_reference' => 'REF-1'],
            ['method' => 'transfer', 'proof' => '../' . $staged, 'sender_reference' => 'REF-1'],
            ['method' => 'transfer', 'proof' => $staged, 'sender_reference' => '   '],
            ['method' => 'transfer', 'proof' => $staged, 'sender_reference' => str_repeat('x', 65)],
            ['method' => 'wire'],
        ];
        foreach ($attempts as $index => $payment) {
            try {
                $this->place($product, 1, $payment, 'key-bad-' . $index);
                self::fail('Expected an invalid CheckoutException for attempt ' . $index);
            } catch (CheckoutException $failure) {
                self::assertSame(CheckoutException::REASON_INVALID, $failure->reason);
            }
        }
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame(0, $this->countRows('payment_proofs'));
        $this->assertStock($product, 3);
    }

    public function testTransferRollbackRemovesTheStoredCopyAndKeepsTheStagedProofForRetry(): void
    {
        $product = $this->insertProduct('transfer-3', 'Maya Clutch', 50000, 2);
        $staged = $this->proofs->stage($this->upload('webp'));
        $payment = ['method' => 'transfer', 'proof' => $staged, 'sender_reference' => 'BDO-1'];
        $this->database()->exec(
            'CREATE TRIGGER reject_proof BEFORE INSERT ON payment_proofs'
            . " BEGIN SELECT RAISE(ABORT, 'fixture failure'); END"
        );
        try {
            $this->place($product, 1, $payment, 'key-proof-retry');
            self::fail('Expected the proof insert to fail.');
        } catch (\PDOException) {
            self::assertNull($this->proofs->locate($staged));
            self::assertTrue($this->proofs->isStaged($staged));
            self::assertSame(0, $this->countRows('orders'));
            self::assertSame(0, $this->countRows('order_events'));
            $this->assertStock($product, 2);
        }
        $this->database()->exec('DROP TRIGGER reject_proof');

        $order = $this->place($product, 1, $payment, 'key-proof-retry');
        self::assertSame('for_verification', $order['payment_status']);
        self::assertNotNull($this->proofs->locate($staged));
        $this->assertStock($product, 1);
    }

    public function testApprovedCardIsPendingPaidWithOnlyLastFourAndBrand(): void
    {
        $product = $this->insertProduct('card-1', 'Card Tote', 100000, 2);
        $order = $this->place(
            $product,
            1,
            ['method' => 'card', 'result' => 'approved', 'last4' => '4242', 'brand' => 'Visa'],
            'key-card'
        );

        self::assertSame(
            ['pending', 'card', 'paid', 'approved', '4242', 'Visa'],
            [$order['status'], $order['payment_method'], $order['payment_status'],
                $order['payment_result'], $order['payment_last4'], $order['payment_brand']]
        );
        self::assertSame('paid', $this->events((int) $order['id'])[1]['new_value']);
    }

    public function testAnUnavailableBagCannotBePlaced(): void
    {
        $product = $this->insertProduct('off-1', 'Switched Off Tote', 100000, 2);
        $this->database()->exec("UPDATE products SET is_available = 0 WHERE id = {$product}");
        try {
            $this->place($product, 1, ['method' => 'cod'], 'key-off');
            self::fail('Expected an invalid CheckoutException.');
        } catch (CheckoutException $failure) {
            self::assertSame(CheckoutException::REASON_INVALID, $failure->reason);
        }
        self::assertSame(0, $this->countRows('orders'));
        $this->assertStock($product, 2);
    }

    public function testConcurrentLastItemPlacementsNeverOversellForAnyMethod(): void
    {
        $dsn = (string) Env::get('DB_DSN');
        foreach (['cod', 'transfer', 'card'] as $method) {
            $product = $this->insertProduct('race-' . $method, 'Race ' . $method, 70000, 1);
            $startAt = sprintf('%.6F', microtime(true) + 1.0);
            $processes = [];
            for ($worker = 0; $worker < 6; $worker++) {
                $proof = $method === 'transfer' ? $this->proofs->stage($this->upload('png')) : '-';
                $command = [
                    PHP_BINARY, habi_repo_root() . '/tests/TestSupport/place-order-worker.php',
                    $dsn, $this->proofDirectory, (string) $product, '70000', $method,
                    "race-{$method}-{$worker}", $proof, $startAt,
                ];
                $pipes = [];
                $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $results[] = trim((string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }
            sort($results);

            self::assertSame(['ok', 'stock', 'stock', 'stock', 'stock', 'stock'], $results, $method);
            $this->assertStock($product, 0);
            self::assertSame(1, $this->countRows('orders', "payment_method = '{$method}'"), $method);
            self::assertSame(1, $this->countRows('stock_audit', "product_id = {$product}"), $method);
        }
        self::assertSame(1, $this->countRows('payment_proofs'));
    }

    /**
     * @param array<string, mixed> $payment
     *
     * @return array<string, mixed>
     */
    private function place(int $product, int $quantity, array $payment, string $key): array
    {
        $price = (int) $this->database()->query("SELECT price_centavos FROM products WHERE id = {$product}")
            ->fetchColumn();

        return $this->orders->placeOrder(
            [['product_id' => $product, 'quantity' => $quantity, 'price_centavos' => $price]],
            [
                'name' => 'Maria Clara', 'email' => 'maria@example.com', 'phone' => '+63 917 123 4567',
                'address1' => '12 Katipunan Ave', 'address2' => '', 'city' => 'Quezon City',
                'postal_code' => '1108', 'region' => 'metro_manila',
            ],
            $payment,
            $key
        );
    }

    private function upload(string $type): UploadedFile
    {
        $bytes = (string) file_get_contents(habi_repo_root() . '/tests/Fixtures/images/tiny.' . $type);

        return new UploadedFile(
            Stream::create($bytes),
            strlen($bytes),
            UPLOAD_ERR_OK,
            'receipt.' . $type,
            'image/' . $type
        );
    }

    /**
     * @return list<array{field: string, old_value: ?string, new_value: string, actor_role: string}>
     */
    private function events(int $orderId): array
    {
        return $this->database()->query(
            "SELECT field, old_value, new_value, actor_role FROM order_events WHERE order_id = {$orderId} ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertStock(int $productId, int $expected): void
    {
        self::assertSame(
            $expected,
            (int) $this->database()->query("SELECT stock FROM products WHERE id = {$productId}")->fetchColumn()
        );
    }

    private function countRows(string $table, string $where = '1 = 1'): int
    {
        return (int) $this->database()->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
    }
}
