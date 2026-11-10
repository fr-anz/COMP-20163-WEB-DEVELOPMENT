<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\ErrorHandler;
use Habi\Services\AdminActionException;
use Habi\Services\AdminProductService;
use Habi\Services\ImageUploadService;
use Habi\Services\StockService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Admin products (R20, R23) and inventory/stock adjustments (R21, R18).
 *
 * Dialogs from the published admin reference are server-rendered overlays
 * on their list page, reached by real URLs (…/new, …/edit, …/archive,
 * …/adjust, …/review), so every flow works without JavaScript; admin.js
 * only adds focus containment and Esc. The list's q/stock filters travel
 * in each dialog URL and form action so closing or saving returns to the
 * same filtered list.
 */
final class AdminProductController
{
    public function __construct(
        private readonly AdminResponder $view,
        private readonly AdminProductService $products,
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
            'values' => $feedback['values'] ?? $this->products->formValues(null),
            'errors' => $feedback['errors'] ?? [],
            'message' => $feedback['message'] ?? null,
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->products->validate(AdminResponder::body($request), null);
        $values = $result['values'];
        $errors = $result['errors'];
        $file = $request->getUploadedFiles()['image'] ?? null;
        $file = $file instanceof UploadedFileInterface ? $file : null;
        $image = $this->checkImage($file, $errors);
        if ($image === null && !isset($errors['image']) && $values['demo_image'] === '') {
            $errors['image'] = 'Upload an image or choose a demo illustration.';
        }
        $form = '/admin/products/new' . $this->query($request);
        if ($errors !== []) {
            $this->view->feedback('product-new', $values, $errors);

            return $this->view->redirect($response, $form);
        }

        $path = $image === null ? $values['demo_image'] : $this->uploads->store($image);
        try {
            $this->products->create($values, $path, $this->view->adminId());
        } catch (Throwable $failure) {
            if ($image !== null) {
                $this->uploads->discard($path);
            }
            if (!$failure instanceof AdminActionException) {
                throw $failure;
            }
            $this->view->feedback('product-new', $values, [$failure->field ?? 'name' => $failure->getMessage()]);

            return $this->view->redirect($response, $form);
        }
        $this->view->flash('success', sprintf(
            '%s was added with %d in stock.',
            $values['name'],
            (int) $values['stock']
        ));

        return $this->view->redirect($response, '/admin/products' . $this->query($request));
    }

    public function editForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $product = $this->products->find((int) $args['id']);
        if ($product === null) {
            return $this->notFound($response);
        }
        $feedback = $this->view->takeFeedback('product-' . $product['id']);

        return $this->renderProducts($request, $response->withStatus($feedback === null ? 200 : 422), [
            'type' => 'product',
            'product' => $product,
            'values' => $feedback['values'] ?? $this->products->formValues($product),
            'errors' => $feedback['errors'] ?? [],
            'message' => $feedback['message'] ?? null,
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $product = $this->products->find((int) $args['id']);
        if ($product === null) {
            return $this->notFound($response);
        }
        $result = $this->products->validate(AdminResponder::body($request), $product['id']);
        $values = $result['values'];
        $errors = $result['errors'];
        $file = $request->getUploadedFiles()['image'] ?? null;
        $image = $this->checkImage($file instanceof UploadedFileInterface ? $file : null, $errors);
        $form = '/admin/products/' . $product['id'] . '/edit' . $this->query($request);
        if ($errors !== []) {
            $this->view->feedback('product-' . $product['id'], $values, $errors);

            return $this->view->redirect($response, $form);
        }

        $path = $image === null ? ($values['demo_image'] === '' ? null : $values['demo_image'])
            : $this->uploads->store($image);
        try {
            $this->products->update($product['id'], $values, $path);
        } catch (Throwable $failure) {
            if ($image !== null && $path !== null) {
                $this->uploads->discard($path);
            }
            if (!$failure instanceof AdminActionException) {
                throw $failure;
            }
            $this->view->feedback('product-' . $product['id'], $values, [
                $failure->field ?? 'name' => $failure->getMessage(),
            ]);

            return $this->view->redirect($response, $form);
        }
        $this->view->flash('success', sprintf('%s was saved.', $values['name']));

        return $this->view->redirect($response, '/admin/products' . $this->query($request));
    }

    public function archiveForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $product = $this->products->find((int) $args['id']);
        if ($product === null) {
            return $this->notFound($response);
        }

        return $this->renderProducts($request, $response, ['type' => 'archive', 'product' => $product]);
    }

    public function archive(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->setActive($request, $response, (int) $args['id'], false);
    }

    public function restore(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->setActive($request, $response, (int) $args['id'], true);
    }

    public function inventory(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderInventory($request, $response, null);
    }

    public function adjustForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $product = $this->products->find((int) $args['id']);
        if ($product === null) {
            return $this->notFound($response);
        }
        $feedback = $this->view->takeFeedback('adjust-' . $product['id']);
        $query = $request->getQueryParams();

        return $this->renderInventory($request, $response->withStatus($feedback === null ? 200 : 422), [
            'type' => 'adjust',
            'product' => $product,
            'values' => $feedback['values'] ?? [
                'change' => is_string($query['change'] ?? null) ? $query['change'] : '0',
                'reason' => is_string($query['reason'] ?? null) ? $query['reason'] : '',
                'note' => is_string($query['note'] ?? null) ? $query['note'] : '',
            ],
            'errors' => $feedback['errors'] ?? [],
        ]);
    }

    public function review(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $product = $this->products->find((int) $args['id']);
        if ($product === null) {
            return $this->notFound($response);
        }
        $query = $request->getQueryParams();
        $values = [
            'change' => is_string($query['change'] ?? null) ? $query['change'] : '',
            'reason' => is_string($query['reason'] ?? null) ? $query['reason'] : '',
            'note' => is_string($query['note'] ?? null) ? $query['note'] : '',
        ];
        try {
            $preview = $this->stock->preview($product['id'], $values['change'], $values['reason'], $values['note']);
        } catch (AdminActionException $invalid) {
            return $this->renderInventory($request, $response->withStatus(422), [
                'type' => 'adjust',
                'product' => $product,
                'values' => $values,
                'errors' => [$invalid->field ?? 'change' => $invalid->getMessage()],
            ]);
        }

        return $this->renderInventory($request, $response, [
            'type' => 'review',
            'product' => $product,
            'values' => $values,
            'preview' => $preview,
        ]);
    }

    public function adjust(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $body = AdminResponder::body($request);
        $values = [
            'change' => is_string($body['change'] ?? null) ? $body['change'] : '',
            'reason' => is_string($body['reason'] ?? null) ? $body['reason'] : '',
            'note' => is_string($body['note'] ?? null) ? $body['note'] : '',
        ];
        $expected = is_string($body['expected_stock'] ?? null) && ctype_digit($body['expected_stock'])
            ? (int) $body['expected_stock'] : -1;
        $list = $this->listQuery($request);
        try {
            $result = $this->stock->adjust(
                $id,
                $expected,
                $values['change'],
                $values['reason'],
                $values['note'],
                $this->view->adminId()
            );
        } catch (AdminActionException $failure) {
            if (isset($failure->context['current_stock'])) {
                // Re-review against the new live stock instead of applying blindly.
                $this->view->flash('error', $failure->getMessage());

                return $this->view->redirect($response, '/admin/inventory/' . $id . '/review?'
                    . http_build_query(array_merge($values, $list)));
            }
            if ($failure->field === null) {
                $this->view->flash('error', $failure->getMessage());

                return $this->view->redirect($response, '/admin/inventory' . $this->query($request));
            }
            $this->view->feedback('adjust-' . $id, $values, [$failure->field => $failure->getMessage()]);

            return $this->view->redirect($response, '/admin/inventory/' . $id . '/adjust' . $this->query($request));
        }
        $product = $this->products->find($id);
        $this->view->flash('success', sprintf(
            'Stock for %s changed from %d to %d. The change is in the stock audit.',
            $product['name'] ?? 'the product',
            $result['old_stock'],
            $result['new_stock']
        ));

        return $this->view->redirect($response, '/admin/inventory' . $this->query($request));
    }

    /**
     * @param array<string, string> $errors receives an "image" error when invalid
     *
     * @return array{bytes: string, extension: string}|null
     */
    private function checkImage(?UploadedFileInterface $file, array &$errors): ?array
    {
        if (!ImageUploadService::provided($file)) {
            return null;
        }
        try {
            return $this->uploads->validate($file);
        } catch (AdminActionException $invalid) {
            $errors['image'] = $invalid->getMessage();

            return null;
        }
    }

    private function setActive(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id,
        bool $active,
    ): ResponseInterface {
        $product = $this->products->find($id);
        if ($product === null) {
            return $this->notFound($response);
        }
        $this->products->setActive($id, $active);
        $this->view->flash('success', $active
            ? sprintf('%s is visible in the storefront again.', $product['name'])
            : sprintf('%s was archived and hidden from the storefront. Order history is kept.', $product['name']));

        return $this->view->redirect($response, '/admin/products' . $this->query($request));
    }

    /**
     * @param array<string, mixed>|null $dialog
     */
    private function renderProducts(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?array $dialog,
    ): ResponseInterface {
        return $this->view->render($response, 'products', array_merge(
            $this->products->list($request->getQueryParams()),
            [
                'active_nav' => 'products',
                'list_query' => $this->query($request),
                'dialog' => $dialog,
                'options' => $this->options(),
            ]
        ));
    }

    /**
     * @param array<string, mixed>|null $dialog
     */
    private function renderInventory(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?array $dialog,
    ): ResponseInterface {
        return $this->view->render($response, 'inventory', array_merge(
            $this->products->list($request->getQueryParams()),
            [
                'active_nav' => 'inventory',
                'list_query' => $this->query($request),
                'dialog' => $dialog,
                'reasons' => StockService::REASONS,
            ]
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'categories' => AdminProductService::CATEGORIES,
            'shapes' => AdminProductService::SHAPES,
            'handle_styles' => AdminProductService::HANDLE_STYLES,
            'materials' => AdminProductService::MATERIALS,
            'linings' => AdminProductService::LININGS,
            'pockets' => AdminProductService::POCKETS,
            'demo_images' => $this->products->demoImages(),
        ];
    }

    /**
     * The list filters to carry through dialogs and redirects.
     *
     * @return array<string, string>
     */
    private function listQuery(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $kept = [];
        if (is_string($query['q'] ?? null) && trim($query['q']) !== '') {
            $kept['q'] = trim($query['q']);
        }
        if (
            is_string($query['stock'] ?? null) && in_array($query['stock'], AdminProductService::STOCK_FILTERS, true)
            && $query['stock'] !== 'all'
        ) {
            $kept['stock'] = $query['stock'];
        }

        return $kept;
    }

    private function query(ServerRequestInterface $request): string
    {
        $kept = $this->listQuery($request);

        return $kept === [] ? '' : '?' . http_build_query($kept);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(ErrorHandler::page(404));

        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
