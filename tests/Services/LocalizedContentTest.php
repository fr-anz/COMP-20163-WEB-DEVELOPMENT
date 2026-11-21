<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\JournalService;
use Habi\Services\ProductService;
use Habi\Services\WeaverDirectoryService;
use Habi\Support\Translator;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;

/**
 * R33 content fallback: in Filipino the read services return *_fil fields,
 * and English whenever the Filipino field is NULL or empty; English pages
 * never show Filipino content. The locale defaults to the request's.
 */
final class LocalizedContentTest extends IsolatedDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
    }

    protected function tearDown(): void
    {
        Translator::reset();
        parent::tearDown();
    }

    public function testProductDescriptionStoryAndCategoryFallBackToEnglish(): void
    {
        $pdo = $this->database();
        $slugs = $pdo->query("SELECT slug FROM products WHERE listing_status = 'active' ORDER BY id LIMIT 2")
            ->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->prepare(
            'UPDATE products SET description = ?, description_fil = ?, story = ?, story_fil = ? WHERE slug = ?'
        )
            ->execute(['English description.', 'Paglalarawan.', 'English story.', 'Kuwento.', $slugs[0]]);
        $pdo->prepare(
            'UPDATE products SET description = ?, description_fil = ?, story = ?, story_fil = NULL WHERE slug = ?'
        )
            ->execute(['Only English.', '', 'Only English story.', $slugs[1]]);
        $category = (string) $pdo->query("SELECT category FROM products WHERE slug = " . $pdo->quote($slugs[0]))
            ->fetchColumn();
        $pdo->prepare('UPDATE categories SET name_fil = ? WHERE id = (SELECT category_id FROM products WHERE slug = ?)')
            ->execute(['Bayong ' . $category, $slugs[0]]);

        $en = (new ProductService($pdo, 'en'))->getBySlug($slugs[0]);
        self::assertSame('English description.', $en['description']);
        self::assertSame('English story.', $en['story']);
        self::assertSame($category, $en['category_label']);

        $fil = (new ProductService($pdo, 'fil'))->getBySlug($slugs[0]);
        self::assertSame('Paglalarawan.', $fil['description']);
        self::assertSame('Kuwento.', $fil['story']);
        self::assertSame('Bayong ' . $category, $fil['category_label']);
        self::assertSame($category, $fil['category'], 'the filter value stays the stored category');

        $fallback = (new ProductService($pdo, 'fil'))->getBySlug($slugs[1]);
        self::assertSame('Only English.', $fallback['description']);
        self::assertSame('Only English story.', $fallback['story']);

        $listed = array_column((new ProductService($pdo, 'fil'))->list()['products'], 'description', 'slug');
        self::assertSame('Paglalarawan.', $listed[$slugs[0]]);
        self::assertSame('Bayong ' . $category, (new ProductService($pdo, 'fil'))->categoryLabels()[$category]);
        self::assertSame($category, (new ProductService($pdo, 'en'))->categoryLabels()[$category]);
    }

    public function testLocaleDefaultsToTheRequestTranslator(): void
    {
        $pdo = $this->database();
        $slug = (string) $pdo->query("SELECT slug FROM products WHERE listing_status = 'active' ORDER BY id LIMIT 1")
            ->fetchColumn();
        $pdo->prepare('UPDATE products SET description_fil = ? WHERE slug = ?')->execute(['Paglalarawan.', $slug]);

        Translator::setCurrent(Translator::current()->withLocale('fil'));
        self::assertSame('Paglalarawan.', (new ProductService($pdo))->getBySlug($slug)['description']);
        Translator::setCurrent(Translator::current()->withLocale('en'));
        self::assertNotSame('Paglalarawan.', (new ProductService($pdo))->getBySlug($slug)['description']);
    }

    public function testWeaverStoryFallsBackToEnglish(): void
    {
        $pdo = $this->database();
        $weavers = (new WeaverDirectoryService($pdo, 'en'))->listActive();
        self::assertGreaterThanOrEqual(2, count($weavers));
        $pdo->prepare('UPDATE weavers SET story = ?, story_fil = ? WHERE slug = ?')
            ->execute(['English story.', 'Kuwento ng manghahabi.', $weavers[0]['slug']]);
        $pdo->prepare('UPDATE weavers SET story = ?, story_fil = ? WHERE slug = ?')
            ->execute(['Second story.', '  ', $weavers[1]['slug']]);
        $pdo->prepare('UPDATE weavers SET story_fil = NULL WHERE slug = ?')->execute([$weavers[1]['slug']]);

        $en = (new WeaverDirectoryService($pdo, 'en'))->findActiveBySlug($weavers[0]['slug']);
        self::assertSame('English story.', $en['story']);
        self::assertSame(
            'Kuwento ng manghahabi.',
            (new WeaverDirectoryService($pdo, 'fil'))->findActiveBySlug($weavers[0]['slug'])['story']
        );
        $listed = array_column((new WeaverDirectoryService($pdo, 'fil'))->listActive(), 'story', 'slug');
        self::assertSame('Kuwento ng manghahabi.', $listed[$weavers[0]['slug']]);
        self::assertSame('Second story.', $listed[$weavers[1]['slug']]);
    }

    public function testJournalFieldsFallBackIndividually(): void
    {
        $pdo = $this->database();
        $slug = (string) $pdo->query("SELECT slug FROM journal_articles WHERE status = 'published' LIMIT 1")
            ->fetchColumn();
        $pdo->prepare(
            'UPDATE journal_articles SET title = ?, title_fil = ?, excerpt = ?, excerpt_fil = ?, body = ?, body_fil = ?
             WHERE slug = ?'
        )->execute(['Title', 'Pamagat', 'Excerpt', '', 'Body', null, $slug]);

        $fil = (new JournalService($pdo, 'fil'))->findPublished($slug);
        self::assertSame(['Pamagat', 'Excerpt', 'Body'], [$fil['title'], $fil['excerpt'], $fil['body']]);
        $en = (new JournalService($pdo, 'en'))->findPublished($slug);
        self::assertSame(['Title', 'Excerpt', 'Body'], [$en['title'], $en['excerpt'], $en['body']]);
        $titles = array_column((new JournalService($pdo, 'fil'))->published(), 'title', 'slug');
        self::assertSame('Pamagat', $titles[$slug]);
    }
}
