<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\ErrorHandler;
use Habi\Services\AdminActionException;
use Habi\Services\CategoryService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin categories (R41): list, Add/Rename dialogs (…/new, …/{id}/edit) and
 * deactivate/reactivate. Categories are never deleted; one with live
 * products cannot be deactivated (CategoryService).
 */
final class AdminCategoryController
{
    public function __construct(
        private readonly AdminResponder $view,
        private readonly CategoryService $categories,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($response, null);
    }

    public function createForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $feedback = $this->view->takeFeedback('category-new');

        return $this->render($response->withStatus($feedback === null ? 200 : 422), [
            'category' => null,
            'values' => $feedback['values'] ?? ['name' => ''],
            'errors' => $feedback['errors'] ?? [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->categories->validate(AdminResponder::body($request), null);
        if ($result['errors'] !== []) {
            $this->view->feedback('category-new', $result['values'], $result['errors']);

            return $this->view->redirect($response, '/admin/categories/new');
        }
        $this->categories->create($result['values']);
        $this->view->flash('success', sprintf('%s was added.', $result['values']['name']));

        return $this->view->redirect($response, '/admin/categories');
    }

    public function editForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $category = $this->categories->find((int) $args['id']);
        if ($category === null) {
            return $this->notFound($response);
        }
        $feedback = $this->view->takeFeedback('category-' . $category['id']);

        return $this->render($response->withStatus($feedback === null ? 200 : 422), [
            'category' => $category,
            'values' => $feedback['values'] ?? ['name' => $category['name']],
            'errors' => $feedback['errors'] ?? [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $category = $this->categories->find((int) $args['id']);
        if ($category === null) {
            return $this->notFound($response);
        }
        $result = $this->categories->validate(AdminResponder::body($request), $category['id']);
        if ($result['errors'] !== []) {
            $this->view->feedback('category-' . $category['id'], $result['values'], $result['errors']);

            return $this->view->redirect($response, '/admin/categories/' . $category['id'] . '/edit');
        }
        $this->categories->rename($category['id'], $result['values']);
        $this->view->flash('success', sprintf('%s was renamed to %s.', $category['name'], $result['values']['name']));

        return $this->view->redirect($response, '/admin/categories');
    }

    public function deactivate(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $category = $this->categories->find((int) $args['id']);
        if ($category === null) {
            return $this->notFound($response);
        }
        try {
            $this->categories->deactivate($category['id']);
            $this->view->flash(
                'success',
                sprintf('%s was deactivated and left the catalog filter.', $category['name'])
            );
        } catch (AdminActionException $failure) {
            $this->view->flash('error', $failure->getMessage());
        }

        return $this->view->redirect($response, '/admin/categories');
    }

    public function reactivate(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $category = $this->categories->find((int) $args['id']);
        if ($category === null) {
            return $this->notFound($response);
        }
        $this->categories->reactivate($category['id']);
        $this->view->flash('success', sprintf('%s can be used for products again.', $category['name']));

        return $this->view->redirect($response, '/admin/categories');
    }

    /** @param array<string, mixed>|null $dialog */
    private function render(ResponseInterface $response, ?array $dialog): ResponseInterface
    {
        return $this->view->render($response, 'categories', [
            'active_nav' => 'categories',
            'categories' => $this->categories->list(),
            'dialog' => $dialog,
            'max_name' => CategoryService::MAX_NAME_LENGTH,
        ]);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(ErrorHandler::page(404));

        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
