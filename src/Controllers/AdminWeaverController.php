<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\ErrorHandler;
use Habi\Services\AdminActionException;
use Habi\Services\AdminWeaverService;
use Habi\Services\ImageUploadService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Admin weaver accounts (R40) and weaver-profile content (R43). The form is
 * a page (…/new, …/{id}/edit) with an optional photo; Set password and
 * Deactivate are server-rendered dialogs on the list. A generated password
 * is shown once in the next flash and never again; entered passwords are
 * never echoed or kept in form feedback.
 */
final class AdminWeaverController
{
    public function __construct(
        private readonly AdminResponder $view,
        private readonly AdminWeaverService $weavers,
        private readonly ImageUploadService $uploads,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderList($response, null);
    }

    public function createForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderForm($response, null);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->weavers->validate(AdminResponder::body($request), null);
        $errors = $result['errors'];
        $photo = $this->checkPhoto($request, $errors);
        if ($errors !== []) {
            $this->view->feedback('weaver-new', array_merge($result['values'], ['password' => '']), $errors);

            return $this->view->redirect($response, '/admin/weavers/new');
        }
        $path = $photo === null ? null : $this->uploads->store($photo);
        try {
            $created = $this->weavers->create($result['values'], $this->view->adminId(), $path);
        } catch (Throwable $failure) {
            if ($path !== null) {
                $this->uploads->discard($path);
            }
            if (!$failure instanceof AdminActionException) {
                throw $failure;
            }
            $this->view->feedback('weaver-new', array_merge($result['values'], ['password' => '']), [
                $failure->field ?? 'email' => $failure->getMessage(),
            ]);

            return $this->view->redirect($response, '/admin/weavers/new');
        }
        $this->view->flash('success', $created['password'] === null
            ? sprintf('%s can now sign in at /weaver/login.', $result['values']['display_name'])
            : sprintf(
                '%s can now sign in at /weaver/login with the temporary password %s — share it now;'
                . ' it is not shown again.',
                $result['values']['display_name'],
                $created['password']
            ));

        return $this->view->redirect($response, '/admin/weavers');
    }

    public function editForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $weaver = $this->weavers->find((int) $args['id']);

        return $weaver === null ? $this->notFound($response) : $this->renderForm($response, $weaver);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $weaver = $this->weavers->find((int) $args['id']);
        if ($weaver === null) {
            return $this->notFound($response);
        }
        $form = '/admin/weavers/' . $weaver['id'] . '/edit';
        $result = $this->weavers->validate(AdminResponder::body($request), $weaver['id']);
        $errors = $result['errors'];
        $photo = $this->checkPhoto($request, $errors);
        if ($errors !== []) {
            $this->view->feedback('weaver-' . $weaver['id'], $result['values'], $errors);

            return $this->view->redirect($response, $form);
        }
        $path = $photo === null ? null : $this->uploads->store($photo);
        try {
            $this->weavers->update($weaver['id'], $result['values'], $path);
        } catch (Throwable $failure) {
            if ($path !== null) {
                $this->uploads->discard($path);
            }
            if (!$failure instanceof AdminActionException) {
                throw $failure;
            }
            $this->view->feedback('weaver-' . $weaver['id'], $result['values'], [
                $failure->field ?? 'email' => $failure->getMessage(),
            ]);

            return $this->view->redirect($response, $form);
        }
        $this->view->flash('success', sprintf('%s was saved.', $result['values']['display_name']));

        return $this->view->redirect($response, '/admin/weavers');
    }

    public function passwordForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $weaver = $this->weavers->find((int) $args['id']);
        if ($weaver === null) {
            return $this->notFound($response);
        }
        $feedback = $this->view->takeFeedback('weaver-password-' . $weaver['id']);

        return $this->renderList($response->withStatus($feedback === null ? 200 : 422), [
            'type' => 'password',
            'weaver' => $weaver,
            'errors' => $feedback['errors'] ?? [],
        ]);
    }

    public function setPassword(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $weaver = $this->weavers->find((int) $args['id']);
        if ($weaver === null) {
            return $this->notFound($response);
        }
        $password = AdminResponder::body($request)['password'] ?? '';
        try {
            $generated = $this->weavers->setPassword($weaver['id'], is_string($password) ? $password : '');
        } catch (AdminActionException $failure) {
            $this->view->feedback('weaver-password-' . $weaver['id'], [], [
                $failure->field ?? 'password' => $failure->getMessage(),
            ]);

            return $this->view->redirect($response, '/admin/weavers/' . $weaver['id'] . '/password');
        }
        $this->view->flash('success', $generated === null
            ? sprintf('The password for %s was changed.', $weaver['display_name'])
            : sprintf(
                'The new temporary password for %s is %s — share it now; it is not shown again.',
                $weaver['display_name'],
                $generated
            ));

        return $this->view->redirect($response, '/admin/weavers');
    }

    public function deactivateForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $weaver = $this->weavers->find((int) $args['id']);

        return $weaver === null || !$weaver['is_active']
            ? $this->notFound($response)
            : $this->renderList($response, ['type' => 'deactivate', 'weaver' => $weaver]);
    }

    public function deactivate(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->setActive($response, (int) $args['id'], false);
    }

    public function reactivate(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->setActive($response, (int) $args['id'], true);
    }

    private function setActive(ResponseInterface $response, int $id, bool $active): ResponseInterface
    {
        $weaver = $this->weavers->find($id);
        if ($weaver === null) {
            return $this->notFound($response);
        }
        $this->weavers->setActive($id, $active);
        $this->view->flash('success', $active
            ? sprintf('%s can sign in again.', $weaver['display_name'])
            : sprintf('%s was deactivated and can no longer sign in. Their orders are kept.', $weaver['display_name']));

        return $this->view->redirect($response, '/admin/weavers');
    }

    /** @param array<string, mixed>|null $dialog */
    private function renderList(ResponseInterface $response, ?array $dialog): ResponseInterface
    {
        return $this->view->render($response, 'weavers', [
            'active_nav' => 'weavers',
            'weavers' => $this->weavers->list(),
            'dialog' => $dialog,
            'min_password' => AdminWeaverService::MIN_PASSWORD_LENGTH,
        ]);
    }

    /** @param array<string, mixed>|null $weaver */
    private function renderForm(ResponseInterface $response, ?array $weaver): ResponseInterface
    {
        $feedback = $this->view->takeFeedback($weaver === null ? 'weaver-new' : 'weaver-' . $weaver['id']);

        return $this->view->render($response->withStatus($feedback === null ? 200 : 422), 'weaver-form', [
            'active_nav' => 'weavers',
            'weaver' => $weaver,
            'values' => $feedback['values'] ?? $this->weavers->formValues($weaver),
            'errors' => $feedback['errors'] ?? [],
            'limits' => AdminWeaverService::LIMITS,
            'min_password' => AdminWeaverService::MIN_PASSWORD_LENGTH,
        ]);
    }

    /**
     * @param array<string, string> $errors receives a "photo" error when invalid
     *
     * @return array{bytes: string, extension: string}|null
     */
    private function checkPhoto(ServerRequestInterface $request, array &$errors): ?array
    {
        $file = $request->getUploadedFiles()['photo'] ?? null;
        if (!$file instanceof UploadedFileInterface || !ImageUploadService::provided($file)) {
            return null;
        }
        try {
            return $this->uploads->validate($file);
        } catch (AdminActionException $invalid) {
            $errors['photo'] = $invalid->getMessage();

            return null;
        }
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(ErrorHandler::page(404));

        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
