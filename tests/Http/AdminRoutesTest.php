<?php

declare(strict_types=1);

namespace Habi\Tests\Http;

use Habi\Bootstrap\Initializer;
use Habi\Controllers\AdminCategoryController;
use Habi\Controllers\AdminCustomerController;
use Habi\Controllers\AdminJournalController;
use Habi\Controllers\AdminOrderController;
use Habi\Controllers\AdminProductController;
use Habi\Controllers\AdminResponder;
use Habi\Controllers\AdminRoutes;
use Habi\Controllers\AdminWeaverController;
use Habi\Middleware\AdminAuthMiddleware;
use Habi\Services\AdminJournalService;
use Habi\Services\AdminProductService;
use Habi\Services\AdminWeaverService;
use Habi\Services\AuthService;
use Habi\Services\CategoryService;
use Habi\Services\CustomerService;
use Habi\Services\ImageUploadService;
use Habi\Services\OrderService;
use Habi\Services\PaymentProofService;
use Habi\Services\StockService;
use Habi\Support\SessionStore;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use Habi\Tests\TestSupport\WeaverFixtures;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The M13 admin routes through the real middleware, routes, controllers,
 * services and templates: every route requires an administrator (signed
 * out and weaver-only sessions go to the admin sign-in); proofs are
 * streamed only to admins with nosniff/no-store and 404 otherwise (N11);
 * reasons are required; a stale form changes nothing; content with HTML is
 * refused and stored text is escaped; every admin screen renders.
 */
final class AdminRoutesTest extends IsolatedDatabaseTestCase
{
    use WeaverFixtures;

    /** Every AdminRoutes route; a new route must be added here and tested. */
    private const ROUTES = [
        'GET /admin/orders/{number}/reject',
        'POST /admin/orders/{number}/reject',
        'GET /admin/orders/{number}/refuse',
        'POST /admin/orders/{number}/payment',
        'GET /admin/orders/{number}/proof',
        'POST /admin/products/{id:[0-9]+}/approve',
        'GET /admin/products/{id:[0-9]+}/deactivate',
        'POST /admin/products/{id:[0-9]+}/deactivate',
        'GET /admin/weavers',
        'GET /admin/weavers/new',
        'POST /admin/weavers',
        'GET /admin/weavers/{id:[0-9]+}/edit',
        'POST /admin/weavers/{id:[0-9]+}',
        'GET /admin/weavers/{id:[0-9]+}/password',
        'POST /admin/weavers/{id:[0-9]+}/password',
        'GET /admin/weavers/{id:[0-9]+}/deactivate',
        'POST /admin/weavers/{id:[0-9]+}/deactivate',
        'POST /admin/weavers/{id:[0-9]+}/reactivate',
        'GET /admin/categories',
        'GET /admin/categories/new',
        'POST /admin/categories',
        'GET /admin/categories/{id:[0-9]+}/edit',
        'POST /admin/categories/{id:[0-9]+}',
        'POST /admin/categories/{id:[0-9]+}/deactivate',
        'POST /admin/categories/{id:[0-9]+}/reactivate',
        'GET /admin/customers',
        'GET /admin/customers/{key:[a-f0-9]{64}}',
        'GET /admin/journal',
        'GET /admin/journal/new',
        'POST /admin/journal',
        'GET /admin/journal/{id:[0-9]+}/edit',
        'POST /admin/journal/{id:[0-9]+}',
        'POST /admin/journal/{id:[0-9]+}/publish',
        'POST /admin/journal/{id:[0-9]+}/unpublish',
    ];

    /** @var array<string, mixed> */
    private array $session = [];
    private Psr17Factory $factory;
    private App $app;
    private string $dataDir;
    private OrderService $orders;
    private int $adminId;
    private int $weaver;
    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->database();
        Initializer::initialize($pdo, $this->migrationsDir());
        $this->adminId = (int) $pdo->query('SELECT id FROM admins LIMIT 1')->fetchColumn();
        $this->weaver = $this->insertWeaver('lita', 'lita-password');
        $this->product = $this->insertWeaverProduct($this->weaver, 'LITA-BAG', 5);

        $this->dataDir = sys_get_temp_dir() . '/habi-admin-routes-' . getmypid() . '-' . uniqid();
        $proofs = new PaymentProofService($this->dataDir . '/proofs');
        $uploads = new ImageUploadService($this->dataDir . '/uploads');
        $this->orders = new OrderService($pdo, $proofs);
        $this->factory = new Psr17Factory();
        $this->app = new App($this->factory);
        $store = fn (): SessionStore => new SessionStore($this->session);
        $this->app->add(new AdminAuthMiddleware($this->factory, $store));
        $twig = new Environment(new FilesystemLoader(habi_repo_root() . '/views'), ['autoescape' => 'html']);
        $view = static fn (): AdminResponder => new AdminResponder($twig, $store());
        $orders = fn (): AdminOrderController => new AdminOrderController(
            $view(),
            new OrderService($pdo),
            $proofs,
            $this->factory
        );
        AdminRoutes::register(
            $this->app,
            $orders,
            static fn (): AdminProductController => new AdminProductController(
                $view(),
                new AdminProductService($pdo, habi_repo_root() . '/public'),
                new StockService($pdo),
                $uploads
            ),
            static fn (): AdminWeaverController =>
                new AdminWeaverController($view(), new AdminWeaverService($pdo), $uploads),
            static fn (): AdminCategoryController => new AdminCategoryController($view(), new CategoryService($pdo)),
            static fn (): AdminCustomerController => new AdminCustomerController($view(), new CustomerService($pdo)),
            static fn (): AdminJournalController =>
                new AdminJournalController($view(), new AdminJournalService($pdo), $uploads)
        );
        // The M6 product list and order detail/status/cancel routes live in public/index.php.
        $catalog = static fn (): AdminProductController => new AdminProductController(
            $view(),
            new AdminProductService($pdo, habi_repo_root() . '/public'),
            new StockService($pdo),
            $uploads
        );
        $this->app->get('/admin/products', fn ($q, $r) => $catalog()->index($q, $r));
        $this->app->get('/admin/orders/{number}', fn ($q, $r, array $a) => $orders()->show($q, $r, $a));
        $this->app->get('/admin/orders/{number}/cancel', fn ($q, $r, array $a) => $orders()->cancelForm($q, $r, $a));
        $this->app->post('/admin/orders/{number}/status', fn ($q, $r, array $a) => $orders()->updateStatus($q, $r, $a));
        $this->app->post('/admin/orders/{number}/cancel', fn ($q, $r, array $a) => $orders()->cancel($q, $r, $a));
    }

    protected function tearDown(): void
    {
        foreach (['/proofs/tmp', '/proofs', '/uploads'] as $directory) {
            foreach (glob($this->dataDir . $directory . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->dataDir . $directory);
        }
        @rmdir($this->dataDir);
        parent::tearDown();
    }

    public function testTheRouteListIsExactlyTheTestedOne(): void
    {
        $registered = [];
        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $registered[] = $method . ' ' . $route->getPattern();
            }
        }
        $registered = array_values(array_diff($registered, [
            'GET /admin/products', 'GET /admin/orders/{number}', 'GET /admin/orders/{number}/cancel',
            'POST /admin/orders/{number}/status', 'POST /admin/orders/{number}/cancel',
        ]));
        sort($registered);
        $expected = self::ROUTES;
        sort($expected);

        self::assertSame($expected, $registered);
    }

    /** @return array<string, array{string, string}> */
    public static function routes(): array
    {
        $routes = [];
        foreach (self::ROUTES as $route) {
            [$method, $pattern] = explode(' ', $route, 2);
            $routes[$route] = [$method, str_replace(
                ['{number}', '{id:[0-9]+}', '{key:[a-f0-9]{64}}'],
                ['H-20261120-0001', '1', str_repeat('a', 64)],
                $pattern
            )];
        }

        return $routes;
    }

    #[DataProvider('routes')]
    public function testSignedOutAndWeaverSessionsGoToAdminSignIn(string $method, string $path): void
    {
        $before = $this->snapshot();
        foreach ([[], ['weaver_id' => $this->weaver, 'weaver_email' => 'lita@weavers.test']] as $session) {
            $this->session = $session;
            $response = $this->send($method, $path, ['reason' => 'x', 'action' => 'accept', 'name' => 'X']);
            self::assertSame(303, $response->getStatusCode(), $method . ' ' . $path);
            self::assertSame('/admin/login', $response->getHeaderLine('Location'));
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testProofIsStreamedOnlyToAdminsWithSafeHeaders(): void
    {
        $order = $this->place('transfer', 'proof-1');
        $path = '/admin/orders/' . $order['order_number'] . '/proof';
        $bytes = (string) file_get_contents(habi_repo_root() . '/tests/Fixtures/images/tiny.png');

        $this->session = [];
        self::assertSame(303, $this->send('GET', $path)->getStatusCode());
        $this->session = ['weaver_id' => $this->weaver];
        self::assertSame(303, $this->send('GET', $path)->getStatusCode());

        $this->signIn();
        $response = $this->send('GET', $path);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame($bytes, (string) $response->getBody());

        $cod = $this->place('cod', 'proof-2');
        self::assertSame(404, $this->send('GET', '/admin/orders/' . $cod['order_number'] . '/proof')->getStatusCode());
        self::assertSame(404, $this->send('GET', '/admin/orders/H-29991231-9999/proof')->getStatusCode());
        $stored = (string) $this->database()->query('SELECT path FROM payment_proofs')->fetchColumn();
        unlink($this->dataDir . '/proofs/' . $stored);
        self::assertSame(404, $this->send('GET', $path)->getStatusCode());
    }

    public function testTheOrderDetailShowsProofEventsGroupsAndOnlyAllowedActions(): void
    {
        $order = $this->place('transfer', 'detail-1');
        $this->signIn();

        $html = (string) $this->send('GET', '/admin/orders/' . $order['order_number'])->getBody();

        self::assertStringContainsString('/proof"', $html);
        self::assertStringContainsString('Lita Weaver', $html, 'Items are grouped under the weaver snapshot.');
        self::assertStringContainsString('Accept payment', $html);
        self::assertStringContainsString('Reject…', $html);
        self::assertStringNotContainsString('Confirm order', $html, 'An unpaid transfer cannot be confirmed.');
        self::assertStringContainsString('For verification', $html);
        self::assertStringContainsString('value="pending/for_verification"', $html);
    }

    public function testRejectNeedsAReasonThenRestoresStockThroughTheForm(): void
    {
        $order = $this->place('cod', 'reject-1');
        $number = (string) $order['order_number'];
        $this->signIn();

        $missing = $this->send('POST', '/admin/orders/' . $number . '/reject', [
            'reason' => ' ', 'expected' => 'pending/unpaid',
        ]);
        self::assertSame('/admin/orders/' . $number . '/reject', $missing->getHeaderLine('Location'));
        $dialog = $this->send('GET', '/admin/orders/' . $number . '/reject');
        self::assertSame(422, $dialog->getStatusCode());
        self::assertStringContainsString('Enter a reason', (string) $dialog->getBody());
        self::assertSame(4, $this->stock());

        $done = $this->send('POST', '/admin/orders/' . $number . '/reject', [
            'reason' => 'Out of delivery area', 'expected' => 'pending/unpaid',
        ]);
        self::assertSame('/admin/orders/' . $number, $done->getHeaderLine('Location'));
        self::assertSame(5, $this->stock());
        $html = (string) $this->send('GET', '/admin/orders/' . $number)->getBody();
        self::assertStringContainsString('Out of delivery area', $html);
        self::assertStringContainsString('was rejected and its stock restored', $html);
    }

    public function testAStalePaymentFormChangesNothing(): void
    {
        $order = $this->place('transfer', 'stale-1');
        $number = (string) $order['order_number'];
        $this->signIn();
        $events = (int) $this->database()->query('SELECT COUNT(*) FROM order_events')->fetchColumn();

        $payment = '/admin/orders/' . $number . '/payment';
        $this->send('POST', $payment, ['action' => 'accept', 'expected' => 'pending/paid']);

        self::assertSame('for_verification', $this->orders->findByNumber($number)['payment_status']);
        self::assertSame($events, (int) $this->database()->query('SELECT COUNT(*) FROM order_events')->fetchColumn());
        $detail = (string) $this->send('GET', '/admin/orders/' . $number)->getBody();
        self::assertStringContainsString('Review it again', $detail);

        $this->send('POST', $payment, ['action' => 'accept', 'expected' => 'pending/for_verification']);
        self::assertSame('paid', $this->orders->findByNumber($number)['payment_status']);
    }

    public function testCreatingAWeaverShowsAGeneratedPasswordOnceAndItSignsIn(): void
    {
        $this->signIn();
        $response = $this->send('POST', '/admin/weavers', [
            'email' => 'ana@weavers.test', 'display_name' => 'Ana', 'password' => '', 'story' => 'Plain story.',
        ]);
        self::assertSame('/admin/weavers', $response->getHeaderLine('Location'));

        $html = (string) $this->send('GET', '/admin/weavers')->getBody();
        self::assertSame(1, preg_match('/temporary password ([A-Za-z0-9]{16})/', $html, $match));
        self::assertSame(
            AuthService::STATUS_OK,
            (new AuthService($this->database()))->attemptWeaver('ana@weavers.test', $match[1], '10.0.0.1')['status']
        );
        self::assertStringNotContainsString($match[1], (string) $this->send('GET', '/admin/weavers')->getBody());
    }

    public function testRejectedWeaverFormNeverKeepsThePassword(): void
    {
        $this->signIn();
        $this->send('POST', '/admin/weavers', [
            'email' => 'bad', 'display_name' => 'Ana', 'password' => 'secret-password-1', 'story' => '<b>x</b>',
        ]);
        $form = $this->send('GET', '/admin/weavers/new');

        self::assertSame(422, $form->getStatusCode());
        self::assertStringNotContainsString('secret-password-1', (string) $form->getBody());
        self::assertStringNotContainsString('secret-password-1', serialize($this->session));
        self::assertStringContainsString('HTML tags are not allowed', (string) $form->getBody());
    }

    public function testApprovingAPendingListingMakesItActive(): void
    {
        $pending = $this->insertWeaverProduct($this->weaver, 'LITA-NEW', 2, 'pending_review');
        $this->signIn();

        $list = (string) $this->send('GET', '/admin/products?stock=pending')->getBody();
        self::assertStringContainsString('LITA-NEW', $list);
        self::assertStringContainsString('Pending review', $list);
        $this->send('POST', '/admin/products/' . $pending . '/approve');

        $status = $this->database()->query('SELECT listing_status FROM products WHERE id = ' . $pending);
        self::assertSame('active', $status->fetchColumn());
    }

    public function testStoredTextIsEscapedOnAdminScreens(): void
    {
        $this->database()->prepare(
            "INSERT INTO orders (order_number, status, payment_method, payment_status, customer_name, customer_email,
                 shipping_region, created_at, updated_at)
             VALUES ('H-20261120-0009', 'pending', 'cod', 'unpaid', '<script>x()</script>', 'x@example.test',
                 'luzon', datetime('now'), datetime('now'))"
        )->execute();
        $this->signIn();

        $html = (string) $this->send('GET', '/admin/customers')->getBody();

        self::assertStringNotContainsString('<script>x()</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /** @return array<string, array{string}> */
    public static function screens(): array
    {
        return [
            'weavers' => ['/admin/weavers'], 'weaver new' => ['/admin/weavers/new'],
            'weaver edit' => ['/admin/weavers/{weaver}/edit'], 'password' => ['/admin/weavers/{weaver}/password'],
            'deactivate weaver' => ['/admin/weavers/{weaver}/deactivate'], 'categories' => ['/admin/categories'],
            'category new' => ['/admin/categories/new'], 'category edit' => ['/admin/categories/1/edit'],
            'customers' => ['/admin/customers'], 'customer' => ['/admin/customers/{customer}'],
            'journal' => ['/admin/journal'], 'article new' => ['/admin/journal/new'],
            'article edit' => ['/admin/journal/1/edit'],
            'deactivate listing' => ['/admin/products/{product}/deactivate'],
            'order' => ['/admin/orders/{order}'], 'reject' => ['/admin/orders/{order}/reject'],
            'cancel' => ['/admin/orders/{confirmed}/cancel'], 'refuse' => ['/admin/orders/{order}/refuse'],
        ];
    }

    #[DataProvider('screens')]
    public function testEveryAdminScreenRendersForAnAdmin(string $path): void
    {
        $order = $this->place('transfer', 'screen-1');
        $confirmed = $this->place('cod', 'screen-2');
        $this->orders->advanceStatus((string) $confirmed['order_number'], 'confirmed', $this->adminId);
        $this->signIn();
        $path = strtr($path, [
            '{weaver}' => (string) $this->weaver, '{product}' => (string) $this->product,
            '{order}' => (string) $order['order_number'], '{confirmed}' => (string) $confirmed['order_number'],
            '{customer}' => CustomerService::key('maria@example.com'),
        ]);

        $response = $this->send('GET', $path);

        self::assertSame(200, $response->getStatusCode(), $path);
        $html = (string) $response->getBody();
        self::assertStringContainsString('<main id="main-content"', $html);
        self::assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
        if (str_contains($path, '/reject') || str_contains($path, '/refuse') || str_contains($path, '/cancel')) {
            self::assertStringContainsString('role="dialog"', $html);
            self::assertStringContainsString('name="reason"', $html);
        }
    }

    private function signIn(): void
    {
        $this->session = ['admin_id' => $this->adminId, 'admin_email' => 'admin@example.test'];
    }

    /** @return array<string, mixed> */
    private function place(string $method, string $key): array
    {
        $payment = ['method' => $method];
        if ($method === 'transfer') {
            $name = bin2hex(random_bytes(16)) . '.png';
            @mkdir($this->dataDir . '/proofs/tmp', 0770, true);
            copy(habi_repo_root() . '/tests/Fixtures/images/tiny.png', $this->dataDir . '/proofs/tmp/' . $name);
            $payment += ['proof' => $name, 'sender_reference' => 'GCASH-123'];
        }

        return $this->orders->placeOrder(
            [['product_id' => $this->product, 'quantity' => 1, 'price_centavos' => 150000]],
            [
                'name' => 'Maria Clara', 'email' => 'maria@example.com', 'phone' => '+63 917 123 4567',
                'address1' => '12 Katipunan Ave', 'address2' => '', 'city' => 'Quezon City',
                'postal_code' => '1108', 'region' => 'metro_manila',
            ],
            $payment,
            $key
        );
    }

    private function stock(): int
    {
        return (int) $this->database()->query('SELECT stock FROM products WHERE id = ' . $this->product)->fetchColumn();
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        $counts = [];
        foreach (['orders', 'order_events', 'stock_audit', 'weavers', 'categories', 'journal_articles'] as $table) {
            $counts[$table] = (int) $this->database()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }
        $counts['active'] = (int) $this->database()->query(
            "SELECT COUNT(*) FROM products WHERE listing_status = 'active'"
        )->fetchColumn();

        return $counts;
    }

    /** @param array<string, string> $body */
    private function send(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = $this->factory->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '10.1.1.1']
        );
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return $this->app->handle($request->withQueryParams($query)->withParsedBody($body));
    }
}
