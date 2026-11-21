<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Services\JournalService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Heritage Journal (R31): published articles only; drafts and unknown slugs
 * answer 404. Pages are indexable (R26).
 */
final class JournalController
{
    public function __construct(
        private readonly StorefrontResponder $view,
        private readonly JournalService $journal,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'storefront/journal.twig', [
            'articles' => $this->journal->published(),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->journal->findPublished((string) ($args['slug'] ?? ''));
        if ($article === null) {
            return $this->view->notFound($response);
        }

        return $this->view->render($response, 'storefront/article.twig', [
            'article' => $article,
        ]);
    }
}
