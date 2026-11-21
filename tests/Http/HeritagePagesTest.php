<?php

declare(strict_types=1);

namespace Habi\Tests\Http;

use Habi\Bootstrap\HeritageUpgrade;
use Habi\Bootstrap\Initializer;
use Habi\Controllers\JournalController;
use Habi\Controllers\StorefrontController;
use Habi\Controllers\StorefrontResponder;
use Habi\Controllers\WeaversController;
use Habi\Services\CartService;
use Habi\Services\JournalService;
use Habi\Services\ProductService;
use Habi\Services\WeaverDirectoryService;
use Habi\Support\SessionStore;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use Habi\Tests\TestSupport\Views;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * M10 storefront pages through the real controllers, services and Twig
 * templates: weaver and journal routes (R30, R31, R26), the product story
 * (R29), shipping rates (R3), the 3D control only with a config (R4) and the
 * catalog material/region filters (R2).
 */
final class HeritagePagesTest extends IsolatedDatabaseTestCase
{
    /** @var array<string, mixed> */
    private array $session = [];
    private Psr17Factory $factory;
    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->factory = new Psr17Factory();
        $this->twig = Views::twig();
        $this->twig->addGlobal('site_url', 'https://habi.test');
        $this->twig->addGlobal('canonical_path', '/');
    }

    public function testWeaverListAndProfileShowOnlyActivePublicWeavers(): void
    {
        $pdo = $this->database();
        $placeholder = HeritageUpgrade::placeholderWeaverId($pdo);
        $weaver = $pdo->query(
            "SELECT w.slug, w.display_name, p.name AS bag FROM weavers w
             JOIN products p ON p.weaver_id = w.id WHERE w.is_active = 1 ORDER BY w.id LIMIT 1"
        )->fetch();
        $pdo->prepare('UPDATE weavers SET story = ? WHERE slug = ?')
            ->execute(["First <b>part</b>.\n\nSecond part.", $weaver['slug']]);

        $list = $this->weavers()->index($this->request('/weavers'), $this->factory->createResponse());
        $html = (string) $list->getBody();
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString('<title>Meet the weavers — Habi</title>', $html);
        self::assertStringContainsString('href="/weavers/' . $weaver['slug'] . '"', $html);
        self::assertStringNotContainsString('/weavers/' . HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG, $html);
        self::assertStringNotContainsString('noindex', $html);

        $profile = $this->weavers()->show(
            $this->request('/weavers/' . $weaver['slug']),
            $this->factory->createResponse(),
            ['slug' => $weaver['slug']]
        );
        $html = (string) $profile->getBody();
        self::assertSame(200, $profile->getStatusCode());
        self::assertStringContainsString('<meta property="og:type" content="profile">', $html);
        self::assertStringContainsString('<meta name="description" content="First &lt;b&gt;part&lt;/b&gt;.">', $html);
        self::assertStringContainsString('<p class="mt-4">First &lt;b&gt;part&lt;/b&gt;.</p>', $html);
        self::assertStringContainsString('<p class="mt-4">Second part.</p>', $html);
        self::assertStringContainsString('Bags by ' . $weaver['display_name'], $html);
        self::assertStringContainsString($weaver['bag'], $html);

        $pdo->prepare('UPDATE weavers SET is_active = 1 WHERE id = ?')->execute([$placeholder]);
        foreach ([HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG, 'missing'] as $slug) {
            $missing = $this->weavers()->show($this->request('/weavers/' . $slug), $this->factory->createResponse(), [
                'slug' => $slug,
            ]);
            self::assertSame(404, $missing->getStatusCode());
        }
        $pdo->prepare('UPDATE weavers SET is_active = 0 WHERE slug = ?')->execute([$weaver['slug']]);
        $gone = $this->weavers()->show($this->request('/'), $this->factory->createResponse(), [
            'slug' => $weaver['slug'],
        ]);
        self::assertSame(404, $gone->getStatusCode());
    }

    public function testJournalListsPublishedArticlesAndRendersBodyAndSources(): void
    {
        $pdo = $this->database();
        $pdo->exec('DELETE FROM journal_articles');
        $insert = $pdo->prepare(
            'INSERT INTO journal_articles (slug, title, excerpt, body, sources, status, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            'on-buri', 'On buri', 'Palm strips.', "Para one.\n\nPara two.",
            "Org. Title. Pub, 2020. https://example.org/a\nOther. Book. Press, 2019.",
            'published',
            '2026-02-03 10:00:00',
        ]);
        $insert->execute(['hidden', 'Hidden draft', '', 'Body', '', 'draft', null]);
        $insert->execute(['no-sources', 'No sources', '', 'Body', '', 'published', '2026-01-01 00:00:00']);

        $list = (string) $this->journal()
            ->index($this->request('/journal'), $this->factory->createResponse())
            ->getBody();
        self::assertStringContainsString('href="/journal/on-buri"', $list);
        self::assertStringContainsString('<time datetime="2026-02-03">3 February 2026</time>', $list);
        self::assertStringNotContainsString('Hidden draft', $list);

        $article = $this->journal()->show($this->request('/journal/on-buri'), $this->factory->createResponse(), [
            'slug' => 'on-buri',
        ]);
        $html = (string) $article->getBody();
        self::assertSame(200, $article->getStatusCode());
        self::assertStringContainsString('<title>On buri — Habi</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Palm strips.">', $html);
        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<p class="mt-4">Para two.</p>', $html);
        self::assertStringContainsString('<a class="link" href="https://example.org/a" rel="noopener">', $html);
        self::assertStringContainsString('<li>Other. Book. Press, 2019.</li>', $html);

        $noSources = (string) $this->journal()->show($this->request('/'), $this->factory->createResponse(), [
            'slug' => 'no-sources',
        ])->getBody();
        self::assertStringNotContainsString('id="sources"', $noSources);

        self::assertSame(404, $this->journal()->show($this->request('/'), $this->factory->createResponse(), [
            'slug' => 'hidden',
        ])->getStatusCode());
    }

    public function testProductPageShowsStoryShippingAndTheThreeDControlOnlyWithAConfig(): void
    {
        $pdo = $this->database();
        $weaver = $pdo->query(
            "SELECT w.slug, w.display_name FROM weavers w JOIN products p ON p.weaver_id = w.id
             WHERE p.slug = 'tala-sling'"
        )->fetch();
        $pdo->prepare(
            "UPDATE products SET fiber = 'abaca', community = 'Virac', region = 'Bicol Region',
                 technique = 'Loom weaving', story = ? WHERE slug = 'tala-sling'"
        )->execute(["Story one.\n\nStory two."]);

        $html = $this->productPage('tala-sling');
        self::assertStringContainsString(
            'href="/weavers/' . $weaver['slug'] . '">' . $weaver['display_name'] . '</a>',
            $html
        );
        foreach (['Virac', 'Bicol Region', 'Abaca', 'Loom weaving', '<p class="mt-3">Story two.</p>'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        $rates = [
            'Metro Manila', '&#8369;120', 'Luzon', '&#8369;180', 'Visayas', '&#8369;220', 'Mindanao', '&#8369;250',
        ];
        foreach ($rates as $rate) {
            self::assertStringContainsString($rate, $html);
        }
        self::assertStringContainsString('Free above ₱5,000.', $html);
        self::assertStringContainsString('data-view-toggle', $html);
        self::assertStringContainsString('data-viewer-dialog', $html);
        self::assertStringContainsString('type="importmap"', $html);

        $placeholder = HeritageUpgrade::placeholderWeaverId($pdo);
        $pdo->prepare('UPDATE products SET weaver_id = ?, three_config = NULL WHERE slug = ?')
            ->execute([$placeholder, 'tala-sling']);
        $html = $this->productPage('tala-sling');
        self::assertStringNotContainsString('/weavers/', substr($html, strpos($html, '<main')));
        self::assertStringNotContainsString(HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG, $html);
        self::assertStringNotContainsString('Unassigned weaver', $html);
        self::assertStringContainsString('Story two.', $html);
        self::assertStringNotContainsString('data-view-toggle', $html);
        self::assertStringNotContainsString('data-viewer', $html);
        self::assertStringNotContainsString('importmap', $html);
        self::assertStringContainsString('data-gallery-image', $html);

        $config = $this->storefront()->config3d($this->request('/'), $this->factory->createResponse(), [
            'slug' => 'tala-sling',
        ]);
        self::assertSame(404, $config->getStatusCode());
    }

    public function testCatalogFiltersByMaterialAndRegionAndKeepsThemInPagination(): void
    {
        $pdo = $this->database();
        $pdo->exec("UPDATE products SET fiber = 'buri', region = 'Bicol Region' WHERE slug = 'sinag-tote'");
        $insert = $pdo->prepare(
            "INSERT INTO products (sku, slug, name, weaver_id, category_id, category, fiber, region,
                 price_centavos, stock)
             VALUES (?, ?, ?, (SELECT MIN(id) FROM weavers), (SELECT id FROM categories WHERE name = 'Tote'),
                 'Tote', 'buri', 'Bicol Region', 100000, 3)"
        );
        for ($n = 1; $n <= 8; $n++) {
            $insert->execute(["F-$n", "fixture-$n", "Fixture $n"]);
        }

        $response = $this->storefront()->products(
            $this->request('/products', ['fiber' => 'buri', 'region' => 'Bicol Region', 'hide_sold_out' => '1']),
            $this->factory->createResponse()
        );
        $html = (string) $response->getBody();
        self::assertStringContainsString('<option value="buri" selected>Buri</option>', $html);
        self::assertStringContainsString('<option value="Bicol Region" selected>Bicol Region</option>', $html);
        self::assertStringContainsString('9 bags', $html);
        self::assertStringContainsString(
            'href="/products?fiber=buri&amp;region=Bicol+Region&amp;hide_sold_out=1&amp;page=2"',
            $html
        );
    }

    private function productPage(string $slug): string
    {
        $response = $this->storefront()->product(
            $this->request('/product/' . $slug),
            $this->factory->createResponse(),
            ['slug' => $slug]
        );
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** @param array<string, string> $query */
    private function request(string $path, array $query = []): ServerRequestInterface
    {
        return $this->factory->createServerRequest('GET', $path)->withQueryParams($query);
    }

    private function cart(): CartService
    {
        return new CartService($this->database(), new SessionStore($this->session));
    }

    private function weavers(): WeaversController
    {
        return new WeaversController(
            new StorefrontResponder($this->twig, $this->cart()),
            new WeaverDirectoryService($this->database()),
            new ProductService($this->database())
        );
    }

    private function journal(): JournalController
    {
        return new JournalController(
            new StorefrontResponder($this->twig, $this->cart()),
            new JournalService($this->database())
        );
    }

    private function storefront(): StorefrontController
    {
        return new StorefrontController($this->twig, new ProductService($this->database()), $this->cart());
    }
}
