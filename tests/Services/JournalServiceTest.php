<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\JournalService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;

final class JournalServiceTest extends IsolatedDatabaseTestCase
{
    private JournalService $journal;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->database()->exec('DELETE FROM journal_articles');
        $insert = $this->database()->prepare(
            'INSERT INTO journal_articles (slug, title, excerpt, body, sources, status, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute(['older', 'Older', 'Older excerpt', 'Body', '', 'published', '2026-01-01 00:00:00']);
        $insert->execute(
            ['newer', 'Newer', 'Newer excerpt', "A.\n\nB.", 'Src. T. P, 2020.', 'published', '2026-03-01 00:00:00']
        );
        $insert->execute(['draft', 'Draft', 'Draft excerpt', 'Body', '', 'draft', null]);
        $this->journal = new JournalService($this->database());
    }

    public function testListsPublishedArticlesNewestFirstAndHidesDrafts(): void
    {
        $articles = $this->journal->published();

        self::assertSame(['newer', 'older'], array_column($articles, 'slug'));
        self::assertSame('Newer excerpt', $articles[0]['excerpt']);
        self::assertSame('2026-03-01', $articles[0]['published_on']);
    }

    public function testFindsOnlyPublishedArticlesBySlug(): void
    {
        $article = $this->journal->findPublished('newer');
        self::assertIsArray($article);
        self::assertSame("A.\n\nB.", $article['body']);
        self::assertSame('Src. T. P, 2020.', $article['sources']);
        self::assertNull($article['cover_path']);

        self::assertNull($this->journal->findPublished('draft'));
        self::assertNull($this->journal->findPublished('missing'));

        $this->database()->exec("UPDATE journal_articles SET status = 'draft' WHERE slug = 'newer'");
        self::assertNull($this->journal->findPublished('newer'));
        self::assertSame(['older'], array_column($this->journal->published(), 'slug'));
    }
}
