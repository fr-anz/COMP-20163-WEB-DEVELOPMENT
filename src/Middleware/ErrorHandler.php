<?php

declare(strict_types=1);

namespace Habi\Middleware;

use Habi\Support\Translator;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Renders standalone Habi-styled error pages. Debug details, paths and
 * configuration are never included in responses; unexpected exceptions are
 * logged to the server error log only.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $status = match (true) {
            $exception instanceof HttpNotFoundException => 404,
            $exception instanceof HttpMethodNotAllowedException => 405,
            default => 500,
        };

        if ($status >= 500 && $logErrors) {
            error_log(sprintf(
                '[habi] unhandled %s: %s in %s:%d',
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            ));
        }

        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write(self::page($status));

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Standalone Habi-styled error page: reuses the base layout's markup and
     * app.css classes only — no Twig, DB, session or scripts — so it renders
     * even when the app failed to initialize. Never includes debug details.
     * Words come from the active catalog (error.*, R33); before
     * LocaleMiddleware runs that is English.
     */
    public static function page(int $status): string
    {
        $translator = Translator::current();
        $code = in_array($status, [404, 405], true) ? (string) $status : '500';
        $text = static fn (string $key): string => htmlspecialchars(
            $translator->t($key),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $escapedTitle = $text('error.' . $code . '.title');
        $escapedMessage = $text('error.' . $code . '.body');
        $lang = $translator->locale();
        $unavailable = $text('error.unavailable');
        $skip = $text('shell.skip_link');
        $browse = $text('shell.browse_collection');
        $home = $text('error.back_home');
        $footer = $text('shell.nav_footer');
        $weave = $text('footer.weave');
        $collection = $text('nav.collection');
        $bag = $text('shell.your_bag');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$lang}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$escapedTitle} — Habi</title>
            <meta name="description" content="{$unavailable}">
            <meta name="robots" content="noindex">
            <link rel="stylesheet" href="/assets/css/app.css">
            <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
        </head>
        <body class="@container bg-page">
            <a class="skip-link" href="#main-content">{$skip}</a>

            <header class="sticky top-0 z-30 border-b border-line bg-page/95 backdrop-blur-sm">
                <div class="mx-auto flex h-16 max-w-[1280px] items-center gap-2 px-4 @3xl:px-8">
                    <a href="/" class="touch-target flex items-center gap-2 rounded-sm py-1">
                        <img src="/assets/img/reference/brand-mark.svg" width="26" height="26" alt="">
                        <span class="relative inline-flex items-baseline font-display font-bold leading-none"
                              role="img" aria-label="Habi"
                              style="font-size: 26px; color: var(--color-text-primary);
                                     letter-spacing: -0.035em; font-variation-settings: 'opsz' 96;">
                            <span aria-hidden="true">hab</span>
                            <span aria-hidden="true" class="relative">ı<img
                                 src="/assets/img/reference/wordmark-tittle.svg" alt=""
                                 class="absolute left-1/2 -translate-x-1/2 max-w-none"
                                 style="width: .2em; height: .2em; top: -.02em;"></span>
                        </span>
                    </a>
                </div>
            </header>

            <main id="main-content" tabindex="-1">
                <div class="mx-auto max-w-[1280px] px-4 py-8 @3xl:px-8 @3xl:py-12">
                    <div class="max-w-2xl">
                        <h1 class="font-display text-[30px] font-bold @3xl:text-h1">{$escapedTitle}</h1>
                        <p class="mt-3 text-body text-ink-2">{$escapedMessage}</p>
                        <div class="mt-6 flex flex-wrap items-center gap-3">
                            <a href="/products" class="btn btn--primary">{$browse}</a>
                            <a href="/" class="btn btn--secondary">{$home}</a>
                        </div>
                    </div>
                </div>
            </main>
            <footer class="bg-brown-900 text-inverse">
                <div class="h-2.5" aria-hidden="true">
                    <img src="/assets/img/reference/footer-selvedge.svg" alt="" class="block h-full w-full">
                </div>
                <div class="mx-auto flex max-w-[1280px] flex-wrap items-end justify-between gap-8 px-4 py-12
                            @3xl:px-8">
                    <span class="relative inline-flex items-baseline font-display font-bold leading-none"
                          role="img" aria-label="Habi"
                          style="font-size: 34px; color: var(--color-text-inverse);
                                 letter-spacing: -0.035em; font-variation-settings: 'opsz' 96;">
                        <span aria-hidden="true">hab</span>
                        <span aria-hidden="true" class="relative">ı<img
                             src="/assets/img/reference/wordmark-tittle.svg" alt=""
                             class="absolute left-1/2 -translate-x-1/2 max-w-none"
                             style="width: .2em; height: .2em; top: -.02em;"></span>
                    </span>
                    <nav aria-label="{$footer}">
                        <ul class="flex flex-wrap gap-x-6 gap-y-1 text-small">
                            <li>
                                <a href="/#craft"
                                   class="touch-link inline-block py-1.5 underline-offset-4 hover:underline"
                                   >{$weave}</a>
                            </li>
                            <li>
                                <a href="/products"
                                   class="touch-link inline-block py-1.5 underline-offset-4 hover:underline"
                                   >{$collection}</a>
                            </li>
                            <li>
                                <a href="/cart"
                                   class="touch-link inline-block py-1.5 underline-offset-4 hover:underline"
                                   >{$bag}</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </footer>
        </body>
        </html>

        HTML;
    }
}
