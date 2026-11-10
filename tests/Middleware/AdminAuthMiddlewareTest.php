<?php

declare(strict_types=1);

namespace Habi\Tests\Middleware;

use Habi\Middleware\AdminAuthMiddleware;
use Habi\Support\SessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The admin gate must see the same path Slim routes (rawurldecode'd), so
 * percent-encoded spellings of /admin cannot reach admin routes signed out.
 */
final class AdminAuthMiddlewareTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function protectedPaths(): array
    {
        return [
            'plain' => ['/admin/orders'],
            'root' => ['/admin'],
            'encoded letter' => ['/%61dmin/orders'],
            'encoded root' => ['/%61dmin'],
            'encoded slash' => ['/admin%2Forders'],
            'encoded login suffix' => ['/admin/login%2F..%2Forders'],
        ];
    }

    #[DataProvider('protectedPaths')]
    public function testSignedOutRequestsToAdminPathsRedirectToSignIn(string $path): void
    {
        $response = $this->gate($path, []);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
    }

    public function testSignInPageAndStorefrontStayPublic(): void
    {
        self::assertSame(200, $this->gate('/admin/login', [])->getStatusCode());
        self::assertSame(200, $this->gate('/products', [])->getStatusCode());
    }

    public function testSignedInAdministratorPassesEncodedPaths(): void
    {
        self::assertSame(200, $this->gate('/%61dmin/orders', ['admin_id' => 1])->getStatusCode());
    }

    /** @param array<string, mixed> $session */
    private function gate(string $path, array $session): ResponseInterface
    {
        $factory = new Psr17Factory();
        $middleware = new AdminAuthMiddleware($factory, static fn (): SessionStore => new SessionStore($session));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        return $middleware->process($factory->createServerRequest('GET', 'http://localhost' . $path), $handler);
    }
}
