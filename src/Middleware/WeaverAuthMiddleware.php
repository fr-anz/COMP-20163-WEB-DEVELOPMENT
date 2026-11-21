<?php

declare(strict_types=1);

namespace Habi\Middleware;

use Closure;
use Habi\Services\WeaverService;
use Habi\Support\Database;
use Habi\Support\Session;
use Habi\Support\SessionStore;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Every /weaver path except the sign-in page requires an active weaver
 * (R34, N10). The session's weaver_id is checked against the database on
 * every request, so a weaver an admin deactivates is signed out on their
 * next request; an admin session alone never passes. The active weaver
 * row is handed to controllers as the "weaver" request attribute, the only
 * source of the id used in ownership filters. The path is normalized as in
 * AdminAuthMiddleware (Slim's rawurldecode), so encoded spellings cannot
 * bypass it; /weavers (public profiles) is a different path.
 */
final class WeaverAuthMiddleware implements MiddlewareInterface
{
    /** @var Closure(): SessionStore */
    private readonly Closure $session;
    /** @var Closure(int): ?array<string, mixed> */
    private readonly Closure $activeWeaver;

    /**
     * @param (Closure(): SessionStore)|null $session defaults to the native PHP session
     * @param (Closure(int): ?array<string, mixed>)|null $activeWeaver defaults to WeaverService::findActive
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        ?Closure $session = null,
        ?Closure $activeWeaver = null,
    ) {
        $this->session = $session ?? static fn (): SessionStore => Session::store();
        $this->activeWeaver = $activeWeaver
            ?? static fn (int $id): ?array => (new WeaverService(Database::connection()))->findActive($id);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rawurldecode($request->getUri()->getPath());
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        if (($path !== '/weaver' && !str_starts_with($path, '/weaver/')) || $path === '/weaver/login') {
            return $handler->handle($request);
        }
        $session = ($this->session)();
        $id = $session->get('weaver_id');
        $weaver = is_int($id) && $id > 0 ? ($this->activeWeaver)($id) : null;
        if ($weaver !== null) {
            return $handler->handle($request->withAttribute('weaver', $weaver));
        }
        if ($id !== null) {
            $session->unset('weaver_id');
            $session->unset('weaver_email');
        }

        return $this->responses->createResponse(303)->withHeader('Location', '/weaver/login');
    }
}
