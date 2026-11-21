<?php

declare(strict_types=1);

namespace Habi\Middleware;

use Closure;
use Habi\Support\SessionStore;
use Habi\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Picks the request locale (R33): the session choice, else the habi_lang
 * cookie, else English; unsupported values are ignored. Staff screens
 * (/admin, /weaver) always use English (PRD R33 lets those modules stay
 * English-only), so shared messages there never switch language. The
 * locale becomes Translator::current() and the `locale` request attribute;
 * base.twig renders it as <html lang> (R26).
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public const COOKIE = 'habi_lang';
    public const SESSION_KEY = 'lang';

    /**
     * @param Closure(): ?SessionStore $session the current session, or null when there is none
     */
    public function __construct(
        private readonly Translator $translator,
        private readonly Closure $session,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = self::isStaffPath($request->getUri()->getPath())
            ? Translator::DEFAULT_LOCALE
            : self::resolve(($this->session)(), $request->getCookieParams());
        Translator::setCurrent($this->translator->withLocale($locale));

        return $handler->handle($request->withAttribute('locale', $locale));
    }

    /**
     * @param array<string, mixed> $cookies
     */
    public static function resolve(?SessionStore $session, array $cookies): string
    {
        $chosen = $session?->get(self::SESSION_KEY);
        if (Translator::isSupported($chosen)) {
            return $chosen;
        }

        return Translator::normalize($cookies[self::COOKIE] ?? null);
    }

    private static function isStaffPath(string $path): bool
    {
        $path = rawurldecode($path);

        return preg_match('#^/(?:admin|weaver)(?:/|$)#', $path) === 1;
    }
}
