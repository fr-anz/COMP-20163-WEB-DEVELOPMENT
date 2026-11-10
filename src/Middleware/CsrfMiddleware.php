<?php

declare(strict_types=1);

namespace Habi\Middleware;

use Closure;
use Habi\Support\Csrf;
use Habi\Support\Session;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly Closure $cartFailure,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }
        $body = $request->getParsedBody();
        if (!is_array($body) && str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            $body = json_decode((string) $request->getBody(), true);
            $request->getBody()->rewind();
        }
        $token = is_array($body) && is_string($body['csrf_token'] ?? null) ? $body['csrf_token'] : null;
        $session = Session::store();
        if (Csrf::validate($session, $token)) {
            return $handler->handle($request);
        }
        $response = $this->responses->createResponse(403);
        if (in_array($request->getUri()->getPath(), ['/cart/add', '/cart/update', '/cart/remove'], true)) {
            return ($this->cartFailure)($request, $response);
        }
        $path = $request->getUri()->getPath();
        $admin = $path === '/admin' || str_starts_with($path, '/admin/');
        $message = $admin
            ? 'Your session expired or this form is stale. Reload the page and try again; nothing was changed.'
            : 'Your session expired or this form is stale. Reload the page and try again; no order was placed.';
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            $response->getBody()->write(json_encode([
                'error' => $message,
                'csrf_token' => Csrf::token($session),
            ], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write('<!doctype html><html lang="en"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Session expired — Habi</title><main><h1>Reload this form</h1><p>' . $message
            . '</p>'
            . ($admin ? '<a href="/admin">Return to the admin panel</a>' : '<a href="/cart">Review your bag</a>')
            . '</main></html>');

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
