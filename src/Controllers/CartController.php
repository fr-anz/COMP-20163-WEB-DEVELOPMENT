<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Services\CartService;
use Habi\Support\Env;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Cart routes (M4): GET /cart (page or JSON) and the CSRF-protected
 * mutations /cart/add, /cart/update, /cart/remove. Native form posts get a
 * 303 redirect to /cart with session feedback; requests accepting JSON get
 * {cart, drawer_html, page_html, message, csrf_token} — errors use the same
 * shape plus a public error string and an appropriate status. CSRF
 * failures never mutate anything.
 */
final class CartController
{
    /** Shipping regions the cart page's no-JS estimate accepts (PRD R12). */
    private const REGIONS = ['metro_manila', 'luzon', 'visayas', 'mindanao'];

    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly CartService $cart,
    ) {
    }

    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $cart = $this->cart->cart();
        $message = $this->cart->consumeMessage();

        if ($this->wantsJson($request)) {
            return $this->jsonPayload($response, 200, $message);
        }

        $response->getBody()->write($this->twig->render('storefront/cart.twig', [
            'app_name' => 'Habi',
            'app_env' => (string) Env::get('APP_ENV', 'local'),
            'cart' => $cart,
            'cart_mode' => 'page',
            'csrf_token' => $this->cart->csrfToken(),
            'cart_message' => $message,
            'region' => $this->selectedRegion($request),
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function add(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->mutate($request, $response, 'add');
    }

    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->mutate($request, $response, 'update');
    }

    public function remove(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->mutate($request, $response, 'remove');
    }

    public function rejectCsrf(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->error(
            $request,
            $response,
            403,
            'Your session has expired — please reload and try again. An expired session loses its bag.'
        );
    }

    /**
     * The ?region= selection for the no-JS shipping estimate, allowlisted to
     * the supported keys; anything unknown or absent is an empty string.
     */
    private function selectedRegion(ServerRequestInterface $request): string
    {
        $requested = $request->getQueryParams()['region'] ?? null;

        if (is_string($requested) && in_array($requested, self::REGIONS, true)) {
            return $requested;
        }

        return '';
    }

    private function mutate(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $action
    ): ResponseInterface {
        $body = $this->body($request);


        $productId = CartService::parseId($body['product_id'] ?? null);
        if ($productId === null) {
            return $this->error(
                $request,
                $response,
                400,
                'That product could not be identified.'
            );
        }

        $quantity = null;
        if ($action !== 'remove') {
            $quantity = CartService::parseQuantity($body['quantity'] ?? null);
            if ($quantity === null) {
                return $this->error(
                    $request,
                    $response,
                    400,
                    'Enter a valid quantity.'
                );
            }
        }

        $result = match ($action) {
            'add' => $this->cart->add($productId, (int) $quantity),
            'update' => $this->cart->update($productId, (int) $quantity),
            default => $this->cart->remove($productId),
        };

        if (!$result['ok']) {
            return $this->error(
                $request,
                $response,
                $result['status'],
                (string) ($result['error'] ?? 'The cart could not be updated.')
            );
        }

        if (!$this->wantsJson($request)) {
            $this->cart->setMessage((string) $result['message']);

            return $response
                ->withStatus(303)
                ->withHeader('Location', '/cart');
        }

        return $this->jsonPayload($response, 200, (string) $result['message']);
    }

    /**
     * Shared JSON shape: cart plus rendered drawer/page fragments, the
     * transient message when one is being delivered, the session's CSRF
     * token so JSON clients can drive further mutations, and — on failures
     * — a public error string.
     */
    private function jsonPayload(
        ResponseInterface $response,
        int $status,
        ?string $message,
        ?string $error = null
    ): ResponseInterface {
        $cart = $this->cart->cart();
        $payload = [
            'cart' => $cart,
            'drawer_html' => $this->fragment('drawer', $cart),
            'page_html' => $this->fragment('page', $cart),
            'message' => $message,
            'csrf_token' => $this->cart->csrfToken(),
        ];
        if ($error !== null) {
            $payload['error'] = $error;
        }

        $response->getBody()->write(
            (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Failures keep the shared JSON shape with a public error string; on
     * native (no-JS) posts the actionable message is flashed onto the cart
     * page via a 303 redirect so the shopper can recover.
     */
    private function error(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $status,
        string $message
    ): ResponseInterface {
        if (!$this->wantsJson($request)) {
            $this->cart->setMessage($message);

            return $response
                ->withStatus(303)
                ->withHeader('Location', '/cart');
        }

        return $this->jsonPayload($response, $status, $message, $message);
    }

    /**
     * @param array<string, mixed> $cart
     */
    private function fragment(string $mode, array $cart): string
    {
        return $this->twig->render('storefront/_cart-content.twig', [
            'cart' => $cart,
            'cart_mode' => $mode,
            'csrf_token' => $this->cart->csrfToken(),
        ]);
    }

    /**
     * Form fields from native posts or a JSON body; anything unreadable is
     * treated as an empty body.
     *
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        $contentType = (string) $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) $request->getBody(), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');

        return str_contains($accept, 'application/json');
    }
}
