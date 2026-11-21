<?php

declare(strict_types=1);

namespace Habi\Tests\Http;

use Habi\Bootstrap\Initializer;
use Habi\Controllers\CartController;
use Habi\Controllers\CheckoutController;
use Habi\Controllers\JournalController;
use Habi\Controllers\StorefrontController;
use Habi\Controllers\StorefrontResponder;
use Habi\Controllers\TrackController;
use Habi\Controllers\WeaversController;
use Habi\Services\CartService;
use Habi\Services\JournalService;
use Habi\Services\OrderService;
use Habi\Services\PaymentSimulator;
use Habi\Services\ProductService;
use Habi\Services\TrackingService;
use Habi\Services\WeaverDirectoryService;
use Habi\Support\SessionStore;
use Habi\Support\Translator;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use Habi\Tests\TestSupport\Views;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Twig\Environment;

/**
 * R33/R26/N9 end to end through the real controllers and templates: in
 * Filipino every storefront, checkout and tracking page renders with
 * <html lang="fil">, Filipino catalog words, Filipino validation messages
 * and none of the English UI phrases; strict mode (tests/bootstrap.php)
 * fails on any key missing from lang/fil.php.
 */
final class FilipinoPagesTest extends IsolatedDatabaseTestCase
{
    /** English UI phrases that must not survive in a Filipino page. */
    private const ENGLISH = ['Add to bag', 'The collection', 'Your bag', 'Track order', 'Meet the weavers',
        'Skip to main content', 'Secure checkout', 'Shipping details', 'Review your order',
        'Continue browsing', 'Sold out', 'Browse the collection', 'Payment method', 'Enter your', 'Choose a'];

    /** @var array<string, mixed> */
    private array $session = [];
    private Psr17Factory $http;
    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->http = new Psr17Factory();
        $this->twig = Views::twig();
        $this->twig->addGlobal('site_url', 'https://habi.test');
        $this->twig->addGlobal('canonical_path', '/');
        Translator::setCurrent(Translator::current()->withLocale('fil'));
    }

    protected function tearDown(): void
    {
        Translator::reset();
        parent::tearDown();
    }

    public function testStorefrontPagesRenderInFilipino(): void
    {
        $slug = (string) $this->database()->query("SELECT slug FROM products WHERE listing_status = 'active' LIMIT 1")
            ->fetchColumn();
        $weaver = (string) $this->database()->query(
            "SELECT slug FROM weavers WHERE is_active = 1 AND slug <> 'unassigned' ORDER BY id LIMIT 1"
        )->fetchColumn();
        $article = (string) $this->database()
            ->query("SELECT slug FROM journal_articles WHERE status = 'published' LIMIT 1")
            ->fetchColumn();
        $storefront = new StorefrontController($this->twig, new ProductService($this->database()), $this->cart());
        $responder = new StorefrontResponder($this->twig, $this->cart());
        $weavers = new WeaversController(
            $responder,
            new WeaverDirectoryService($this->database()),
            new ProductService($this->database())
        );
        $journal = new JournalController($responder, new JournalService($this->database()));

        $pages = [
            'home' => $storefront->home($this->get('/'), $this->http->createResponse()),
            'products' => $storefront->products(
                $this->get('/products', ['fiber' => 'abaca', 'sort' => 'price_asc']),
                $this->http->createResponse()
            ),
            'product' => $storefront->product($this->get('/product/' . $slug), $this->response(), ['slug' => $slug]),
            'weavers' => $weavers->index($this->get('/weavers'), $this->http->createResponse()),
            'weaver' => $weavers->show($this->get('/weavers/' . $weaver), $this->response(), ['slug' => $weaver]),
            'journal' => $journal->index($this->get('/journal'), $this->http->createResponse()),
            'article' => $journal->show($this->get('/journal/' . $article), $this->response(), ['slug' => $article]),
            'cart' => (new CartController($this->twig, $this->cart()))->show($this->get('/cart'), $this->response()),
            'track' => $this->track()->form($this->get('/track'), $this->http->createResponse()),
        ];
        foreach ($pages as $name => $response) {
            $this->assertFilipino($name, $response, 200);
        }
        $html = (string) $pages['products']->getBody();
        self::assertStringContainsString('Ang koleksiyon', $html);
        self::assertStringContainsString('>Abaka</option>', $html);
        self::assertStringContainsString('aria-pressed="true" title="Filipino"', $html);
        self::assertStringContainsString('"copied":"Nakopya"', $html, 'the JavaScript dictionary follows the locale');
        self::assertStringContainsString('Idagdag sa bag', (string) $pages['product']->getBody());
    }

    public function testCheckoutAndTrackingRenderInFilipino(): void
    {
        $this->cart()->add(1, 1);
        $checkout = new CheckoutController(
            $this->twig,
            $this->cart(),
            new SessionStore($this->session),
            new OrderService($this->database()),
            new PaymentSimulator()
        );

        $this->post($checkout, 'saveShipping', ['name' => '']);
        $shipping = $checkout->shipping($this->get('/checkout'), $this->http->createResponse());
        $this->assertFilipino('shipping errors', $shipping, 422);
        self::assertStringContainsString('Ilagay ang iyong buong pangalan.', (string) $shipping->getBody());

        $this->post($checkout, 'saveShipping', [
            'name' => 'Test Buyer', 'email' => 'buyer@example.test', 'phone' => '09171234567',
            'address1' => '123 Sample Street', 'city' => 'Quezon City', 'postal_code' => '1100',
            'region' => 'metro_manila',
        ]);
        $this->post($checkout, 'submitPayment', ['method' => 'transfer', 'sender_reference' => '']);
        $payment = $checkout->payment($this->get('/checkout/payment'), $this->http->createResponse());
        $this->assertFilipino('payment errors', $payment, 422);
        self::assertStringContainsString('Pumili kung paano mo gustong magbayad.', (string) $payment->getBody());

        $this->post($checkout, 'submitPayment', ['method' => 'card', 'card_number' => '1', 'expiry' => '']);
        $card = (string) $checkout->payment($this->get('/checkout/payment'), $this->http->createResponse())->getBody();
        self::assertStringContainsString('Maglagay ng tamang card number.', $card);

        $this->post($checkout, 'submitPayment', ['method' => 'cod']);
        $review = $checkout->review($this->get('/checkout/review'), $this->http->createResponse());
        $this->assertFilipino('review', $review, 200);
        $key = (string) ($this->session['checkout']['idempotency_key'] ?? '');
        $location = $this->post($checkout, 'place', ['idempotency_key' => $key])->getHeaderLine('Location');
        $number = substr($location, strlen('/order/'));
        $confirmation = $checkout->confirmation($this->get($location), $this->response(), ['orderNumber' => $number]);
        $this->assertFilipino('confirmation', $confirmation, 200);
        self::assertStringContainsString('Salamat — nailagay na ang iyong order', (string) $confirmation->getBody());

        $found = $this->track()->lookup(
            $this->http->createServerRequest('POST', '/track')
                ->withParsedBody(['reference' => $number, 'email' => 'buyer@example.test']),
            $this->http->createResponse()
        );
        $this->assertFilipino('tracking result', $found, 200);
        self::assertStringContainsString('Nailagay ang order', (string) $found->getBody());
        self::assertMatchesRegularExpression(
            '/(Ene|Peb|Mar|Abr|May|Hun|Hul|Ago|Set|Okt|Nob|Dis) 20\d\d/',
            (string) $found->getBody()
        );
    }

    private function assertFilipino(string $name, ResponseInterface $response, int $status): void
    {
        $html = (string) $response->getBody();
        self::assertSame($status, $response->getStatusCode(), $name);
        self::assertStringContainsString('<html lang="fil">', $html, $name);
        // Visible text and attribute values only: scripts, data and URLs may carry English words.
        $text = (string) preg_replace('#<script\b.*?</script>#s', '', $html);
        foreach (self::ENGLISH as $phrase) {
            self::assertStringNotContainsString($phrase, $text, "$name still shows \"$phrase\"");
        }
    }

    private function response(): ResponseInterface
    {
        return $this->http->createResponse();
    }

    private function cart(): CartService
    {
        return new CartService($this->database(), new SessionStore($this->session));
    }

    private function track(): TrackController
    {
        return new TrackController($this->twig, $this->cart(), new TrackingService($this->database()));
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->http->createServerRequest('GET', $path)->withQueryParams($query);
    }

    /** @param array<string, mixed> $body */
    private function post(CheckoutController $checkout, string $action, array $body): ResponseInterface
    {
        return $checkout->$action(
            $this->http->createServerRequest('POST', '/checkout')->withParsedBody($body),
            $this->http->createResponse()
        );
    }
}
