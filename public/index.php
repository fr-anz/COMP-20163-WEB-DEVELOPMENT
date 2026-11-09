<?php

declare(strict_types=1);

use Habi\Bootstrap\Initializer;
use Habi\Controllers\StorefrontController;
use Habi\Middleware\ErrorHandler;
use Habi\Services\ProductService;
use Habi\Support\Database;
use Habi\Support\Env;
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

// The controller is built inside each route handler so the database
// connection is only opened after the first-boot middleware has run.
$storefront = static function () use ($twig): StorefrontController {
    return new StorefrontController($twig, new ProductService(Database::connection()));
};

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
