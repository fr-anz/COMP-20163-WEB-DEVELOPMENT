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
 * Renders plain public error pages. Debug details, paths and configuration
 * are never included in responses; unexpected exceptions are logged to the
 * server error log only.
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
     * Minimal standalone page (no view dependencies, no debug output).
     */
    public static function page(int $status): string
    {
        [$title, $message] = match ($status) {
            404 => ['Page not found', 'The page you are looking for does not exist.'],
            405 => ['Method not allowed', 'That request method is not supported here.'],
            default => ['Something went wrong', 'Please try again in a moment.'],
        };

        $escapedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Habi &middot; {$escapedTitle}</title>
            </head>
            <body>
                <main>
                    <h1>{$escapedTitle}</h1>
                    <p>{$escapedMessage}</p>
                    <p><a href="/">Return to Habi</a></p>
                </main>
            </body>
            </html>

            HTML;
    }
}
