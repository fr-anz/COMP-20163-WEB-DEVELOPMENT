<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\AdminJournalService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;

/**
 * Heritage Journal management (PRD R31, R43, N2): plain-text articles
 * (HTML refused), one source per line with http(s) links only, unique
 * slugs, and draft/published with published_at set on first publication.
 */
final class AdminJournalServiceTest extends IsolatedDatabaseTestCase
{
    private AdminJournalService $journal;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->journal = new AdminJournalService($this->database());
        $this->adminId = (int) $this->database()->query('SELECT id FROM admins LIMIT 1')->fetchColumn();
    }

    public function testCreateStoresPlainTextAsADraft(): void
    {
        $result = $this->journal->validate($this->input(), null);
        self::assertSame([], $result['errors']);

        $id = $this->journal->create($result['values'], $this->adminId, null);

        $row = $this->journal->find($id);
        self::assertSame('the-banig-mat', $row['slug']);
        self::assertSame('draft', $row['status']);
        self::assertNull($row['published_at']);
        self::assertSame("Paragraph one.\n\nParagraph two.", $row['body']);
        self::assertSame(
            "Museum. Banig. Press, 2020. https://example.org/banig\nAuthor. Book. Press, 1999.",
            $row['sources']
        );
        self::assertSame($this->adminId, $row['updated_by_admin_id']);
    }

    public function testHtmlAndBadSourcesAreRefused(): void
    {
        $result = $this->journal->validate($this->input([
            'title' => '<em>Title</em>',
            'excerpt' => 'Click <a href="x">here</a>',
            'body' => '<script>alert(1)</script>',
            'sources' => 'Bad. Link. X, 2020. javascript:alert(1)',
        ]), null);

        self::assertSame(['title', 'excerpt', 'body', 'sources'], array_keys($result['errors']));
        self::assertArrayHasKey('body', $this->journal->validate($this->input(['body' => '  ']), null)['errors']);
    }

    public function testSlugsAreUniqueAndValidated(): void
    {
        $this->journal->create($this->journal->validate($this->input(), null)['values'], $this->adminId, null);

        $second = $this->journal->validate($this->input(), null);
        self::assertSame([], $second['errors'], 'A generated slug gets a suffix.');
        self::assertSame('the-banig-mat-2', $second['values']['slug']);
        foreach (['the-banig-mat', 'Bad Slug!'] as $slug) {
            self::assertArrayHasKey('slug', $this->journal->validate($this->input(['slug' => $slug]), null)['errors']);
        }
    }

    public function testPublishSetsPublishedAtOnceAndUnpublishReturnsToDraft(): void
    {
        $id = $this->journal->create($this->journal->validate($this->input(), null)['values'], $this->adminId, null);

        $this->journal->publish($id, $this->adminId);
        $published = $this->journal->find($id);
        self::assertSame('published', $published['status']);
        self::assertNotNull($published['published_at']);

        $this->database()->exec("UPDATE journal_articles SET published_at = '2026-01-01 00:00:00' WHERE id = " . $id);
        $this->journal->unpublish($id, $this->adminId);
        self::assertSame('draft', $this->journal->find($id)['status']);
        $this->journal->publish($id, $this->adminId);
        self::assertSame('2026-01-01 00:00:00', $this->journal->find($id)['published_at'], 'First date kept.');
    }

    public function testUpdateKeepsSlugAndFilipinoFields(): void
    {
        $id = $this->journal->create($this->journal->validate($this->input(), null)['values'], $this->adminId, null);
        $this->database()->exec("UPDATE journal_articles SET title_fil = 'Ang banig' WHERE id = " . $id);

        $edit = $this->journal->validate(
            $this->input(['title' => 'The Banig Mat, Revisited', 'slug' => 'the-banig-mat']),
            $id
        );
        self::assertSame([], $edit['errors']);
        $this->journal->update($id, $edit['values'], $this->adminId, '/uploads/cover.png');

        $row = $this->journal->find($id);
        self::assertSame(['The Banig Mat, Revisited', 'the-banig-mat', 'Ang banig', '/uploads/cover.png'], [
            $row['title'], $row['slug'], $row['title_fil'], $row['cover_path'],
        ]);
    }

    /** @param array<string, string> $overrides @return array<string, string> */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'title' => 'The Banig Mat',
            'slug' => '',
            'excerpt' => 'How mats are woven.',
            'body' => "Paragraph one.\r\n\r\n\r\nParagraph two.",
            'sources' => "Museum. Banig. Press, 2020. https://example.org/banig\n\nAuthor. Book. Press, 1999.\n",
        ], $overrides);
    }
}
