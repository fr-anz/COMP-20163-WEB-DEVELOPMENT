<?php

declare(strict_types=1);

namespace Habi\Middleware;

use Habi\Support\Session;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Protect current and future admin paths; the sign-in flow is M6. */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (($path !== '/admin' && !str_starts_with($path, '/admin/')) || $path === '/admin/login') {
            return $handler->handle($request);
        }
        $id = Session::store()->get('admin_id');
        if (is_int($id) && $id > 0) {
            return $handler->handle($request);
        }

        return $this->responses->createResponse(303)->withHeader('Location', '/admin/login');
    }
}
