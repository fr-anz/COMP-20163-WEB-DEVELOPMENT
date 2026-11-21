<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteCollectorProxyInterface;

/**
 * Weaver module routes (M12, R34–R39, SDD §15.3). Registered from
 * public/index.php and from WeaverRoutesTest, so the tests exercise the
 * exact routes production serves. WeaverAuthMiddleware guards every path
 * except /weaver/login; CsrfMiddleware guards every POST. Controllers are
 * built per request so the database opens after first-boot initialization.
 */
final class WeaverRoutes
{
    /**
     * @param Closure(): WeaverController $account
     * @param Closure(): WeaverProductController $products
     */
    public static function register(RouteCollectorProxyInterface $app, Closure $account, Closure $products): void
    {
        $routes = [
            ['GET', '/weaver/login', $account, 'loginForm'],
            ['POST', '/weaver/login', $account, 'login'],
            ['POST', '/weaver/logout', $account, 'logout'],
            ['GET', '/weaver', $account, 'dashboard'],
            ['GET', '/weaver/profile', $account, 'profileForm'],
            ['POST', '/weaver/profile', $account, 'saveProfile'],
            ['GET', '/weaver/products', $products, 'index'],
            ['GET', '/weaver/products/new', $products, 'createForm'],
            ['POST', '/weaver/products', $products, 'create'],
            ['GET', '/weaver/products/{id:[0-9]+}/edit', $products, 'editForm'],
            ['POST', '/weaver/products/{id:[0-9]+}', $products, 'update'],
            ['GET', '/weaver/products/{id:[0-9]+}/archive', $products, 'archiveForm'],
            ['POST', '/weaver/products/{id:[0-9]+}/archive', $products, 'archive'],
            ['POST', '/weaver/products/{id:[0-9]+}/availability', $products, 'availability'],
            ['POST', '/weaver/products/{id:[0-9]+}/stock', $products, 'adjust'],
            ['GET', '/weaver/stock', $products, 'stockList'],
            ['GET', '/weaver/stock/{id:[0-9]+}/adjust', $products, 'adjustForm'],
            ['GET', '/weaver/stock/{id:[0-9]+}/review', $products, 'review'],
            ['GET', '/weaver/orders', $account, 'orders'],
            ['GET', '/weaver/orders/{number}', $account, 'order'],
            ['GET', '/weaver/earnings', $account, 'earnings'],
        ];
        foreach ($routes as [$method, $pattern, $controller, $action]) {
            $app->map([$method], $pattern, static fn (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args
            ): ResponseInterface => $controller()->$action($request, $response, $args));
        }
    }
}
