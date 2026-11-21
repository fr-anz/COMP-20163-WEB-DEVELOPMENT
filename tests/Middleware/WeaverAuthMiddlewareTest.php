<?php

declare(strict_types=1);

namespace Habi\Tests\Middleware;

use Habi\Middleware\WeaverAuthMiddleware;
use Habi\Support\SessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The weaver gate (R34, N10) normalizes paths like Slim's router, requires
 * an active weaver on every request (an admin session is not enough),
 * signs out a weaver deactivated mid-session and leaves /weavers public.
 */
final class WeaverAuthMiddlewareTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $seen = null;

    /** @return array<string, array{string}> */
    public static function protectedPaths(): array
    {
        return [
            'root' => ['/weaver'],
            'plain' => ['/weaver/products'],
            'encoded letter' => ['/%77eaver/orders'],
            'encoded slash' => ['/weaver%2Fearnings'],
            'encoded login suffix' => ['/weaver/login%2F..%2Fprofile'],
        ];
    }

    #[DataProvider('protectedPaths')]
    public function testSignedOutAndAdminSessionsRedirectToWeaverSignIn(string $path): void
    {
        foreach ([[], ['admin_id' => 1]] as $session) {
            $response = $this->gate($path, $session);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/weaver/login', $response->getHeaderLine('Location'));
        }
    }

    public function testPublicPathsAndSignInStayOpen(): void
    {
        foreach (['/weaver/login', '/weavers', '/weavers/lita', '/products', '/admin/login'] as $path) {
            $session = [];
            self::assertSame(200, $this->gate($path, $session)->getStatusCode(), $path);
        }
    }

    public function testActiveWeaverPassesWithTheWeaverAttribute(): void
    {
        $session = ['weaver_id' => 7];
        self::assertSame(200, $this->gate('/%77eaver/orders', $session)->getStatusCode());
        self::assertSame(7, $this->seen['id']);
    }

    public function testDeactivatedWeaverIsSignedOutOnTheNextRequest(): void
    {
        $session = ['weaver_id' => 8, 'weaver_email' => 'gone@weavers.test'];
        $response = $this->gate('/weaver', $session);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/weaver/login', $response->getHeaderLine('Location'));
        self::assertArrayNotHasKey('weaver_id', $session);
        self::assertArrayNotHasKey('weaver_email', $session);
    }

    /** @param array<string, mixed> $session */
    private function gate(string $path, array &$session): ResponseInterface
    {
        $factory = new Psr17Factory();
        $middleware = new WeaverAuthMiddleware(
            $factory,
            static function () use (&$session): SessionStore {
                return new SessionStore($session);
            },
            static fn (int $id): ?array => $id === 7 ? ['id' => 7, 'display_name' => 'Lita'] : null
        );
        $handler = new class ($this->seen) implements RequestHandlerInterface {
            /** @param array<string, mixed>|null $seen */
            public function __construct(private ?array &$seen)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request->getAttribute('weaver');

                return new Response(200);
            }
        };

        return $middleware->process($factory->createServerRequest('GET', 'http://localhost' . $path), $handler);
    }
}
