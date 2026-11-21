<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Services\CartService;
use Habi\Services\ShippingService;
use Habi\Services\TrackingService;
use Habi\Support\Env;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * Guest order tracking page (/track, PRD R32, R26). GET shows the lookup
 * form; POST (CSRF-checked by middleware) looks the order up by reference
 * number and checkout email and renders the result or the generic
 * not-found, inline errors or throttle message on the same form.
 */
final class TrackController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CartService $cart,
        private readonly TrackingService $tracking,
    ) {
    }

    public function form(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($response, []);
    }

    public function lookup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $reference = is_string($body['reference'] ?? null) ? $body['reference'] : '';
        $email = is_string($body['email'] ?? null) ? $body['email'] : '';
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        $result = $this->tracking->lookup($reference, $email, $ip);
        $data = ['reference' => trim($reference), 'email' => trim($email)];

        return match ($result['status']) {
            TrackingService::STATUS_FOUND => $this->render($response, $data + ['order' => $result['order']]),
            TrackingService::STATUS_INVALID => $this->render(
                $response->withStatus(422),
                $data + ['errors' => $result['errors']]
            ),
            TrackingService::STATUS_LOCKED => $this->render(
                $response->withStatus(429)->withHeader('Retry-After', (string) $result['retry_after']),
                $data + ['message' => sprintf(
                    'Too many attempts. Try again in %d seconds.',
                    $result['retry_after']
                )]
            ),
            default => $this->render(
                $response->withStatus(404),
                $data + ['message' => "We couldn't find that order. Check the reference number and email."]
            ),
        };
    }

    private function render(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write($this->twig->render('storefront/track.twig', $data + [
            'app_name' => 'Habi',
            'app_env' => (string) Env::get('APP_ENV', 'local'),
            'cart' => $this->cart->cart(),
            'csrf_token' => $this->cart->csrfToken(),
            'regions' => ShippingService::REGIONS,
            'reference' => '',
            'email' => '',
            'errors' => [],
            'message' => null,
            'order' => null,
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
