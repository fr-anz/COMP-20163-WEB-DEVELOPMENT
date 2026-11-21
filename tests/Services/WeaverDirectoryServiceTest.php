<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\HeritageUpgrade;
use Habi\Bootstrap\Initializer;
use Habi\Services\WeaverDirectoryService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;

final class WeaverDirectoryServiceTest extends IsolatedDatabaseTestCase
{
    private WeaverDirectoryService $weavers;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->weavers = new WeaverDirectoryService($this->database());
    }

    public function testListsActiveWeaversOnlyAndNeverThePlaceholder(): void
    {
        $placeholder = HeritageUpgrade::placeholderWeaverId($this->database());
        $slugs = array_column($this->weavers->listActive(), 'slug');
        self::assertNotEmpty($slugs);
        self::assertNotContains(HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG, $slugs);

        $hidden = $slugs[0];
        $this->database()->prepare('UPDATE weavers SET is_active = 0 WHERE slug = ?')->execute([$hidden]);
        // Even if the placeholder were switched on, it is never public.
        $this->database()->prepare('UPDATE weavers SET is_active = 1 WHERE id = ?')->execute([$placeholder]);

        $after = array_column($this->weavers->listActive(), 'slug');
        self::assertSame(array_values(array_diff($slugs, [$hidden])), $after);
        self::assertNull($this->weavers->findActiveBySlug($hidden));
        self::assertNull($this->weavers->findActiveBySlug(HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG));
        self::assertNull($this->weavers->findActiveBySlug('no-such-weaver'));
    }

    public function testProfileHasPublicFieldsOnly(): void
    {
        $slug = $this->weavers->listActive()[0]['slug'];
        $this->database()->prepare('UPDATE weavers SET photo_path = ?, story = ? WHERE slug = ?')
            ->execute(['/uploads/' . str_repeat('a', 32) . '.png', "One.\n\nTwo.", $slug]);

        $weaver = $this->weavers->findActiveBySlug($slug);
        self::assertIsArray($weaver);
        self::assertSame(
            ['id', 'slug', 'display_name', 'community', 'region', 'fibers', 'techniques', 'story', 'photo_path'],
            array_keys($weaver)
        );
        self::assertSame("One.\n\nTwo.", $weaver['story']);
        self::assertSame('/uploads/' . str_repeat('a', 32) . '.png', $weaver['photo_path']);
        self::assertArrayNotHasKey('email', $weaver);
        self::assertArrayNotHasKey('password_hash', $weaver);
    }
}
