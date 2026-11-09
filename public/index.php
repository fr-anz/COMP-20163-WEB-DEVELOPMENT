<?php

declare(strict_types=1);

use Habi\Bootstrap\Initializer;
use Habi\Middleware\ErrorHandler;
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

$app->get('/', function (
    ServerRequestInterface $request,
    ResponseInterface $response
) use ($twig): ResponseInterface {
    $response->getBody()->write($twig->render('storefront/home.twig', [
        'app_name' => 'Habi',
        'app_env' => (string) Env::get('APP_ENV', 'local'),
    ]));

    return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
});

$app->run();
