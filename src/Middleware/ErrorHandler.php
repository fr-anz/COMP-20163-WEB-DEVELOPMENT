<?php

declare(strict_types=1);

namespace Habi\Middleware;

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
     */
    public static function page(int $status): string
    {
        [$title, $message] = match ($status) {
            404 => ['Page not found', 'The page you are looking for does not exist or has moved.'],
            405 => ['Method not allowed', 'That request method is not supported here.'],
            default => ['Something went wrong', 'Something unexpected happened. Please try again in a moment.'],
        };

        $escapedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $code = (string) $status;

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$escapedTitle} — Habi</title>
            <meta name="description" content="This page is unavailable; Habi is an educational demo.">
            <meta name="robots" content="noindex">
            <link rel="stylesheet" href="/assets/css/app.css">
            <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
        </head>
        <body class="@container bg-page">
            <a class="skip-link" href="#main-content">Skip to main content</a>

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
                        <p class="text-caption font-medium tracking-wide text-ink-2 uppercase">Error {$code}</p>
                        <h1 class="mt-1 font-display text-[30px] font-bold @3xl:text-h1">{$escapedTitle}</h1>
                        <p class="mt-3 text-body text-ink-2">{$escapedMessage}</p>
                        <div class="mt-6 flex flex-wrap items-center gap-3">
                            <a href="/products" class="btn btn--primary">Browse the collection</a>
                            <a href="/" class="btn btn--secondary">Back to discovery</a>
                        </div>
                    </div>
                </div>
            </main>

            <footer class="bg-brown-900 text-inverse">
                <div class="h-2.5" aria-hidden="true">
                    <img src="/assets/img/reference/footer-selvedge.svg" alt="" class="block h-full w-full">
                </div>
                <div class="mx-auto grid max-w-[1280px] gap-8 px-4 py-12 @3xl:grid-cols-[1.3fr_1fr_1fr] @3xl:px-8">
                    <div>
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
                        <p class="mt-3 max-w-xs text-small text-inverse/75">
                            Contemporary Filipino craft, explored one weave at a time.
                        </p>
                    </div>
                    <nav aria-label="Footer">
                        <p class="text-caption font-semibold tracking-wider text-inverse/60 uppercase">Explore</p>
                        <ul class="mt-2 space-y-0.5 text-small">
                            <li>
                                <a href="/#craft"
                                   class="touch-link inline-block py-1.5 underline-offset-4 hover:underline"
                                   >Discover the weave</a>
                            </li>
                            <li>
                                <a href="/products"
                                   class="touch-link inline-block py-1.5 underline-offset-4 hover:underline"
                                   >The collection</a>
                            </li>
                            <li>
                                <a href="/cart"
                                   class="touch-link inline-block py-1.5 underline-offset-4 hover:underline"
                                   >Your bag</a>
                            </li>
                        </ul>
                    </nav>
                    <div>
                        <p class="text-caption font-semibold tracking-wider text-inverse/60 uppercase"
                           >About this demo</p>
                        <div class="mt-3">
                            <p class="flex items-start gap-2 text-caption text-inverse/85">
                                <svg class="icon mt-0.5 flex-none text-yellow-400"
                                     width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"
                                     aria-hidden="true" focusable="false">
                                    <circle cx="12" cy="12" r="9"/>
                                    <path d="M12 11v5.5M12 7.5v.01"/>
                                </svg>
                                <span>
                                    <strong class="font-semibold text-inverse">Educational demo.</strong>
                                    Habi is a fictional brand. Products, prices, orders and material labels
                                    are illustrative; nothing is sold, shipped or charged, and no maker or
                                    origin is claimed.
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
            </footer>
        </body>
        </html>

        HTML;
    }
}
