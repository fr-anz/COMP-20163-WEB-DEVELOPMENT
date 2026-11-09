<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\ErrorHandler;
use Habi\Services\ProductService;
use Habi\Support\Env;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Public storefront pages: home, catalog listing, product detail, and the
 * read-only stock/config APIs. Rendering has no cart or mutation scope.
 */
final class StorefrontController
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly ProductService $products,
    ) {
    }

    public function home(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        $newest = $this->products->list(['sort' => 'newest', 'page' => 1])['products'];
        $featured = array_slice($newest, 0, 3);

        return $this->render($response, 'storefront/home.twig', [
            'featured_products' => $featured,
        ]);
    }

    public function products(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        $query = $request->getQueryParams();
        $listing = $this->products->list([
            'q' => $query['q'] ?? '',
            'category' => $query['category'] ?? '',
            'sort' => $query['sort'] ?? '',
            'page' => $query['page'] ?? 1,
        ]);

        return $this->render($response, 'storefront/products.twig', [
            'products' => $listing['products'],
            'total' => $listing['total'],
            'page' => $listing['page'],
            'pages' => $listing['pages'],
            'per_page' => $listing['per_page'],
            'q' => $listing['q'],
            'category' => $listing['category'],
            'sort' => $listing['sort'],
            'categories' => $this->products->categories(),
            'previous_url' => $this->pageUrl($request, $listing, $listing['page'] - 1),
            'next_url' => $this->pageUrl($request, $listing, $listing['page'] + 1),
        ]);
    }

    public function product(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $product = $this->products->getBySlug((string) ($args['slug'] ?? ''));
        if ($product === null) {
            return $this->notFound($response);
        }

        return $this->render($response, 'storefront/product.twig', [
            'product' => $product,
        ]);
    }

    public function stock(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $product = $this->products->getBySlug((string) ($args['slug'] ?? ''));

        $response->getBody()->write(
            (string) json_encode(
                $product === null
                    ? ['error' => 'Product not found']
                    : [
                        'slug' => $product['slug'],
                        'stock' => $product['stock'],
                        'low_stock_threshold' => $product['low_stock_threshold'],
                    ],
                JSON_UNESCAPED_SLASHES
            )
        );

        $status = $product === null ? 404 : 200;

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function config3d(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $config = $this->products->get3dConfig((string) ($args['slug'] ?? ''));
        $response->getBody()->write(
            json_encode($config ?? ['error' => 'Product not found'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );

        return $response
            ->withStatus($config === null ? 404 : 200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Renders a Twig template with the shared app context plus the given
     * template data.
     *
     * @param array<string, mixed> $data
     */
    private function render(
        ResponseInterface $response,
        string $template,
        array $data
    ): ResponseInterface {
        $response->getBody()->write($this->twig->render($template, [
            'app_name' => 'Habi',
            'app_env' => (string) Env::get('APP_ENV', 'local'),
        ] + $data));

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * URL of a listing page with every active filter preserved, or null when
     * the page number falls outside the available range.
     */
    private function pageUrl(
        ServerRequestInterface $request,
        array $listing,
        int $page
    ): ?string {
        if ($page < 1 || $page > $listing['pages']) {
            return null;
        }

        $params = array_filter([
            'q' => $listing['q'],
            'category' => $listing['category'],
            'sort' => $listing['sort'] !== 'newest' ? $listing['sort'] : '',
            'page' => $page !== 1 ? (string) $page : '',
        ], static fn (string $value): bool => $value !== '');

        $query = $params === [] ? '' : '?' . http_build_query($params);

        return $request->getUri()->getPath() . $query;
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(ErrorHandler::page(404));

        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
