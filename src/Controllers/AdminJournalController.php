<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\ErrorHandler;
use Habi\Services\AdminActionException;
use Habi\Services\AdminJournalService;
use Habi\Services\ImageUploadService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Admin Heritage Journal (R31, R43): article list, plain-text article form
 * page with an optional cover image (…/new, …/{id}/edit), and Publish /
 * Unpublish. Content rules live in AdminJournalService and PlainText.
 */
final class AdminJournalController
{
    public function __construct(
        private readonly AdminResponder $view,
        private readonly AdminJournalService $journal,
        private readonly ImageUploadService $uploads,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'journal', [
            'active_nav' => 'journal',
            'articles' => $this->journal->list(),
        ]);
    }

    public function createForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderForm($response, null);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->save($request, $response, null);
    }

    public function editForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $article = $this->journal->find((int) $args['id']);

        return $article === null ? $this->notFound($response) : $this->renderForm($response, $article);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->journal->find((int) $args['id']);

        return $article === null ? $this->notFound($response) : $this->save($request, $response, $article);
    }

    public function publish(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->setStatus($response, (int) $args['id'], true);
    }

    public function unpublish(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->setStatus($response, (int) $args['id'], false);
    }

    /** @param array<string, mixed>|null $article */
    private function save(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?array $article,
    ): ResponseInterface {
        $id = $article['id'] ?? null;
        $form = $id === null ? '/admin/journal/new' : '/admin/journal/' . $id . '/edit';
        $key = $id === null ? 'article-new' : 'article-' . $id;
        $result = $this->journal->validate(AdminResponder::body($request), $id);
        $errors = $result['errors'];
        $cover = null;
        $file = $request->getUploadedFiles()['cover'] ?? null;
        if ($file instanceof UploadedFileInterface && ImageUploadService::provided($file)) {
            try {
                $cover = $this->uploads->validate($file);
            } catch (AdminActionException $invalid) {
                $errors['cover'] = $invalid->getMessage();
            }
        }
        if ($errors !== []) {
            $this->view->feedback($key, $result['values'], $errors);

            return $this->view->redirect($response, $form);
        }
        $path = $cover === null ? null : $this->uploads->store($cover);
        try {
            if ($id === null) {
                $id = $this->journal->create($result['values'], $this->view->adminId(), $path);
            } else {
                $this->journal->update($id, $result['values'], $this->view->adminId(), $path);
            }
        } catch (Throwable $failure) {
            if ($path !== null) {
                $this->uploads->discard($path);
            }
            if (!$failure instanceof AdminActionException) {
                throw $failure;
            }
            $this->view->feedback($key, $result['values'], [$failure->field ?? 'title' => $failure->getMessage()]);

            return $this->view->redirect($response, $form);
        }
        $this->view->flash('success', $article === null
            ? sprintf('%s was saved as a draft.', $result['values']['title'])
            : sprintf('%s was saved.', $result['values']['title']));

        return $this->view->redirect($response, '/admin/journal');
    }

    private function setStatus(ResponseInterface $response, int $id, bool $publish): ResponseInterface
    {
        $article = $this->journal->find($id);
        if ($article === null) {
            return $this->notFound($response);
        }
        if ($publish) {
            $this->journal->publish($id, $this->view->adminId());
        } else {
            $this->journal->unpublish($id, $this->view->adminId());
        }
        $this->view->flash('success', $publish
            ? sprintf('%s is published in the Journal.', $article['title'])
            : sprintf('%s is back to draft and hidden from the Journal.', $article['title']));

        return $this->view->redirect($response, '/admin/journal');
    }

    /** @param array<string, mixed>|null $article */
    private function renderForm(ResponseInterface $response, ?array $article): ResponseInterface
    {
        $feedback = $this->view->takeFeedback($article === null ? 'article-new' : 'article-' . $article['id']);

        return $this->view->render($response->withStatus($feedback === null ? 200 : 422), 'article-form', [
            'active_nav' => 'journal',
            'article' => $article,
            'values' => $feedback['values'] ?? $this->journal->formValues($article),
            'errors' => $feedback['errors'] ?? [],
            'limits' => AdminJournalService::LIMITS,
        ]);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(ErrorHandler::page(404));

        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
