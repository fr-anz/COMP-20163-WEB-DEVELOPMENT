<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Controllers\CheckoutController;
use Habi\Services\CartService;
use Habi\Services\OrderService;
use Habi\Services\PaymentSimulator;
use Habi\Support\SessionStore;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class CheckoutFlowTest extends IsolatedDatabaseTestCase
{
    private array $session = [];
    private CartService $cart;
    private CheckoutController $checkout;
    private Psr17Factory $http;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $store = new SessionStore($this->session);
        $this->cart = new CartService($this->database(), $store);
        $this->http = new Psr17Factory();
        $this->checkout = new CheckoutController(
            new Environment(new FilesystemLoader(habi_repo_root() . '/views'), ['autoescape' => 'html']),
            $this->cart,
            $store,
            new OrderService($this->database()),
            new PaymentSimulator()
        );
    }

    public function testReviewAndPlaceCannotBypassShippingOrPayment(): void
    {
        $this->cart->add(1, 1);
        self::assertSame('/checkout', $this->get('review')->getHeaderLine('Location'));
        $shipping = $this->post('saveShipping', $this->shipping());
        self::assertSame('/checkout/payment', $shipping->getHeaderLine('Location'));
        self::assertSame('/checkout/payment', $this->get('review')->getHeaderLine('Location'));
        self::assertSame('/checkout/review', $this->post('place', [
            'idempotency_key' => 'unissued-key',
        ])->getHeaderLine('Location'));
        self::assertSame(0, (int) $this->database()->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testInvalidCardRetainsShippingAndCartWithoutPersistingCardData(): void
    {
        $this->cart->add(1, 1);
        $this->post('saveShipping', $this->shipping());
        $this->post('simulate', [
            'card_number' => '4242424242424241',
            'expiry' => '01/20',
            'cvc' => '8473',
        ]);
        $persisted = serialize($this->session);
        foreach (['4242424242424241', '01/20', '8473'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $persisted);
        }
        self::assertSame($this->shipping(), $this->session['checkout']['shipping']);
        self::assertSame(1, $this->cart->cart()['count']);
        self::assertSame(0, (int) $this->database()->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        $response = $this->get('payment');
        $document = new \DOMDocument();
        $document->loadHTML((string) $response->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $field = (new \DOMXPath($document))->query('//input[@name="card_number"]')->item(0);
        self::assertSame('true', $field->getAttribute('aria-invalid'));
        self::assertSame('', $field->getAttribute('value'));
    }

    public function testCartMutationRevokesApprovalButPreservesShipping(): void
    {
        $this->approve();
        $this->get('review');
        $oldKey = $this->session['checkout']['idempotency_key'];
        $this->cart->update(1, 2);
        self::assertSame('/checkout/payment', $this->get('review')->getHeaderLine('Location'));
        self::assertSame($this->shipping(), $this->session['checkout']['shipping']);
        self::assertSame('/checkout/review', $this->post('place', [
            'idempotency_key' => $oldKey,
        ])->getHeaderLine('Location'));
        self::assertSame(0, (int) $this->database()->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testCommittedRetryDoesNotConsumeANewCartAndOtherSessionCannotReadOrder(): void
    {
        $this->approve();
        $this->get('review');
        $key = $this->session['checkout']['idempotency_key'];
        $placed = $this->post('place', ['idempotency_key' => $key]);
        $location = $placed->getHeaderLine('Location');
        self::assertStringStartsWith('/order/H-', $location);
        $this->cart->add(2, 1);
        self::assertSame($location, $this->post('place', ['idempotency_key' => $key])->getHeaderLine('Location'));
        self::assertSame(1, $this->cart->cart()['count']);
        self::assertSame(1, (int) $this->database()->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        $number = substr($location, strlen('/order/'));
        $this->session = [];
        $response = $this->checkout->confirmation(
            $this->http->createServerRequest('GET', $location),
            $this->http->createResponse(),
            ['orderNumber' => $number]
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Test Buyer', (string) $response->getBody());
    }

    private function approve(): void
    {
        $this->cart->add(1, 1);
        $this->post('saveShipping', $this->shipping());
        $response = $this->post('simulate', [
            'card_number' => '4242 4242 4242 4242',
            'expiry' => (new \DateTimeImmutable('+1 year'))->format('m/y'),
            'cvc' => '123',
        ]);
        self::assertSame('/checkout/review', $response->getHeaderLine('Location'));
    }

    private function shipping(): array
    {
        return [
            'name' => 'Test Buyer', 'email' => 'buyer@example.test', 'phone' => '09171234567',
            'address1' => '123 Demo Street', 'address2' => '', 'city' => 'Quezon City',
            'postal_code' => '1100', 'region' => 'metro_manila',
        ];
    }

    private function get(string $action): ResponseInterface
    {
        return $this->checkout->$action(
            $this->http->createServerRequest('GET', '/checkout'),
            $this->http->createResponse()
        );
    }

    private function post(string $action, array $body): ResponseInterface
    {
        return $this->checkout->$action(
            $this->http->createServerRequest('POST', '/checkout')->withParsedBody($body),
            $this->http->createResponse()
        );
    }
}
