<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Services\AdminActionException;
use Habi\Services\AdminProductService;
use Habi\Services\ImageUploadService;
use Habi\Services\StockService;
use Habi\Services\WeaverProductService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * A weaver's own bags (R36, R23) and stock/availability (R37).
 *
 * Every product is loaded with WeaverProductService::find(weaver, id),
 * which filters by owner in SQL; a miss is the same 404 whether the bag is
 * missing or another weaver's (N10). Dialogs are server-rendered overlays
 * reached by URL, as in the admin panel, so everything works without
 * JavaScript. Stock changes use the admin two-step review with the
 * reviewed value as expected_stock and are audited with the weaver as
 * actor. Admin-deactivated listings are shown read-only.
 */
final class WeaverProductController
{
    public function __construct(
        private readonly WeaverResponder $view,
        private readonly WeaverProductService $products,
        private readonly StockService $stock,
        private readonly ImageUploadService $uploads,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderProducts($request, $response, null);
    }

    public function createForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $feedback = $this->view->takeFeedback('product-new');

        return $this->renderProducts($request, $response->withStatus($feedback === null ? 200 : 422), [
            'type' => 'product',
            'product' => null,
            'values' => $feedback['values'] ?? $this->products->formValues(null, WeaverResponder::weaver($request)),
            'errors' => $feedback['errors'] ?? [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->products->validate(self::body($request), true);
        $values = $result['values'];
        $errors = $result['errors'];
        $image = $this->checkImage($request, $errors);
        if ($image === null && !isset($errors['image'])) {
            $errors['image'] = 'Upload a photo of the bag.';
        }
        if ($errors !== []) {
            $this->view->feedback('product-new', $values, $errors);

            return $this->view->redirect($response, '/weaver/products/new');
        }

        $path = $this->uploads->store($image);
        try {
            $this->products->create(WeaverResponder::weaverId($request), $values, $path);
        } catch (Throwable $failure) {
            $this->uploads->discard($path);
            throw $failure;
        }
        $this->view->flash('success', sprintf(
            '%s was added and is pending review. It appears in the shop once Habi approves it.',
            $values['name']
        ));

        return $this->view->redirect($response, '/weaver/products');
    }

    public function editForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $product = $this->product($request, $args);
        if ($product === null) {
            return $this->view->notFound($response);
        }
        $feedback = $this->view->takeFeedback('product-' . $product['id']);

        return $this->renderProducts($request, $response->withStatus($feedback === null ? 200 : 422), [
            'type' => 'product',
            'product' => $product,
            'values' => $feedback['values'] ?? $this->products->formValues($product, WeaverResponder::weaver($request)),
            'errors' => $feedback['errors'] ?? [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $product = $this->product($request, $args);
        if ($product === null) {
            return $this->view->notFound($response);
        }
        $result = $this->products->validate(self::body($request), false);
        $values = $result['values'];
        $errors = $result['errors'];
        $image = $this->checkImage($request, $errors);
        $form = '/weaver/products/' . $product['id'] . '/edit';
        if ($errors !== []) {
            $this->view->feedback('product-' . $product['id'], $values, $errors);

            return $this->view->redirect($response, $form);
        }

        $path = $image === null ? null : $this->uploads->store($image);
        try {
            $this->products->update(WeaverResponder::weaverId($request), $product['id'], $values, $path);
        } catch (Throwable $failure) {
            if ($path !== null) {
                $this->uploads->discard($path);
            }
            if (!$failure instanceof AdminActionException) {
                throw $failure;
            }
            $this->view->flash('error', $failure->getMessage());

            return $this->view->redirect($response, $form);
        }
        $this->view->flash('success', sprintf('%s was saved.', $values['name']));

        return $this->view->redirect($response, '/weaver/products');
    }

    public function archiveForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $product = $this->product($request, $args);
        if ($product === null) {
            return $this->view->notFound($response);
        }

        return $this->renderProducts($request, $response, ['type' => 'archive', 'product' => $product]);
    }

    public function archive(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $product = $this->product($request, $args);
        if ($product === null) {
            return $this->view->notFound($response);
        }
        try {
            $this->products->archive(WeaverResponder::weaverId($request), $product['id']);
            $this->view->flash('success', sprintf(
                '%s was removed from the shop. Its order history is kept.',
                $product['name']
            ));
        } catch (AdminActionException $refused) {
            $this->view->flash('error', $refused->getMessage());
        }

        return $this->view->redirect($response, '/weaver/products');
    }

    public function availability(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $product = $this->product($request, $args);
        if ($product === null) {
            return $this->view->notFound($response);
        }
        $available = (self::body($request)['available'] ?? '') === '1';
        try {
            $this->products->setAvailable(WeaverResponder::weaverId($request), $product['id'], $available);
            $this->view->flash('success', $available
                ? sprintf('%s is available again.', $product['name'])
                : sprintf('%s is marked sold out.', $product['name']));
        } catch (AdminActionException $refused) {
            $this->view->flash('error', $refused->getMessage());
        }

        return $this->view->redirect($response, '/weaver/stock');
    }

    public function stockList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderStock($request, $response, null);
    }

    public function adjustForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $product = $this->product($request, $args);
        if ($product === null) {
            return $this->view->notFound($response);
        }
        $feedback = $this->view->takeFeedback('adjust-' . $product['id']);

        return $this->renderStock($request, $response->withStatus($feedback === null ? 200 : 422), [
            'type' => 'adjust',
            'product' => $product,
            'values' => $feedback['values'] ?? self::stockValues($request->getQueryParams()),
            'errors' => $feedback['errors'] ?? [],
        ]);
    }

    public function review(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $product = $this->product($request, $args);
        if ($product === null) {
            return $this->view->notFound($response);
        }
        $values = self::stockValues($request->getQueryParams());
        try {
            $preview = $this->stock->preview(
                $product['id'],
                $values['change'],
                $values['reason'],
                $values['note'],
                WeaverResponder::weaverId($request)
            );
        } catch (AdminActionException $invalid) {
            return $this->renderStock($request, $response->withStatus(422), [
                'type' => 'adjust',
                'product' => $product,
                'values' => $values,
                'errors' => [$invalid->field ?? 'change' => $invalid->getMessage()],
            ]);
        }

        return $this->renderStock($request, $response, [
            'type' => 'review',
            'product' => $product,
            'values' => $values,
            'preview' => $preview,
        ]);
    }

    public function adjust(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $product = $this->product($request, $args);
        if ($product === null) {
            return $this->view->notFound($response);
        }
        $id = $product['id'];
        $body = self::body($request);
        $values = self::stockValues($body);
        $expected = is_string($body['expected_stock'] ?? null) && ctype_digit($body['expected_stock'])
            ? (int) $body['expected_stock'] : -1;
        try {
            $result = $this->stock->adjustAsWeaver(
                $id,
                WeaverResponder::weaverId($request),
                $expected,
                $values['change'],
                $values['reason'],
                $values['note']
            );
        } catch (AdminActionException $failure) {
            if (isset($failure->context['current_stock'])) {
                $this->view->flash('error', $failure->getMessage());

                return $this->view->redirect(
                    $response,
                    '/weaver/stock/' . $id . '/review?' . http_build_query($values)
                );
            }
            if ($failure->field === null) {
                $this->view->flash('error', $product['listing_status'] === 'deactivated'
                    ? 'This listing was deactivated by Habi and can no longer be changed.'
                    : $failure->getMessage());

                return $this->view->redirect($response, '/weaver/stock');
            }
            $this->view->feedback('adjust-' . $id, $values, [$failure->field => $failure->getMessage()]);

            return $this->view->redirect($response, '/weaver/stock/' . $id . '/adjust');
        }
        $this->view->flash('success', sprintf(
            'Stock for %s changed from %d to %d.',
            $product['name'],
            $result['old_stock'],
            $result['new_stock']
        ));

        return $this->view->redirect($response, '/weaver/stock');
    }

    /**
     * The signed-in weaver's product named in the URL, or null (404).
     *
     * @param array<string, string> $args
     *
     * @return array<string, mixed>|null
     */
    private function product(ServerRequestInterface $request, array $args): ?array
    {
        return $this->products->find(WeaverResponder::weaverId($request), (int) ($args['id'] ?? 0));
    }

    /**
     * @param array<string, string> $errors receives an "image" error when invalid
     *
     * @return array{bytes: string, extension: string}|null
     */
    private function checkImage(ServerRequestInterface $request, array &$errors): ?array
    {
        $file = $request->getUploadedFiles()['image'] ?? null;
        if (!$file instanceof UploadedFileInterface || !ImageUploadService::provided($file)) {
            return null;
        }
        try {
            return $this->uploads->validate($file);
        } catch (AdminActionException $invalid) {
            $errors['image'] = $invalid->getMessage();

            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $dialog
     */
    private function renderProducts(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?array $dialog,
    ): ResponseInterface {
        return $this->view->render($response, 'products', [
            'active_nav' => 'products',
            'products' => $this->products->list(WeaverResponder::weaverId($request)),
            'dialog' => $dialog,
            'listing_labels' => WeaverProductService::LISTING_LABELS,
            'options' => [
                'categories' => array_combine(AdminProductService::CATEGORIES, AdminProductService::CATEGORIES),
                'fibers' => WeaverProductService::FIBER_LABELS,
            ],
        ]);
    }

    /**
     * @param array<string, mixed>|null $dialog
     */
    private function renderStock(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?array $dialog,
    ): ResponseInterface {
        return $this->view->render($response, 'stock', [
            'active_nav' => 'stock',
            'products' => $this->products->list(WeaverResponder::weaverId($request)),
            'dialog' => $dialog,
            'listing_labels' => WeaverProductService::LISTING_LABELS,
            'reasons' => StockService::REASONS,
        ]);
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array{change: string, reason: string, note: string}
     */
    private static function stockValues(array $source): array
    {
        return [
            'change' => is_string($source['change'] ?? null) ? $source['change'] : '',
            'reason' => is_string($source['reason'] ?? null) ? $source['reason'] : '',
            'note' => is_string($source['note'] ?? null) ? $source['note'] : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }
}
