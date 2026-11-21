<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\LocaleMiddleware;
use Habi\Support\SessionStore;
use Habi\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /lang (R33, SDD §6): the header EN | FIL form. Stores the choice in
 * the session and a one-year habi_lang cookie (unknown values become `en`),
 * then redirects 303 to the submitted return path when it is a same-origin
 * path, else to `/`. CSRF is enforced by CsrfMiddleware like every POST.
 */
final class LanguageController
{
    private const COOKIE_MAX_AGE = 31536000;
    private const MAX_RETURN_LENGTH = 2048;

    public function __construct(
        private readonly SessionStore $session,
        private readonly bool $secureCookie,
    ) {
    }

    public function switch(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $locale = Translator::normalize($body['lang'] ?? null);
        $this->session->set(LocaleMiddleware::SESSION_KEY, $locale);

        $cookie = sprintf(
            '%s=%s; Path=/; Max-Age=%d; SameSite=Lax; HttpOnly%s',
            LocaleMiddleware::COOKIE,
            $locale,
            self::COOKIE_MAX_AGE,
            $this->secureCookie ? '; Secure' : ''
        );

        return $response->withStatus(303)
            ->withHeader('Set-Cookie', $cookie)
            ->withHeader('Location', self::safeReturnPath($body['return'] ?? null));
    }

    /**
     * The submitted path when it is a plain same-origin path ("/…", no
     * scheme, host, backslash or control characters, not /lang itself);
     * otherwise "/". Prevents open redirects and header injection.
     */
    public static function safeReturnPath(mixed $value): string
    {
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > self::MAX_RETURN_LENGTH
            || $value[0] !== '/'
            || str_starts_with($value, '//')
            || str_contains($value, '\\')
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
        ) {
            return '/';
        }
        $parts = parse_url($value);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return '/';
        }
        $path = rawurldecode($parts['path'] ?? '/');
        if (rtrim($path, '/') === '/lang' || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return '/';
        }

        return $value;
    }
}
