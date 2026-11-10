<?php

declare(strict_types=1);

namespace Habi\Tests\Middleware;

use Habi\Middleware\ErrorHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Error pages are standalone (no Twig/DB/session) and must never leak
 * exception details, even when error details are enabled.
 */
final class ErrorHandlerTest extends TestCase
{
    /** @return array<string, array{int, string, string}> */
    public static function statusPages(): array
    {
        return [
            '404' => [404, 'Page not found', 'The page you are looking for does not exist or has moved.'],
            '405' => [405, 'Method not allowed', 'That request method is not supported here.'],
            '500' => [500, 'Something went wrong', 'Something unexpected happened. Please try again in a moment.'],
        ];
    }

    #[DataProvider('statusPages')]
    public function testPageRendersHabiShellForStatus(int $status, string $title, string $message): void
    {
        $html = ErrorHandler::page($status);

        self::assertStringContainsString("<title>{$title} — Habi</title>", $html);
        self::assertMatchesRegularExpression(
            '/<h1[^>]*>' . preg_quote($title, '/') . '<\/h1>/',
            $html
        );
        self::assertStringContainsString($message, $html);
        self::assertStringContainsString("Error {$status}", $html);
        self::assertStringContainsString('href="/products"', $html);
        self::assertStringContainsString('href="/"', $html);
        self::assertStringContainsString('name="robots" content="noindex"', $html);
        self::assertStringContainsString('/assets/css/app.css', $html);
        self::assertStringNotContainsStringIgnoringCase('exception', $html);
    }

    public function testInvokeReturns500AndHidesExceptionDetailsWhenDetailsEnabled(): void
    {
        $response = $this->invoke(new RuntimeException('secret path /x'), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('text/html; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('secret path', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringContainsString('Something went wrong', $body);
    }

    public function testInvokeMapsNotFoundExceptionTo404(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', 'http://localhost/missing');
        $response = $this->invoke(new HttpNotFoundException($request), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Page not found', (string) $response->getBody());
    }

    public function testInvokeMapsMethodNotAllowedExceptionTo405(): void
    {
        $request = (new Psr17Factory())->createServerRequest('POST', 'http://localhost/products');
        $response = $this->invoke(new HttpMethodNotAllowedException($request), true);

        self::assertSame(405, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Method not allowed', $body);
        self::assertStringContainsString('That request method is not supported here.', $body);
    }

    private function invoke(Throwable $exception, bool $displayErrorDetails): ResponseInterface
    {
        $factory = new Psr17Factory();
        $handler = new ErrorHandler($factory);

        return $handler(
            $factory->createServerRequest('GET', 'http://localhost/'),
            $exception,
            $displayErrorDetails,
            false,
            false
        );
    }
}
