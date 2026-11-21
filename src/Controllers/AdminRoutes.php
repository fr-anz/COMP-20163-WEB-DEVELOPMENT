<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteCollectorProxyInterface;

/**
 * Admin v2 routes (M13; R18–R23, R40–R44, N11; SDD §15.3), registered from
 * public/index.php and from AdminRoutesTest so the tests exercise the exact
 * routes production serves. The M6 admin routes stay in index.php.
 * AdminAuthMiddleware guards every /admin path except /admin/login;
 * CsrfMiddleware guards every POST. Controllers are built per request so
 * the database opens after first-boot initialization.
 */
final class AdminRoutes
{
    /**
     * @param Closure(): AdminOrderController $orders
     * @param Closure(): AdminProductController $products
     * @param Closure(): AdminWeaverController $weavers
     * @param Closure(): AdminCategoryController $categories
     * @param Closure(): AdminCustomerController $customers
     * @param Closure(): AdminJournalController $journal
     */
    public static function register(
        RouteCollectorProxyInterface $app,
        Closure $orders,
        Closure $products,
        Closure $weavers,
        Closure $categories,
        Closure $customers,
        Closure $journal,
    ): void {
        $routes = [
            ['GET', '/admin/orders/{number}/reject', $orders, 'rejectForm'],
            ['POST', '/admin/orders/{number}/reject', $orders, 'reject'],
            ['GET', '/admin/orders/{number}/refuse', $orders, 'refuseForm'],
            ['POST', '/admin/orders/{number}/payment', $orders, 'payment'],
            ['GET', '/admin/orders/{number}/proof', $orders, 'proof'],
            ['POST', '/admin/products/{id:[0-9]+}/approve', $products, 'approve'],
            ['GET', '/admin/products/{id:[0-9]+}/deactivate', $products, 'deactivateForm'],
            ['POST', '/admin/products/{id:[0-9]+}/deactivate', $products, 'deactivate'],
            ['GET', '/admin/weavers', $weavers, 'index'],
            ['GET', '/admin/weavers/new', $weavers, 'createForm'],
            ['POST', '/admin/weavers', $weavers, 'create'],
            ['GET', '/admin/weavers/{id:[0-9]+}/edit', $weavers, 'editForm'],
            ['POST', '/admin/weavers/{id:[0-9]+}', $weavers, 'update'],
            ['GET', '/admin/weavers/{id:[0-9]+}/password', $weavers, 'passwordForm'],
            ['POST', '/admin/weavers/{id:[0-9]+}/password', $weavers, 'setPassword'],
            ['GET', '/admin/weavers/{id:[0-9]+}/deactivate', $weavers, 'deactivateForm'],
            ['POST', '/admin/weavers/{id:[0-9]+}/deactivate', $weavers, 'deactivate'],
            ['POST', '/admin/weavers/{id:[0-9]+}/reactivate', $weavers, 'reactivate'],
            ['GET', '/admin/categories', $categories, 'index'],
            ['GET', '/admin/categories/new', $categories, 'createForm'],
            ['POST', '/admin/categories', $categories, 'create'],
            ['GET', '/admin/categories/{id:[0-9]+}/edit', $categories, 'editForm'],
            ['POST', '/admin/categories/{id:[0-9]+}', $categories, 'update'],
            ['POST', '/admin/categories/{id:[0-9]+}/deactivate', $categories, 'deactivate'],
            ['POST', '/admin/categories/{id:[0-9]+}/reactivate', $categories, 'reactivate'],
            ['GET', '/admin/customers', $customers, 'index'],
            ['GET', '/admin/customers/{key:[a-f0-9]{64}}', $customers, 'show'],
            ['GET', '/admin/journal', $journal, 'index'],
            ['GET', '/admin/journal/new', $journal, 'createForm'],
            ['POST', '/admin/journal', $journal, 'create'],
            ['GET', '/admin/journal/{id:[0-9]+}/edit', $journal, 'editForm'],
            ['POST', '/admin/journal/{id:[0-9]+}', $journal, 'update'],
            ['POST', '/admin/journal/{id:[0-9]+}/publish', $journal, 'publish'],
            ['POST', '/admin/journal/{id:[0-9]+}/unpublish', $journal, 'unpublish'],
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
