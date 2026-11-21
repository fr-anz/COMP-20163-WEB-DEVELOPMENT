<?php

declare(strict_types=1);

namespace Habi\Tests\Middleware;

use Habi\Middleware\LocaleMiddleware;
use Habi\Support\SessionStore;
use Habi\Support\Translator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Locale resolution (R33, R26): session first, then the habi_lang cookie,
 * else English; unsupported values never select a locale; staff screens
 * (/admin, /weaver) always render in English.
 */
final class LocaleMiddlewareTest extends TestCase
{
    private ?string $seenAttribute = null;
    private ?string $seenCurrent = null;

    protected function tearDown(): void
    {
        Translator::reset();
    }

    public function testDefaultsToEnglish(): void
    {
        $session = [];
        $this->pass('/products', $session, []);

        self::assertSame('en', $this->seenAttribute);
        self::assertSame('en', $this->seenCurrent);
    }

    public function testCookieSelectsFilipinoWhenTheSessionHasNoChoice(): void
    {
        $session = [];
        $this->pass('/', $session, [LocaleMiddleware::COOKIE => 'fil']);

        self::assertSame('fil', $this->seenAttribute);
        self::assertSame('fil', $this->seenCurrent);
    }

    public function testSessionWinsOverTheCookie(): void
    {
        $session = [LocaleMiddleware::SESSION_KEY => 'en'];
        $this->pass('/', $session, [LocaleMiddleware::COOKIE => 'fil']);
        self::assertSame('en', $this->seenCurrent);

        $session = [LocaleMiddleware::SESSION_KEY => 'fil'];
        $this->pass('/', $session, [LocaleMiddleware::COOKIE => 'en']);
        self::assertSame('fil', $this->seenCurrent);
    }

    public function testInvalidValuesFallBackToEnglish(): void
    {
        $session = [LocaleMiddleware::SESSION_KEY => ['fil']];
        $this->pass('/', $session, [LocaleMiddleware::COOKIE => 'tl']);
        self::assertSame('en', $this->seenCurrent);

        $session = [LocaleMiddleware::SESSION_KEY => 'xx'];
        $this->pass('/', $session, [LocaleMiddleware::COOKIE => 'fil']);
        self::assertSame('fil', $this->seenCurrent, 'an invalid session value is ignored, the cookie still counts');
    }

    public function testWithoutASessionTheCookieDecides(): void
    {
        $middleware = new LocaleMiddleware(Translator::current(), static fn (): ?SessionStore => null);
        $request = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withCookieParams([LocaleMiddleware::COOKIE => 'fil']);
        $middleware->process($request, new class ($this) implements RequestHandlerInterface {
            public function __construct(private readonly LocaleMiddlewareTest $test)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->seen((string) $request->getAttribute('locale'), Translator::current()->locale());

                return new Response(200);
            }
        });

        self::assertSame('fil', $this->seenCurrent);
    }

    public function testStaffScreensStayEnglish(): void
    {
        foreach (['/admin', '/admin/orders', '/weaver', '/weaver/login', '/%61dmin/products'] as $path) {
            $session = [LocaleMiddleware::SESSION_KEY => 'fil'];
            $this->pass($path, $session, [LocaleMiddleware::COOKIE => 'fil']);
            self::assertSame('en', $this->seenCurrent, $path);
        }
        $session = [LocaleMiddleware::SESSION_KEY => 'fil'];
        $this->pass('/weavers', $session, []);
        self::assertSame('fil', $this->seenCurrent, '/weavers is a public storefront page');
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $cookies
     */
    private function pass(string $path, array &$session, array $cookies): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $path)->withCookieParams($cookies);
        $middleware = new LocaleMiddleware(
            Translator::current(),
            static fn (): SessionStore => new SessionStore($session)
        );

        return $middleware->process($request, new class ($this) implements RequestHandlerInterface {
            public function __construct(private readonly LocaleMiddlewareTest $test)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->seen((string) $request->getAttribute('locale'), Translator::current()->locale());

                return new Response(200);
            }
        });
    }

    public function seen(string $attribute, string $current): void
    {
        $this->seenAttribute = $attribute;
        $this->seenCurrent = $current;
    }
}
