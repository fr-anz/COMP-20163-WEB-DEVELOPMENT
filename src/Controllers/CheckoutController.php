<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\ErrorHandler;
use Habi\Services\CartService;
use Habi\Services\CheckoutException;
use Habi\Services\OrderService;
use Habi\Services\PaymentSimulator;
use Habi\Services\ShippingService;
use Habi\Support\Csrf;
use Habi\Support\Env;
use Habi\Support\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Twig\Environment;

final class CheckoutController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CartService $cart,
        private readonly SessionStore $session,
        private readonly OrderService $orders,
        private readonly PaymentSimulator $payments,
    ) {
    }

    public function shipping(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cart = $this->cart->cart();
        if ($cart['count'] === 0) {
            return $this->emptyCart($response);
        }

        $feedback = $this->session->get('shipping_feedback', []);
        $this->session->unset('shipping_feedback');

        return $this->render(
            $feedback === [] ? $response : $response->withStatus(422),
            'checkout-shipping',
            $cart,
            array_merge(['step' => 1], $feedback)
        );
    }

    public function saveShipping(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cart = $this->cart->cart();
        if ($cart['count'] === 0) {
            return $this->emptyCart($response);
        }
        $validated = ShippingService::validate($this->body($request));
        if ($validated['errors'] !== []) {
            $this->session->set('shipping_feedback', $validated);

            return $this->redirect($response, '/checkout');
        }
        // Editing shipping requires a fresh simulation; keep no prior authorization.
        $this->session->set('checkout', ['shipping' => $validated['shipping']]);
        $this->session->unset('shipping_feedback');
        $this->session->unset('payment_feedback');

        return $this->redirect($response, '/checkout/payment');
    }

    public function payment(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cart = $this->cart->cart();
        if (($guard = $this->guard($response, $cart)) !== null) {
            return $guard;
        }

        $feedback = $this->session->get('payment_feedback', []);
        $this->session->unset('payment_feedback');

        return $this->render(
            $feedback === [] ? $response : $response->withStatus(422),
            'checkout-payment',
            $cart,
            array_merge(['step' => 2], $feedback)
        );
    }

    public function simulate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cart = $this->cart->cart();
        if (($guard = $this->guard($response, $cart)) !== null) {
            return $guard;
        }
        $state = $this->state();
        unset($state['payment'], $state['fingerprint'], $state['idempotency_key'], $state['lines']);
        $this->session->set('checkout', $state);
        // The simulator returns only errors/result and optionally last4/brand.
        // Neither submitted body nor PAN/CVC/expiry ever enters session or logs.
        $result = $this->payments->simulate($this->body($request));
        if ($result['result'] !== 'approved') {
            $message = match ($result['result']) {
                'declined' => 'Card declined (simulated) — try another test card. Your bag and shipping are intact.',
                'insufficient_funds' => 'Insufficient funds (simulated) — try another test card.',
                default => 'Check the highlighted card details and try again.',
            };

            // PRG keeps no-store POST responses out of native Back history.
            // Flash only safe errors: never the submitted card fields.
            $this->session->set('payment_feedback', [
                'errors' => $result['errors'],
                'message' => $message,
            ]);

            return $this->redirect($response, '/checkout/payment');
        }
        $state['payment'] = [
            'result' => 'approved',
            'last4' => $result['last4'],
            'brand' => $result['brand'],
        ];
        $state['fingerprint'] = $this->fingerprint($cart);
        $state['lines'] = $this->snapshot($cart);
        $this->session->set('checkout', $state);

        return $this->redirect($response, '/checkout/review');
    }

    public function review(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cart = $this->cart->cart();
        if (($guard = $this->guard($response, $cart, true)) !== null) {
            return $guard;
        }
        $state = $this->state();
        if (!isset($state['idempotency_key'])) {
            $state['idempotency_key'] = bin2hex(random_bytes(32));
            $this->session->set('checkout', $state);
        }

        return $this->render($response, 'checkout-review', $cart, ['step' => 3]);
    }

    public function place(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $key = $body['idempotency_key'] ?? null;
        $completed = $this->session->get('completed_orders', []);
        // A committed retry is checked before the now-empty cart or new checkout.
        if (is_string($key) && isset($completed[$key])) {
            return $this->redirect($response, '/order/' . rawurlencode($completed[$key]));
        }
        $state = $this->state();
        if (
            !is_string($key) || !isset($state['idempotency_key'])
            || !hash_equals($state['idempotency_key'], $key)
        ) {
            return $this->redirect($response, '/checkout/review');
        }
        if (
            ($state['payment']['result'] ?? '') !== 'approved'
            || !isset($state['shipping'], $state['lines'])
        ) {
            return $this->redirect($response, '/checkout/payment');
        }
        // Do not reconcile/clamp before the transaction: Place must fail rather
        // than silently buying fewer pieces than the reviewed order.
        $quantities = [];
        foreach ($state['lines'] as $line) {
            $quantities[$line['product_id']] = $line['quantity'];
        }
        if ($this->session->get('cart', []) !== $quantities) {
            return $this->changedCart($response);
        }
        try {
            $order = $this->orders->placeOrder($state['lines'], $state['shipping'], $state['payment'], $key);
        } catch (CheckoutException $failure) {
            $this->clearApproval();
            $this->cart->cart();
            $this->cart->setMessage($failure->getMessage() . ' Review your corrected bag before trying again.');

            return $this->redirect($response, '/cart');
        } catch (Throwable) {
            // Keep the exact key and approval: retry is safe even after a lost
            // response. Do not log exceptions containing user/payment input.
            return $this->render($response->withStatus(503), 'checkout-review', $this->cart->cart(), [
                'step' => 3,
                'message' => 'The order could not be completed. Please retry Place order; you will not order twice.',
            ]);
        }
        $completed[$key] = $order['order_number'];
        $this->session->set('completed_orders', $completed);
        $this->session->set('cart', []);
        $this->session->unset('cart_notices');
        $this->session->unset('checkout');

        return $this->redirect($response, '/order/' . rawurlencode($order['order_number']));
    }

    public function confirmation(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $number = (string) $args['orderNumber'];
        $owned = $this->session->get('completed_orders', []);
        $order = in_array($number, $owned, true) ? $this->orders->findByNumber($number) : null;
        if ($order === null) {
            $response->getBody()->write(ErrorHandler::page(404));

            return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->render($response, 'order', $this->cart->cart(), [
            'step' => 4,
            'order' => $order,
        ]);
    }

    private function guard(ResponseInterface $response, array $cart, bool $approved = false): ?ResponseInterface
    {
        if ($cart['count'] === 0) {
            return $this->emptyCart($response);
        }
        $state = $this->state();
        if (!isset($state['shipping'])) {
            return $this->redirect($response, '/checkout');
        }
        if ($approved) {
            if (($state['payment']['result'] ?? '') !== 'approved') {
                return $this->redirect($response, '/checkout/payment');
            }
            if (($state['fingerprint'] ?? '') !== $this->fingerprint($cart)) {
                return $this->changedCart($response);
            }
        }

        return null;
    }

    private function changedCart(ResponseInterface $response): ResponseInterface
    {
        $this->clearApproval();
        $this->cart->setMessage('Your bag or its prices changed. Review the current bag and simulate payment again.');

        return $this->redirect($response, '/cart');
    }

    private function clearApproval(): void
    {
        $state = $this->state();
        unset($state['payment'], $state['fingerprint'], $state['idempotency_key'], $state['lines']);
        $this->session->set('checkout', $state);
    }

    private function emptyCart(ResponseInterface $response): ResponseInterface
    {
        $this->clearApproval();
        $this->cart->setMessage('Your bag is empty or your session expired — please add items again.');

        return $this->redirect($response, '/cart');
    }

    private function state(): array
    {
        $state = $this->session->get('checkout', []);

        return is_array($state) ? $state : [];
    }

    private function snapshot(array $cart): array
    {
        return array_map(static fn (array $line): array => [
            'product_id' => $line['product_id'],
            'quantity' => $line['quantity'],
            'price_centavos' => $line['price_centavos'],
        ], $cart['lines']);
    }

    private function fingerprint(array $cart): string
    {
        return hash('sha256', json_encode($this->snapshot($cart), JSON_THROW_ON_ERROR));
    }

    private function render(ResponseInterface $response, string $template, array $cart, array $data): ResponseInterface
    {
        $state = $this->state();
        $shipping = $data['shipping'] ?? $state['shipping'] ?? [];
        $region = $shipping['region'] ?? 'metro_manila';
        $fee = ShippingService::fee(
            isset(ShippingService::REGIONS[$region]) ? $region : 'metro_manila',
            $cart['subtotal_centavos']
        );
        $response->getBody()->write($this->twig->render('storefront/' . $template . '.twig', array_merge([
            'app_name' => 'Habi',
            'app_env' => (string) Env::get('APP_ENV', 'local'),
            'cart' => $cart,
            'csrf_token' => Csrf::token($this->session),
            'shipping' => $shipping,
            'regions' => ShippingService::REGIONS,
            'shipping_fee_centavos' => $fee,
            'total_centavos' => $cart['subtotal_centavos'] + $fee,
            'payment' => $state['payment'] ?? [],
            'idempotency_key' => $state['idempotency_key'] ?? '',
            'errors' => [],
            'message' => null,
            'delivery_estimate' => 'Simulated estimate: 3–7 business days. Nothing will actually be shipped.',
        ], $data)));

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    private function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $path);
    }
}
