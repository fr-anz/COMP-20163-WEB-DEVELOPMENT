<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Controllers\CheckoutController;
use Habi\Services\CartService;
use Habi\Services\OrderService;
use Habi\Services\PaymentProofService;
use Habi\Services\PaymentSimulator;
use Habi\Support\SessionStore;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use Habi\Tests\TestSupport\Views;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;

/**
 * Controller boundary tests for the checkout v2 payment step (PRD R13,
 * R16, N11; SDD §15.5): COD and transfer choices reach Review and place
 * pending orders, a bad proof file or missing sender reference stays on
 * the payment step with inline errors and no order, and changing the
 * method revokes the issued idempotency key and the staged proof.
 */
final class CheckoutV2FlowTest extends IsolatedDatabaseTestCase
{
    private array $session = [];
    private CartService $cart;
    private CheckoutController $checkout;
    private Psr17Factory $http;
    private string $proofDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->proofDirectory = sys_get_temp_dir() . '/habi-flow-proofs-' . getmypid() . '-' . uniqid();
        $proofs = new PaymentProofService($this->proofDirectory);
        $store = new SessionStore($this->session);
        $this->cart = new CartService($this->database(), $store);
        $this->http = new Psr17Factory();
        $this->checkout = new CheckoutController(
            Views::twig(),
            $this->cart,
            $store,
            new OrderService($this->database(), $proofs),
            new PaymentSimulator(),
            $proofs
        );
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

    public function testCashOnDeliveryPlacesAPendingUnpaidOrderAndTheConfirmationShowsIt(): void
    {
        $this->cart->add(1, 1);
        $this->post('saveShipping', $this->shipping());
        self::assertSame('/checkout/review', $this->location('submitPayment', ['method' => 'cod']));
        self::assertStringContainsString('Cash on delivery', (string) $this->get('review')->getBody());

        $placed = $this->post('place', ['idempotency_key' => $this->session['checkout']['idempotency_key']]);
        $number = substr($placed->getHeaderLine('Location'), strlen('/order/'));
        $order = $this->database()->query("SELECT status, payment_method, payment_status FROM orders")->fetch();
        self::assertSame(['status' => 'pending', 'payment_method' => 'cod', 'payment_status' => 'unpaid'], $order);

        $page = (string) $this->checkout->confirmation(
            $this->http->createServerRequest('GET', '/order/' . $number),
            $this->http->createResponse(),
            ['orderNumber' => $number]
        )->getBody();
        foreach (['Cash on delivery', 'Unpaid', 'value="' . $number . '"', 'href="/track"'] as $expected) {
            self::assertStringContainsString($expected, $page);
        }
        foreach (['simulat', 'demo', 'Simulat', 'Demo'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $page);
        }
    }

    public function testBadProofOrMissingReferenceStaysOnPaymentWithInlineErrorsAndNoOrder(): void
    {
        $this->cart->add(1, 1);
        $this->post('saveShipping', $this->shipping());
        $response = $this->post(
            'submitPayment',
            ['method' => 'transfer', 'sender_reference' => 'GC-1234'],
            ['proof' => $this->file('<?php echo 1;', 'receipt.png')]
        );
        self::assertSame('/checkout/payment', $response->getHeaderLine('Location'));
        $page = $this->get('payment');
        self::assertSame(422, $page->getStatusCode());
        $html = (string) $page->getBody();
        self::assertStringContainsString('Upload a JPG, PNG or WebP image.', $html);
        self::assertStringContainsString('value="GC-1234"', $html);
        self::assertMatchesRegularExpression('/id="pm-transfer"[^>]*checked/', $html);

        $this->post('submitPayment', ['method' => 'transfer', 'sender_reference' => ' '], ['proof' => $this->image()]);
        self::assertStringContainsString(
            'Enter the reference number from your transfer receipt.',
            (string) $this->get('payment')->getBody()
        );
        self::assertSame([], glob($this->proofDirectory . '/tmp/*') ?: [], 'Refused attempts keep no file.');
        self::assertSame(0, (int) $this->database()->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame('/checkout/payment', $this->get('review')->getHeaderLine('Location'));
    }

    public function testTransferPlacesAForVerificationOrderWithItsProof(): void
    {
        $this->cart->add(1, 1);
        $this->post('saveShipping', $this->shipping());
        $response = $this->post(
            'submitPayment',
            ['method' => 'transfer', 'sender_reference' => 'GC-778812'],
            ['proof' => $this->image()]
        );
        self::assertSame('/checkout/review', $response->getHeaderLine('Location'));
        self::assertStringContainsString('reference GC-778812', (string) $this->get('review')->getBody());

        $this->post('place', ['idempotency_key' => $this->session['checkout']['idempotency_key']]);
        $order = $this->database()->query(
            'SELECT o.payment_method, o.payment_status, p.path, p.sender_reference
             FROM orders o JOIN payment_proofs p ON p.order_id = o.id'
        )->fetch();
        self::assertSame(['transfer', 'for_verification', 'GC-778812'], [
            $order['payment_method'], $order['payment_status'], $order['sender_reference'],
        ]);
        self::assertFileExists($this->proofDirectory . '/' . $order['path']);
        self::assertSame([], glob($this->proofDirectory . '/tmp/*') ?: []);
    }

    public function testChangingTheMethodRevokesTheKeyAndReleasesTheStagedProof(): void
    {
        $this->cart->add(1, 1);
        $this->post('saveShipping', $this->shipping());
        $this->post(
            'submitPayment',
            ['method' => 'transfer', 'sender_reference' => 'BDO-1'],
            ['proof' => $this->image()]
        );
        $this->get('review');
        $key = $this->session['checkout']['idempotency_key'];
        self::assertCount(1, glob($this->proofDirectory . '/tmp/*') ?: []);

        $this->post('submitPayment', ['method' => 'cod']);
        self::assertArrayNotHasKey('idempotency_key', $this->session['checkout']);
        self::assertSame([], glob($this->proofDirectory . '/tmp/*') ?: []);
        self::assertSame('/checkout/review', $this->location('place', ['idempotency_key' => $key]));
        self::assertSame(0, (int) $this->database()->query('SELECT COUNT(*) FROM orders')->fetchColumn());

        self::assertSame('/checkout/payment', $this->location('submitPayment', ['method' => 'cheque']));
        self::assertStringContainsString(
            'Choose how you would like to pay.',
            (string) $this->get('payment')->getBody()
        );
    }

    private function shipping(): array
    {
        return [
            'name' => 'Test Buyer', 'email' => 'buyer@example.test', 'phone' => '09171234567',
            'address1' => '123 Sample Street', 'address2' => '', 'city' => 'Quezon City',
            'postal_code' => '1100', 'region' => 'metro_manila',
        ];
    }

    private function image(): UploadedFile
    {
        return $this->file((string) file_get_contents(habi_repo_root() . '/tests/Fixtures/images/tiny.png'), 'r.png');
    }

    private function file(string $bytes, string $name): UploadedFile
    {
        return new UploadedFile(Stream::create($bytes), strlen($bytes), UPLOAD_ERR_OK, $name, 'image/png');
    }

    private function get(string $action): ResponseInterface
    {
        return $this->checkout->$action(
            $this->http->createServerRequest('GET', '/checkout'),
            $this->http->createResponse()
        );
    }

    private function location(string $action, array $body): string
    {
        return $this->post($action, $body)->getHeaderLine('Location');
    }

    private function post(string $action, array $body, array $files = []): ResponseInterface
    {
        return $this->checkout->$action(
            $this->http->createServerRequest('POST', '/checkout')->withParsedBody($body)->withUploadedFiles($files),
            $this->http->createResponse()
        );
    }
}
