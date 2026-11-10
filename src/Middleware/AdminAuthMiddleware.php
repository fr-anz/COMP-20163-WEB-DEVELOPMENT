<?php

declare(strict_types=1);

namespace Habi\Middleware;

use Closure;
use Habi\Support\Session;
use Habi\Support\SessionStore;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Every /admin path except the sign-in page requires a signed-in administrator
 * (R19, N2). The path is normalized exactly as Slim's router does before
 * matching (rawurldecode, then a leading slash, as in Slim's RouteResolver), so
 * encoded variants such as /%61dmin or /admin%2Forders cannot bypass it.
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    /** @var Closure(): SessionStore */
    private readonly Closure $session;

    /** @param (Closure(): SessionStore)|null $session defaults to the native PHP session */
    public function __construct(private readonly ResponseFactoryInterface $responses, ?Closure $session = null)
    {
        $this->session = $session ?? static fn (): SessionStore => Session::store();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rawurldecode($request->getUri()->getPath());
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        if (($path !== '/admin' && !str_starts_with($path, '/admin/')) || $path === '/admin/login') {
            return $handler->handle($request);
        }
        $id = ($this->session)()->get('admin_id');
        if (is_int($id) && $id > 0) {
            return $handler->handle($request);
        }

        return $this->responses->createResponse(303)->withHeader('Location', '/admin/login');
    }
}
