<?php

declare(strict_types=1);

namespace Habi\Services;

use Habi\Bootstrap\HeritageUpgrade;
use Habi\Support\Translator;
use PDO;

/**
 * Public, read-only weaver profiles for Meet the Weavers (R30). Only active
 * weavers are returned, never the inactive placeholder weaver, and only the
 * public profile fields (no email or credentials). Weaver sign-in and
 * self-service edits live in WeaverService. In Filipino the story is
 * story_fil, falling back to English when empty (R33).
 */
final class WeaverDirectoryService
{
    private const COLUMNS = 'id, slug, display_name, community, region, fibers, techniques, story, photo_path,
        COALESCE(NULLIF(story_fil, \'\'), story) AS story_fil';

    private readonly string $locale;

    public function __construct(
        private readonly PDO $pdo,
        ?string $locale = null,
    ) {
        $this->locale = Translator::normalize($locale ?? Translator::current()->locale());
    }

    /**
     * Active weavers in deterministic id order.
     *
     * @return list<array{id: int, slug: string, display_name: string, community: string, region: string,
     *     fibers: string, techniques: string, story: string, photo_path: ?string}>
     */
    public function listActive(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM weavers
             WHERE is_active = 1 AND slug <> :placeholder
             ORDER BY id ASC'
        );
        $statement->execute(['placeholder' => HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG]);

        return array_map($this->hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * One active, public weaver by slug, or null.
     *
     * @return array{id: int, slug: string, display_name: string, community: string, region: string,
     *     fibers: string, techniques: string, story: string, photo_path: ?string}|null
     */
    public function findActiveBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM weavers
             WHERE slug = :slug AND is_active = 1 AND slug <> :placeholder
             LIMIT 1'
        );
        $statement->execute(['slug' => $slug, 'placeholder' => HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, slug: string, display_name: string, community: string, region: string,
     *     fibers: string, techniques: string, story: string, photo_path: ?string}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'display_name' => (string) $row['display_name'],
            'community' => (string) $row['community'],
            'region' => (string) $row['region'],
            'fibers' => (string) $row['fibers'],
            'techniques' => (string) $row['techniques'],
            'story' => (string) $row[$this->locale === 'fil' ? 'story_fil' : 'story'],
            'photo_path' => (string) $row['photo_path'] === '' ? null : (string) $row['photo_path'],
        ];
    }
}
