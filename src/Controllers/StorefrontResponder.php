<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\ErrorHandler;
use Habi\Services\CartService;
use Habi\Support\Env;
use Psr\Http\Message\ResponseInterface;
use Twig\Environment;

/**
 * Shared rendering for public storefront pages outside StorefrontController
 * (Meet the Weavers, Heritage Journal): the shell's cart context (badge
 * count, CSRF token, pending cart message) plus the template data, and the
 * standalone 404 page.
 */
final class StorefrontResponder
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CartService $cart,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(ResponseInterface $response, string $template, array $data): ResponseInterface
    {
        $response->getBody()->write($this->twig->render($template, [
            'app_name' => 'Habi',
            'app_env' => (string) Env::get('APP_ENV', 'local'),
            'cart' => $this->cart->cart(),
            'csrf_token' => $this->cart->csrfToken(),
            'cart_message' => $this->cart->consumeMessage(),
        ] + $data));

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(ErrorHandler::page(404));

        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
