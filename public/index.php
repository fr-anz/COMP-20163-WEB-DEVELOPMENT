<?php

declare(strict_types=1);

use Habi\Bootstrap\Initializer;
use Habi\Controllers\CartController;
use Habi\Controllers\CheckoutController;
use Habi\Controllers\StorefrontController;
use Habi\Middleware\ErrorHandler;
use Habi\Middleware\AdminAuthMiddleware;
use Habi\Middleware\CsrfMiddleware;
use Habi\Services\CartService;
use Habi\Services\ProductService;
use Habi\Services\OrderService;
use Habi\Services\PaymentSimulator;
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

$repoRoot = dirname(__DIR__);
Env::load($repoRoot . '/.env');

$psr17Factory = new Psr17Factory();
$app = new App($psr17Factory);

$twig = new TwigEnvironment(
    new FilesystemLoader($repoRoot . '/views'),
    ['autoescape' => 'html']
);


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
