<?php

declare(strict_types=1);

namespace Habi\Services;

use Habi\Support\PlainText;
use PDO;
use PDOException;
use Throwable;

/**
 * Heritage Journal management (PRD R31, R43, N2). Title, excerpt and body
 * are plain text (HTML refused; body paragraphs separated by a blank line),
 * sources are one per line with only http(s) links (PlainText::sources).
 * Articles start as drafts; publishing sets published_at the first time and
 * keeps it afterwards, unpublishing returns the article to draft. Filipino
 * fields (*_fil) are not edited here (M14) and are never overwritten.
 */
final class AdminJournalService
{
    public const STATUSES = ['draft', 'published'];

    /** Field => maximum length. */
    public const LIMITS = ['title' => 160, 'slug' => 80, 'excerpt' => 300, 'body' => 20000, 'sources' => 4000];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->pdo->query(
            'SELECT id, slug, title, status, published_at, updated_at FROM journal_articles
             ORDER BY status = \'draft\' DESC, COALESCE(published_at, updated_at) DESC, id DESC'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM journal_articles WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['updated_by_admin_id'] = $row['updated_by_admin_id'] === null ? null : (int) $row['updated_by_admin_id'];

        return $row;
    }

    /**
     * @param array<string, mixed>|null $article
     *
     * @return array<string, string>
     */
    public function formValues(?array $article): array
    {
        $values = [];
        foreach (array_keys(self::LIMITS) as $field) {
            $values[$field] = $article === null ? '' : (string) $article[$field];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    public function validate(array $input, ?int $articleId): array
    {
        $raw = static fn (string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $values = [
            'title' => trim((string) preg_replace('/\s+/u', ' ', $raw('title'))),
            'slug' => strtolower(trim($raw('slug'))),
            'excerpt' => trim((string) preg_replace('/\s+/u', ' ', $raw('excerpt'))),
            'body' => PlainText::paragraphs($raw('body')),
        ];
        $sources = PlainText::sources($raw('sources'));
        $values['sources'] = $sources['value'];
        $errors = [];

        foreach (['title', 'excerpt', 'body'] as $field) {
            if (mb_strlen($values[$field]) > self::LIMITS[$field]) {
                $errors[$field] = sprintf('Keep this under %s characters.', number_format(self::LIMITS[$field]));
            } elseif (PlainText::hasMarkup($values[$field])) {
                $errors[$field] = 'Use plain text; HTML tags are not allowed.';
            }
        }
        if ($values['title'] === '' && !isset($errors['title'])) {
            $errors['title'] = 'Enter a title.';
        }
        if ($values['body'] === '' && !isset($errors['body'])) {
            $errors['body'] = 'Enter the article text.';
        }
        if ($sources['error'] !== null) {
            $errors['sources'] = $sources['error'];
        } elseif (mb_strlen($values['sources']) > self::LIMITS['sources']) {
            $errors['sources'] = 'Keep the sources under 4,000 characters.';
        }

        if ($values['slug'] === '') {
            $values['slug'] = $this->uniqueSlug($values['title'], $articleId);
        } elseif (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $values['slug']) !== 1 || strlen($values['slug']) > 80) {
            $errors['slug'] = 'Use lowercase letters, numbers and single hyphens, e.g. history-of-the-bayong.';
        } elseif ($this->slugTaken($values['slug'], $articleId)) {
            $errors['slug'] = 'Another article already uses this address.';
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** Inserts a draft. @param array<string, string> $values validate()['values'] without errors */
    public function create(array $values, int $adminId, ?string $coverPath): int
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO journal_articles (slug, title, excerpt, body, sources, cover_path, status,
                     updated_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)"
            )->execute([
                $values['slug'], $values['title'], $values['excerpt'], $values['body'], $values['sources'],
                $coverPath, $adminId,
            ]);
        } catch (PDOException $exception) {
            throw $this->slugConflict($exception);
        }

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, string> $values validate()['values'] without errors */
    public function update(int $id, array $values, int $adminId, ?string $coverPath): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE journal_articles SET slug = ?, title = ?, excerpt = ?, body = ?, sources = ?,
                 cover_path = COALESCE(?, cover_path), updated_by_admin_id = ?, updated_at = datetime('now')
             WHERE id = ?"
        );
        try {
            $statement->execute([
                $values['slug'], $values['title'], $values['excerpt'], $values['body'], $values['sources'],
                $coverPath, $adminId, $id,
            ]);
        } catch (PDOException $exception) {
            throw $this->slugConflict($exception);
        }
        if ($statement->rowCount() !== 1) {
            throw new AdminActionException('That article no longer exists.');
        }
    }

    public function publish(int $id, int $adminId): void
    {
        $this->setStatus($id, 'published', $adminId);
    }

    public function unpublish(int $id, int $adminId): void
    {
        $this->setStatus($id, 'draft', $adminId);
    }

    private function setStatus(int $id, string $status, int $adminId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE journal_articles SET status = :status,
                 published_at = CASE WHEN :status_check = 'published' THEN COALESCE(published_at, datetime('now'))
                     ELSE published_at END,
                 updated_by_admin_id = :admin, updated_at = datetime('now')
             WHERE id = :id"
        );
        $statement->execute(['status' => $status, 'status_check' => $status, 'admin' => $adminId, 'id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new AdminActionException('That article no longer exists.');
        }
    }

    private function slugConflict(PDOException $exception): Throwable
    {
        return str_contains($exception->getMessage(), 'journal_articles.slug')
            ? new AdminActionException('Another article already uses this address.', 'slug')
            : $exception;
    }

    private function slugTaken(string $slug, ?int $exceptId): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM journal_articles WHERE slug = ? AND id != ?');
        $statement->execute([$slug, $exceptId ?? 0]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function uniqueSlug(string $title, ?int $exceptId): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(
            (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title)
        )), '-');
        $base = $base === '' ? 'article' : rtrim(substr($base, 0, 70), '-');
        for ($suffix = 1;; $suffix++) {
            $slug = $suffix === 1 ? $base : $base . '-' . $suffix;
            if (!$this->slugTaken($slug, $exceptId)) {
                return $slug;
            }
        }
    }
}
