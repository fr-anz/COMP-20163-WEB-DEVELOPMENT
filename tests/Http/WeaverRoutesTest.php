<?php

declare(strict_types=1);

namespace Habi\Tests\Http;

use Habi\Bootstrap\Initializer;
use Habi\Controllers\WeaverController;
use Habi\Controllers\WeaverProductController;
use Habi\Controllers\WeaverResponder;
use Habi\Controllers\WeaverRoutes;
use Habi\Middleware\WeaverAuthMiddleware;
use Habi\Services\AuthService;
use Habi\Services\EarningsService;
use Habi\Services\ImageUploadService;
use Habi\Services\StockService;
use Habi\Services\WeaverOrderService;
use Habi\Services\WeaverProductService;
use Habi\Services\WeaverService;
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
 * Every weaver route through the real middleware, routes, controllers,
 * services and templates (N10, R34–R39): another weaver's product or order
 * is answered 404 (never 403) without changes, list pages show only the
 * signed-in weaver's rows, signed-out, admin-only and deactivated sessions
 * go to the weaver sign-in, and every page is noindex (R26).
 */
final class WeaverRoutesTest extends IsolatedDatabaseTestCase
{
    use WeaverFixtures;

    /** Every weaver route (method + pattern); a new route must be added here and tested. */
    private const ROUTES = [
        'GET /weaver/login',
        'POST /weaver/login',
        'POST /weaver/logout',
        'GET /weaver',
        'GET /weaver/profile',
        'POST /weaver/profile',
        'GET /weaver/products',
        'GET /weaver/products/new',
        'POST /weaver/products',
        'GET /weaver/products/{id:[0-9]+}/edit',
        'POST /weaver/products/{id:[0-9]+}',
        'GET /weaver/products/{id:[0-9]+}/archive',
        'POST /weaver/products/{id:[0-9]+}/archive',
        'POST /weaver/products/{id:[0-9]+}/availability',
        'POST /weaver/products/{id:[0-9]+}/stock',
        'GET /weaver/stock',
        'GET /weaver/stock/{id:[0-9]+}/adjust',
        'GET /weaver/stock/{id:[0-9]+}/review',
        'GET /weaver/orders',
        'GET /weaver/orders/{number}',
        'GET /weaver/earnings',
    ];

    /** @var array<string, mixed> */
    private array $session = [];
    private Psr17Factory $factory;
    private App $app;
    private string $uploadDir;
    private int $lita;
    private int $rosa;
    private int $litaBag;
    private int $rosaBag;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->database();
        Initializer::initialize($pdo, $this->migrationsDir());
        $this->lita = $this->insertWeaver('lita', 'lita-password');
        $this->rosa = $this->insertWeaver('rosa', 'rosa-password');
        $this->litaBag = $this->insertWeaverProduct($this->lita, 'LITA-OWN', 2);
        $this->rosaBag = $this->insertWeaverProduct($this->rosa, 'ROSA-OWN', 2);
        $this->insertWeaverOrder('H-20261120-0001', [[$this->litaBag, $this->lita, 1, 150000]], 'delivered', 'paid');
        $this->insertWeaverOrder('H-20261120-0002', [[$this->rosaBag, $this->rosa, 1, 150000]], 'delivered', 'paid');

        $this->uploadDir = sys_get_temp_dir() . '/habi-weaver-uploads-' . getmypid() . '-' . uniqid();
        $this->factory = new Psr17Factory();
        $this->app = new App($this->factory);
        $store = fn (): SessionStore => new SessionStore($this->session);
        $weavers = static fn (): WeaverService => new WeaverService($pdo);
        $this->app->add(new WeaverAuthMiddleware(
            $this->factory,
            $store,
            static fn (int $id): ?array => $weavers()->findActive($id)
        ));
        $twig = new Environment(new FilesystemLoader(habi_repo_root() . '/views'), ['autoescape' => 'html']);
        $uploads = new ImageUploadService($this->uploadDir);
        WeaverRoutes::register(
            $this->app,
            static fn (): WeaverController => new WeaverController(
                new WeaverResponder($twig, $store()),
                $store(),
                new AuthService($pdo),
                $weavers(),
                new WeaverProductService($pdo),
                new WeaverOrderService($pdo),
                new EarningsService($pdo),
                $uploads,
                static function (): void {
                }
            ),
            static fn (): WeaverProductController => new WeaverProductController(
                new WeaverResponder($twig, $store()),
                new WeaverProductService($pdo),
                new StockService($pdo),
                $uploads
            )
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->uploadDir);
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
        sort($registered);
        $expected = self::ROUTES;
        sort($expected);

        self::assertSame($expected, $registered);
    }

    /** @return array<string, array{string, string, array<string, string>}> */
    public static function foreignResourceRoutes(): array
    {
        $stock = ['expected_stock' => '2', 'change' => '5', 'reason' => 'Restock received', 'note' => ''];

        return [
            'edit form' => ['GET', '/weaver/products/{rosaBag}/edit', []],
            'update' => ['POST', '/weaver/products/{rosaBag}', ['name' => 'Taken over']],
            'archive form' => ['GET', '/weaver/products/{rosaBag}/archive', []],
            'archive' => ['POST', '/weaver/products/{rosaBag}/archive', []],
            'availability' => ['POST', '/weaver/products/{rosaBag}/availability', ['available' => '0']],
            'stock change' => ['POST', '/weaver/products/{rosaBag}/stock', $stock],
            'adjust form' => ['GET', '/weaver/stock/{rosaBag}/adjust', []],
            'review' => ['GET', '/weaver/stock/{rosaBag}/review?change=5&reason=Restock+received', []],
            'order detail' => ['GET', '/weaver/orders/H-20261120-0002', []],
            'missing product' => ['GET', '/weaver/products/999999/edit', []],
            'missing order' => ['GET', '/weaver/orders/H-29991231-9999', []],
        ];
    }

    /** @param array<string, string> $body */
    #[DataProvider('foreignResourceRoutes')]
    public function testAnotherWeaversResourceIsNotFoundAndUnchanged(string $method, string $path, array $body): void
    {
        $this->signIn($this->lita);
        $before = $this->productRow($this->rosaBag);
        $audit = $this->auditCount();

        $response = $this->send($method, str_replace('{rosaBag}', (string) $this->rosaBag, $path), $body);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('ROSA-OWN', (string) $response->getBody());
        self::assertSame($before, $this->productRow($this->rosaBag));
        self::assertSame($audit, $this->auditCount());
    }

    /** @return array<string, array{string, string}> */
    public static function protectedRoutes(): array
    {
        $routes = [];
        foreach (self::ROUTES as $route) {
            [$method, $pattern] = explode(' ', $route, 2);
            if ($pattern === '/weaver/login') {
                continue;
            }
            $path = str_replace(['{id:[0-9]+}', '{number}'], ['1', 'H-20261120-0001'], $pattern);
            $routes[$route] = [$method, $path];
        }

        return $routes;
    }

    #[DataProvider('protectedRoutes')]
    public function testSignedOutAdminOnlyAndDeactivatedSessionsGoToWeaverSignIn(string $method, string $path): void
    {
        $deactivated = $this->insertWeaver('gone', 'x', false);
        foreach ([[], ['admin_id' => 1], ['weaver_id' => $deactivated]] as $session) {
            $this->session = $session;
            $response = $this->send($method, $path);
            self::assertSame(303, $response->getStatusCode(), $method . ' ' . $path);
            self::assertSame('/weaver/login', $response->getHeaderLine('Location'));
            self::assertArrayNotHasKey('weaver_id', $this->session);
        }
    }

    public function testListPagesShowOnlyOwnRowsAndAreNoindex(): void
    {
        $this->signIn($this->lita);
        $pages = ['/weaver', '/weaver/products', '/weaver/stock', '/weaver/orders', '/weaver/earnings',
            '/weaver/profile', '/weaver/products/new'];
        foreach ($pages as $path) {
            $response = $this->send('GET', $path);
            $html = (string) $response->getBody();
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html, $path);
            self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'), $path);
            self::assertStringNotContainsString('ROSA-OWN', $html, $path);
            self::assertStringNotContainsString('H-20261120-0002', $html, $path);
            self::assertStringNotContainsString('buyer@example.test', $html, $path);
        }
        self::assertStringContainsString('Bag LITA-OWN', (string) $this->send('GET', '/weaver/products')->getBody());
        self::assertStringContainsString('H-20261120-0001', (string) $this->send('GET', '/weaver/orders')->getBody());
        self::assertStringContainsString('Lita Weaver', (string) $this->send('GET', '/weaver/profile')->getBody());
    }

    public function testOwnResourcesWork(): void
    {
        $this->signIn($this->lita);
        $id = (string) $this->litaBag;

        self::assertSame(200, $this->send('GET', '/weaver/products/' . $id . '/edit')->getStatusCode());
        self::assertSame(200, $this->send('GET', '/weaver/products/' . $id . '/archive')->getStatusCode());
        self::assertSame(200, $this->send('GET', '/weaver/stock/' . $id . '/adjust')->getStatusCode());
        $review = $this->send('GET', '/weaver/stock/' . $id . '/review?change=3&reason=Restock+received');
        self::assertSame(200, $review->getStatusCode());
        self::assertStringContainsString('Confirm stock change', (string) $review->getBody());

        $order = $this->send('GET', '/weaver/orders/H-20261120-0001');
        self::assertSame(200, $order->getStatusCode());
        self::assertStringContainsString('Tacloban', (string) $order->getBody());
        self::assertStringNotContainsString('Maria Buyer', (string) $order->getBody());
        self::assertStringNotContainsString('12 Secret Street', (string) $order->getBody());

        $stock = $this->send('POST', '/weaver/products/' . $id . '/stock', [
            'expected_stock' => '2', 'change' => '3', 'reason' => 'Restock received', 'note' => 'new batch',
        ]);
        self::assertSame(303, $stock->getStatusCode());
        self::assertSame(5, (int) $this->productRow($this->litaBag)['stock']);
        $audit = $this->database()->query(
            'SELECT weaver_id, admin_id FROM stock_audit WHERE product_id = ' . $id . ' ORDER BY id DESC LIMIT 1'
        )->fetch();
        self::assertSame($this->lita, (int) $audit['weaver_id']);
        self::assertNull($audit['admin_id']);

        $this->send('POST', '/weaver/products/' . $id . '/availability', ['available' => '0']);
        self::assertSame(0, (int) $this->productRow($this->litaBag)['is_available']);

        $update = $this->send('POST', '/weaver/products/' . $id, $this->listingInput(['name' => 'Lita Renamed']));
        self::assertSame(303, $update->getStatusCode());
        self::assertSame('/weaver/products', $update->getHeaderLine('Location'));
        self::assertSame('Lita Renamed', $this->productRow($this->litaBag)['name']);

        $this->send('POST', '/weaver/products/' . $id . '/archive');
        self::assertSame('archived', $this->productRow($this->litaBag)['listing_status']);

        $earnings = (string) $this->send('GET', '/weaver/earnings')->getBody();
        self::assertStringContainsString('100%', $earnings);
        self::assertStringContainsString('&#8369;1,500', $earnings);
    }

    public function testCreateAssignsTheSignedInWeaverAndPendingReview(): void
    {
        $this->signIn($this->lita);
        $file = $this->factory->createUploadedFile(
            $this->factory->createStreamFromFile(habi_repo_root() . '/tests/Fixtures/images/tiny.png'),
            null,
            UPLOAD_ERR_OK,
            'tiny.png',
            'image/png'
        );

        $response = $this->send(
            'POST',
            '/weaver/products',
            $this->listingInput(['weaver_id' => (string) $this->rosa, 'listing_status' => 'active']),
            ['image' => $file]
        );

        self::assertSame(303, $response->getStatusCode());
        $row = $this->database()->query("SELECT * FROM products WHERE name = 'Weaver New Tote'")->fetch();
        self::assertSame($this->lita, (int) $row['weaver_id']);
        self::assertSame('pending_review', $row['listing_status']);
        self::assertNull($row['three_config']);
        self::assertCount(1, glob($this->uploadDir . '/*.png') ?: []);

        // Without an image the listing is refused.
        $missing = $this->send('POST', '/weaver/products', $this->listingInput(['name' => 'No Image Tote']));
        self::assertSame('/weaver/products/new', $missing->getHeaderLine('Location'));
        self::assertSame(422, $this->send('GET', '/weaver/products/new')->getStatusCode());
    }

    public function testDeactivatedListingCannotBeChangedOrReactivated(): void
    {
        $this->signIn($this->lita);
        $id = $this->insertWeaverProduct($this->lita, 'LITA-OFF', 2, 'deactivated');
        $before = $this->productRow($id);

        $edit = (string) $this->send('GET', '/weaver/products/' . $id . '/edit')->getBody();
        self::assertStringContainsString('Deactivated', $edit);
        foreach (
            [
                ['/weaver/products/' . $id, $this->listingInput()],
                ['/weaver/products/' . $id . '/archive', []],
                ['/weaver/products/' . $id . '/availability', ['available' => '0']],
                [
                    '/weaver/products/' . $id . '/stock',
                    ['expected_stock' => '2', 'change' => '1', 'reason' => 'Restock received'],
                ],
            ] as [$path, $body]
        ) {
            self::assertSame(303, $this->send('POST', $path, $body)->getStatusCode(), $path);
        }
        self::assertSame($before, $this->productRow($id));
    }

    public function testProfileEditChangesOnlyTheSignedInWeaver(): void
    {
        $this->signIn($this->lita);
        $rosaBefore = $this->database()->query('SELECT * FROM weavers WHERE id = ' . $this->rosa)->fetch();

        $response = $this->send('POST', '/weaver/profile', [
            'id' => (string) $this->rosa,
            'email' => 'hijack@example.test',
            'display_name' => 'Nanay Lita',
            'community' => 'Basey, Samar',
            'region' => 'Eastern Visayas (Region VIII)',
            'fibers' => 'tikog',
            'techniques' => 'Banig plaiting',
            'story' => 'Weaving since childhood.',
        ]);

        self::assertSame(303, $response->getStatusCode());
        $lita = $this->database()->query('SELECT * FROM weavers WHERE id = ' . $this->lita)->fetch();
        self::assertSame('Nanay Lita', $lita['display_name']);
        self::assertSame('lita@weavers.test', $lita['email']);
        $rosaAfter = $this->database()->query('SELECT * FROM weavers WHERE id = ' . $this->rosa)->fetch();
        self::assertSame($rosaBefore, $rosaAfter);

        $invalid = $this->send('POST', '/weaver/profile', ['display_name' => '']);
        self::assertSame('/weaver/profile', $invalid->getHeaderLine('Location'));
        self::assertSame(422, $this->send('GET', '/weaver/profile')->getStatusCode());
    }

    public function testSignInIsGenericThrottledAndHoldsOneStaffRole(): void
    {
        self::assertSame(200, $this->send('GET', '/weaver/login')->getStatusCode());

        $this->session = ['admin_id' => 1, 'admin_email' => 'admin@example.test', 'csrf_token' => 'old'];
        $ok = $this->send('POST', '/weaver/login', ['email' => 'lita@weavers.test', 'password' => 'lita-password']);
        self::assertSame('/weaver', $ok->getHeaderLine('Location'));
        self::assertSame($this->lita, $this->session['weaver_id']);
        self::assertArrayNotHasKey('admin_id', $this->session);
        self::assertArrayNotHasKey('admin_email', $this->session);
        self::assertNotSame('old', $this->session['csrf_token']);
        self::assertSame('/weaver', $this->send('GET', '/weaver/login')->getHeaderLine('Location'));

        $token = $this->session['csrf_token'];
        $out = $this->send('POST', '/weaver/logout');
        self::assertSame('/weaver/login', $out->getHeaderLine('Location'));
        self::assertArrayNotHasKey('weaver_id', $this->session);
        self::assertNotSame($token, $this->session['csrf_token']);

        $this->insertWeaver('former', 'former-password', false);
        $messages = [];
        $failures = [
            ['lita@weavers.test', 'wrong'],
            ['nobody@weavers.test', 'x'],
            ['former@weavers.test', 'former-password'],
        ];
        foreach ($failures as [$email, $password]) {
            $this->send('POST', '/weaver/login', ['email' => $email, 'password' => $password]);
            $page = $this->send('GET', '/weaver/login');
            self::assertSame(422, $page->getStatusCode());
            preg_match('/role="alert">.*?<p[^>]*>([^<]+)</s', (string) $page->getBody(), $match);
            $messages[] = $match[1] ?? '';
            self::assertArrayNotHasKey('weaver_id', $this->session);
        }
        self::assertSame(['Invalid email or password.'], array_values(array_unique($messages)));

        for ($i = 0; $i < 5; $i++) {
            $this->send('POST', '/weaver/login', ['email' => 'rosa@weavers.test', 'password' => 'wrong']);
        }
        $locked = $this->send('GET', '/weaver/login');
        self::assertSame(429, $locked->getStatusCode());
        self::assertStringContainsString('Too many failed sign-in attempts', (string) $locked->getBody());
    }

    private function signIn(int $weaverId): void
    {
        $this->session = ['weaver_id' => $weaverId, 'weaver_email' => 'weaver@weavers.test'];
    }

    /**
     * @param array<string, string> $body
     * @param array<string, mixed> $files
     */
    private function send(string $method, string $path, array $body = [], array $files = []): ResponseInterface
    {
        $request = $this->factory->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '10.1.1.1']
        );
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return $this->app->handle($request->withQueryParams($query)->withParsedBody($body)->withUploadedFiles($files));
    }

    /** @return array<string, mixed> */
    private function productRow(int $id): array
    {
        return $this->database()->query('SELECT * FROM products WHERE id = ' . $id)->fetch();
    }

    private function auditCount(): int
    {
        return (int) $this->database()->query('SELECT COUNT(*) FROM stock_audit')->fetchColumn();
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function listingInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Weaver New Tote', 'category' => 'Tote', 'fiber' => 'tikog',
            'materials' => 'Tikog', 'place_of_make' => 'Basey, Samar', 'community' => 'Basey',
            'region' => 'Eastern Visayas (Region VIII)', 'technique' => 'Banig plaiting',
            'description' => 'Market tote.', 'story' => 'Woven at home.', 'price' => '1500',
            'stock' => '3', 'low_stock_threshold' => '1', 'width' => '30', 'height' => '25', 'depth' => '10',
        ], $overrides);
    }
}
