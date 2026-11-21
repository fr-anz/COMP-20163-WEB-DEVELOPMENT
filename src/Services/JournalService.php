<?php

declare(strict_types=1);

namespace Habi\Services;

use PDO;

/**
 * Public, read-only Heritage Journal (R31): published articles only, newest
 * first. Body and sources are plain text, rendered by views/macros/content.twig.
 */
final class JournalService
{
    private const COLUMNS = 'slug, title, excerpt, body, sources, cover_path, published_at';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Published articles, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function published(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM journal_articles
             WHERE status = 'published'
             ORDER BY published_at DESC, id DESC"
        );
        $statement->execute();

        return array_map($this->hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * One published article by slug, or null (drafts are not public).
     *
     * @return array<string, mixed>|null
     */
    public function findPublished(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM journal_articles
             WHERE slug = :slug AND status = 'published'
             LIMIT 1"
        );
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{slug: string, title: string, excerpt: string, body: string, sources: string,
     *     cover_path: ?string, published_on: ?string}
     */
    private function hydrate(array $row): array
    {
        $publishedAt = $row['published_at'] === null ? '' : (string) $row['published_at'];

        return [
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'excerpt' => (string) $row['excerpt'],
            'body' => (string) $row['body'],
            'sources' => (string) $row['sources'],
            'cover_path' => (string) $row['cover_path'] === '' ? null : (string) $row['cover_path'],
            'published_on' => preg_match('/^\d{4}-\d{2}-\d{2}/', $publishedAt) === 1
                ? substr($publishedAt, 0, 10)
                : null,
        ];
    }
}
