<?php

declare(strict_types=1);

use Habi\Bootstrap\Initializer;
use Habi\Controllers\AdminController;
use Habi\Controllers\AdminOrderController;
use Habi\Controllers\AdminProductController;
use Habi\Controllers\AdminResponder;
use Habi\Controllers\CartController;
use Habi\Controllers\CheckoutController;
use Habi\Controllers\StorefrontController;
use Habi\Controllers\UploadController;
use Habi\Middleware\ErrorHandler;
use Habi\Middleware\AdminAuthMiddleware;
use Habi\Middleware\CsrfMiddleware;
use Habi\Services\AdminProductService;
use Habi\Services\AuthService;
use Habi\Services\CartService;
use Habi\Services\ImageUploadService;
use Habi\Services\ProductService;
use Habi\Services\OrderService;
use Habi\Services\PaymentSimulator;
use Habi\Services\StockService;
use Habi\Support\Database;
use Habi\Support\Env;
use Habi\Support\Session;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

// PHP diagnostics go to the server log only: a displayed warning would leak
// paths and, by starting output early, turn error pages into HTTP 200.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// The PHP server sends dynamic HTML/JSON uncompressed; let zlib negotiate
// gzip (Vary: Accept-Encoding) so documents meet the N1 LCP budget on 4G.
ini_set('zlib.output_compression', '1');

$repoRoot = dirname(__DIR__);
Env::load($repoRoot . '/.env');

$psr17Factory = new Psr17Factory();
$app = new App($psr17Factory);

$twig = new TwigEnvironment(
    new FilesystemLoader($repoRoot . '/views'),
    ['autoescape' => 'html']
);

// Absolute base URL for canonical and Open Graph links: APP_URL when
// configured (trailing slash trimmed), otherwise derived from the request
// host with https for HTTPS, a TLS-terminating proxy or APP_ENV=production
// (the same rule as the session Secure flag), falling back to localhost.
$appUrl = rtrim((string) Env::get('APP_URL', ''), '/');
if ($appUrl === '') {
    $isHttps = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || Env::get('APP_ENV', 'local') === 'production';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $appUrl = ($isHttps ? 'https' : 'http') . '://' . ($host !== '' ? $host : 'localhost');
}
// Canonical path is the request path without its query string; listing
// filters in the query are intentionally dropped from canonical/og:url.
$canonicalPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$twig->addGlobal('site_url', $appUrl);
$twig->addGlobal('canonical_path', $canonicalPath);


// The controller is built inside each route handler so the database
// connection is only opened after the first-boot middleware has run.
$storefront = static function () use ($twig): StorefrontController {
    return new StorefrontController(
        $twig,
        new ProductService(Database::connection()),
        new CartService(Database::connection(), Session::store())
    );
};

$cart = static function () use ($twig): CartController {
    return new CartController(
        $twig,
        new CartService(Database::connection(), Session::store())
    );
};

$checkout = static function () use ($twig): CheckoutController {
    $session = Session::store();

    return new CheckoutController(
        $twig,
        new CartService(Database::connection(), $session),
        $session,
        new OrderService(Database::connection()),
        new PaymentSimulator()
    );
};

$uploads = static fn (): ImageUploadService => new ImageUploadService($repoRoot . '/data/uploads');
$adminView = static fn (): AdminResponder => new AdminResponder($twig, Session::store());
$adminProducts = static fn (): AdminProductService =>
    new AdminProductService(Database::connection(), $repoRoot . '/public');

$admin = static function () use ($adminView, $adminProducts): AdminController {
    return new AdminController(
        $adminView(),
        Session::store(),
        new AuthService(Database::connection()),
        $adminProducts(),
        new OrderService(Database::connection()),
        new StockService(Database::connection())
    );
};

$adminCatalog = static function () use ($adminView, $adminProducts, $uploads): AdminProductController {
    return new AdminProductController(
        $adminView(),
        $adminProducts(),
        new StockService(Database::connection()),
        $uploads()
    );
};

$adminOrders = static fn (): AdminOrderController =>
    new AdminOrderController($adminView(), new OrderService(Database::connection()));

// Slim executes middleware last-added first: response headers, error handler,
// database initialization, CSRF, administrator authorization, then routing.
$app->add(new AdminAuthMiddleware($psr17Factory));
$app->add(new CsrfMiddleware(
    $psr17Factory,
    static fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface =>
        $cart()->rejectCsrf($request, $response)
));
// First-boot initialization runs on every request but only acts when the
// schema is missing; it is transactional and safe under concurrent boots.
// A failure (for example missing admin settings on an empty database) is
// logged to stderr and answered with a generic public error page.
$app->add(function (
    ServerRequestInterface $request,
    RequestHandlerInterface $handler
) use (
    $repoRoot,
    $psr17Factory
): ResponseInterface {
    try {
        Initializer::initialize(Database::connection(), $repoRoot . '/migrations');
    } catch (Throwable $initFailure) {
        error_log('[habi] database initialization failed: ' . $initFailure->getMessage());

        $response = $psr17Factory->createResponse(500);
        $response->getBody()->write(ErrorHandler::page(500));

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    return $handler->handle($request);
});

$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setDefaultErrorHandler(new ErrorHandler($psr17Factory));

// All session-backed responses, including failed POSTs, must not be cached.
$app->add(function (
    ServerRequestInterface $request,
    RequestHandlerInterface $handler
): ResponseInterface {
    $response = $handler->handle($request);

    return $response
        ->withHeader('Cache-Control', 'no-store')
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

// Admin panel (M6, R18–R23). AdminAuthMiddleware redirects every /admin path
// except /admin/login to sign-in; CsrfMiddleware guards every POST.
$app->get('/admin/login', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $admin()->loginForm($request, $response));

$app->post('/admin/login', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $admin()->login($request, $response));

$app->post('/admin/logout', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $admin()->logout($request, $response));

$app->get('/admin', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $admin()->dashboard($request, $response));

$app->get('/admin/audit', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $admin()->audit($request, $response));

$app->get('/admin/products', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $adminCatalog()->index($request, $response));

$app->get('/admin/products/new', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $adminCatalog()->createForm($request, $response));

$app->post('/admin/products', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $adminCatalog()->create($request, $response));

$app->get('/admin/products/{id:[0-9]+}/edit', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $adminCatalog()->editForm($request, $response, $args));

$app->post('/admin/products/{id:[0-9]+}', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $adminCatalog()->update($request, $response, $args));

$app->get('/admin/products/{id:[0-9]+}/archive', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $adminCatalog()->archiveForm($request, $response, $args));

$app->post('/admin/products/{id:[0-9]+}/archive', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $adminCatalog()->archive($request, $response, $args));

$app->post('/admin/products/{id:[0-9]+}/restore', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $adminCatalog()->restore($request, $response, $args));

$app->post('/admin/products/{id:[0-9]+}/stock', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $adminCatalog()->adjust($request, $response, $args));

$app->get('/admin/inventory', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $adminCatalog()->inventory($request, $response));

$app->get('/admin/inventory/{id:[0-9]+}/adjust', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $adminCatalog()->adjustForm($request, $response, $args));

$app->get('/admin/inventory/{id:[0-9]+}/review', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $adminCatalog()->review($request, $response, $args));

$app->get('/admin/orders', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $adminOrders()->index($request, $response));

$app->get('/admin/orders/{number}', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $adminOrders()->show($request, $response, $args));

$app->get('/admin/orders/{number}/cancel', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $adminOrders()->cancelForm($request, $response, $args));

$app->post('/admin/orders/{number}/status', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $adminOrders()->updateStatus($request, $response, $args));

$app->post('/admin/orders/{number}/cancel', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $adminOrders()->cancel($request, $response, $args));

$app->get('/uploads/{name}', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => (new UploadController($uploads(), $psr17Factory))->show($request, $response, $args));

$app->get('/checkout', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $checkout()->shipping($request, $response));

$app->post('/checkout/shipping', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $checkout()->saveShipping($request, $response));

$app->get('/checkout/payment', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $checkout()->payment($request, $response));

$app->post('/api/payment/simulate', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $checkout()->simulate($request, $response));

$app->get('/checkout/review', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $checkout()->review($request, $response));

$app->post('/checkout/place', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $checkout()->place($request, $response));

$app->get('/order/{orderNumber}', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $checkout()->confirmation($request, $response, $args));

$app->get('/', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $storefront()->home($request, $response));

$app->get('/products', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $storefront()->products($request, $response));

$app->get('/product/{slug}', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $storefront()->product($request, $response, $args));

$app->get('/cart', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $cart()->show($request, $response));

$app->post('/cart/add', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $cart()->add($request, $response));

$app->post('/cart/update', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $cart()->update($request, $response));

$app->post('/cart/remove', fn (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface => $cart()->remove($request, $response));

$app->get('/api/product/{slug}/stock', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $storefront()->stock($request, $response, $args));

$app->get('/api/product/{slug}/3d', fn (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
): ResponseInterface => $storefront()->config3d($request, $response, $args));

$app->run();
