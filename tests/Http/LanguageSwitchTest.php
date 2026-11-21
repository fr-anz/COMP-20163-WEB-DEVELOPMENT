<?php

declare(strict_types=1);

namespace Habi\Tests\Http;

use Habi\Controllers\LanguageController;
use Habi\Middleware\LocaleMiddleware;
use Habi\Support\SessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /lang (R33): stores en/fil in the session and the habi_lang cookie,
 * and redirects back to a same-origin path only. CSRF is the global
 * CsrfMiddleware (every POST); its rejection is covered by the HTTP smoke in
 * BUILD M14 because it starts a native session.
 */
final class LanguageSwitchTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $session = [];

    public function testStoresTheChoiceInSessionAndCookieAndRedirectsBack(): void
    {
        $response = $this->switch(['lang' => 'fil', 'return' => '/products?fiber=abaca&page=2']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/products?fiber=abaca&page=2', $response->getHeaderLine('Location'));
        self::assertSame('fil', $this->session[LocaleMiddleware::SESSION_KEY]);
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringStartsWith('habi_lang=fil;', $cookie);
        self::assertStringContainsString('Path=/', $cookie);
        self::assertStringContainsString('Max-Age=31536000', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringNotContainsString('Secure', $cookie);

        $secure = (new LanguageController(new SessionStore($this->session), true))->switch(
            $this->request(['lang' => 'en']),
            new Response()
        );
        self::assertStringContainsString('; Secure', $secure->getHeaderLine('Set-Cookie'));
        self::assertSame('en', $this->session[LocaleMiddleware::SESSION_KEY]);
    }

    public function testUnknownLanguageFallsBackToEnglish(): void
    {
        $this->session[LocaleMiddleware::SESSION_KEY] = 'fil';
        foreach (['de', '', 'FIL', ['fil']] as $value) {
            $response = $this->switch(['lang' => $value, 'return' => '/cart']);
            self::assertSame('en', $this->session[LocaleMiddleware::SESSION_KEY]);
            self::assertStringStartsWith('habi_lang=en;', $response->getHeaderLine('Set-Cookie'));
        }
    }

    /** @return array<string, array{mixed}> */
    public static function unsafeReturns(): array
    {
        return [
            'absolute URL' => ['https://evil.example/'],
            'protocol-relative' => ['//evil.example/path'],
            'backslash host' => ['/\\evil.example'],
            'encoded backslash kept literal' => ["/\\\\evil.example"],
            'scheme without slashes' => ['javascript:alert(1)'],
            'relative path' => ['products'],
            'header injection' => ["/cart\r\nSet-Cookie: x=1"],
            'tab' => ["/\t/evil.example"],
            'array' => [['/cart']],
            'missing' => [null],
            'switch itself' => ['/lang'],
            'too long' => ['/' . str_repeat('a', 2100)],
        ];
    }

    #[DataProvider('unsafeReturns')]
    public function testUnsafeReturnPathsRedirectHome(mixed $return): void
    {
        $body = ['lang' => 'fil'];
        if ($return !== null) {
            $body['return'] = $return;
        }

        self::assertSame('/', $this->switch($body)->getHeaderLine('Location'));
    }

    /** @param array<string, mixed> $body */
    private function switch(array $body): ResponseInterface
    {
        return (new LanguageController(new SessionStore($this->session), false))
            ->switch($this->request($body), new Response());
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('POST', '/lang')->withParsedBody($body);
    }
}
