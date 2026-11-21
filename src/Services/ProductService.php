<?php

declare(strict_types=1);

namespace Habi\Services;

use Habi\Bootstrap\HeritageUpgrade;
use Habi\Support\Translator;
use PDO;

/**
 * Read-only queries for the public storefront catalog. Only active products
 * are returned; procedural configs are fetched separately from catalog data.
 * A product marked unavailable (is_available = 0, R37) reports stock 0, so
 * it shows and behaves as sold out (R17).
 *
 * Money is integer centavos. Listing pages hold 8 products. Sorting is a
 * fixed allowlist ("featured", the deterministic id order, is the default) so
 * no user input ever reaches the ORDER BY clause. Fiber and region filters
 * accept only values that active products carry (R2).
 *
 * Language (R33): in Filipino, description, story and the category label
 * use the *_fil column, falling back to English when it is NULL or empty
 * (COALESCE(NULLIF(x_fil, ''), x)); the locale defaults to the request's.
 */
final class ProductService
{
    /**
     * Storefront visibility for alias `p`: an active listing whose weaver is
     * active, or the inactive placeholder weaver that owns upgraded v1 and
     * not-yet-reassigned products. Shared by the cart and checkout so a bag
     * hidden from the catalog cannot be bought either.
     */
    public const VISIBLE = "p.listing_status = 'active' AND EXISTS (SELECT 1 FROM weavers vw"
        . " WHERE vw.id = p.weaver_id AND (vw.is_active = 1 OR vw.slug = '"
        . HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG . "'))";

    private const PER_PAGE = 8;

    /**
     * Fiber names in English, in HeritageUpgrade::FIBERS order; storefront
     * templates show the fiber.* catalog keys instead.
     */
    public const FIBER_LABELS = [
        'buri' => 'Buri',
        'pandan' => 'Pandan',
        'tikog' => 'Tikog',
        'abaca' => 'Abaca',
        'other' => 'Other fiber',
    ];

    /** Catalog columns shared by every product query ("p" is products). */
    private const COLUMNS = 'p.id, p.slug, p.name, p.category, p.description, p.materials,
        p.place_of_make, p.price_centavos, CASE WHEN p.is_available = 1 THEN p.stock ELSE 0 END AS stock,
        p.low_stock_threshold, p.dimensions, p.fiber, p.community, p.region, p.technique,
        COALESCE(NULLIF(p.description_fil, \'\'), p.description) AS description_fil,
        (SELECT COALESCE(NULLIF(c.name_fil, \'\'), c.name) FROM categories c
         WHERE c.id = p.category_id) AS category_fil,
        (SELECT pi.path FROM product_images pi
         WHERE pi.product_id = p.id
         ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
         LIMIT 1) AS image_path';

    /**
     * Deterministic sort fragments; the user-facing sort key must exist here.
     *
     * @var array<string, string>
     */
    private const SORTS = [
        'featured' => 'p.id ASC',
        'newest' => 'p.created_at DESC, p.id DESC',
        'price_asc' => 'p.price_centavos ASC, p.id ASC',
        'price_desc' => 'p.price_centavos DESC, p.id DESC',
    ];

    private readonly string $locale;

    public function __construct(
        private readonly PDO $pdo,
        ?string $locale = null,
    ) {
        $this->locale = Translator::normalize($locale ?? Translator::current()->locale());
    }

    /**
     * Paginated, filtered catalog listing.
     *
     * Accepted filters: q (string), category (string), fiber and region
     * (values from fibers()/regions()), sort (allowlist key), page (positive
     * integer), hide_soldout (bool). Malformed or unknown values fall back to
     * their defaults; the page is clamped to the available range.
     *
     * @return array{
     *     products: list<array<string, mixed>>, total: int, page: int,
     *     pages: int, per_page: int, q: string, category: string, fiber: string,
     *     region: string, sort: string, hide_soldout: bool
     * }
     */
    public function list(array $filters = []): array
    {
        $q = $this->scalar($filters['q'] ?? '');
        $category = $this->scalar($filters['category'] ?? '');
        $sort = self::SORTS[$this->scalar($filters['sort'] ?? '')] ?? self::SORTS['featured'];
        $page = $this->positiveInt($filters['page'] ?? 1);
        $hideSoldout = (bool) ($filters['hide_soldout'] ?? false);
        $fiber = $this->scalar($filters['fiber'] ?? '');
        $fiber = isset($this->fibers()[$fiber]) ? $fiber : '';
        $region = $this->scalar($filters['region'] ?? '');
        $region = in_array($region, $this->regions(), true) ? $region : '';

        [$conditions, $parameters] = $this->filters($q, $category, $fiber, $region);
        if ($hideSoldout) {
            $conditions[] = 'p.stock > 0 AND p.is_available = 1';
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $countStatement = $this->pdo->prepare('SELECT COUNT(*) FROM products p' . $where);
        $countStatement->execute($parameters);
        $total = (int) $countStatement->fetchColumn();

        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM products p' . $where . '
             ORDER BY ' . $sort . '
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', self::PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * self::PER_PAGE, PDO::PARAM_INT);
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value, PDO::PARAM_STR);
        }
        $statement->execute();

        $products = array_map($this->hydrate(...), $statement->fetchAll());

        return [
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => self::PER_PAGE,
            'q' => $q,
            'category' => $category,
            'fiber' => $fiber,
            'region' => $region,
            'sort' => array_search($sort, self::SORTS, true),
            'hide_soldout' => $hideSoldout,
        ];
    }

    /**
     * Active categories present in the catalog, alphabetically.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT p.category
             FROM products p
             WHERE " . self::VISIBLE . "
             ORDER BY p.category ASC"
        );
        $statement->execute();

        return array_map(
            static fn (array $row): string => (string) $row['category'],
            $statement->fetchAll()
        );
    }

    /**
     * Category filter labels (category value => name in the active
     * language) for the categories active products carry.
     *
     * @return array<string, string>
     */
    public function categoryLabels(): array
    {
        $labels = [];
        $rows = $this->pdo->query(
            "SELECT DISTINCT p.category, COALESCE(NULLIF(c.name_fil, ''), c.name) AS name_fil
             FROM products p LEFT JOIN categories c ON c.id = p.category_id
             WHERE " . self::VISIBLE
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $category = (string) $row['category'];
            $labels[$category] = $this->locale === 'fil' && (string) $row['name_fil'] !== ''
                ? (string) $row['name_fil']
                : $category;
        }

        return $labels;
    }

    /**
     * Fibers carried by active products, key => label, in the controlled
     * list's order (R2 material filter options and allowlist).
     *
     * @return array<string, string>
     */
    public function fibers(): array
    {
        $present = $this->pdo->query(
            'SELECT DISTINCT p.fiber FROM products p WHERE ' . self::VISIBLE
        )->fetchAll(PDO::FETCH_COLUMN);

        $fibers = [];
        foreach (HeritageUpgrade::FIBERS as $fiber) {
            if (in_array($fiber, $present, true)) {
                $fibers[$fiber] = self::FIBER_LABELS[$fiber];
            }
        }

        return $fibers;
    }

    /**
     * Non-empty regions of origin carried by active products, alphabetically
     * (R2 region filter options and allowlist).
     *
     * @return list<string>
     */
    public function regions(): array
    {
        return array_map('strval', $this->pdo->query(
            'SELECT DISTINCT p.region FROM products p
             WHERE ' . self::VISIBLE . " AND TRIM(p.region) <> ''
             ORDER BY p.region ASC"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * A single active product by slug, or null when missing or inactive.
     * Adds the R29 story, whether a 3D config exists (R4) and the maker as
     * "weaver" — null unless the weaver is active and public, so the
     * placeholder weaver never appears on the storefront.
     *
     * @return array<string, mixed>|null
     */
    public function getBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ', p.story, COALESCE(NULLIF(p.story_fil, \'\'), p.story) AS story_fil,
                    (p.three_config IS NOT NULL AND TRIM(p.three_config) <> \'\') AS has_3d,
                    w.slug AS weaver_slug, w.display_name AS weaver_name
             FROM products p
             LEFT JOIN weavers w ON w.id = p.weaver_id AND w.is_active = 1 AND w.slug <> :placeholder
             WHERE p.slug = :slug AND ' . self::VISIBLE . '
             LIMIT 1'
        );
        $statement->execute(['slug' => $slug, 'placeholder' => HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $product = $this->hydrate($row);
        $product['story'] = (string) $row[$this->locale === 'fil' ? 'story_fil' : 'story'];
        $product['has_3d'] = (int) $row['has_3d'] === 1;
        $product['weaver'] = $row['weaver_slug'] === null ? null : [
            'slug' => (string) $row['weaver_slug'],
            'display_name' => (string) $row['weaver_name'],
        ];
        $images = $this->pdo->prepare(
            'SELECT path FROM product_images WHERE product_id = :id
             ORDER BY is_primary DESC, sort_order ASC, id ASC'
        );
        $images->execute(['id' => $product['id']]);
        $product['images'] = $images->fetchAll();

        return $product;
    }

    /**
     * Per-product procedural parameters, including for sold-out products;
     * null for missing/inactive products and products without a config (R4).
     *
     * @return array<string, mixed>|null
     */
    public function get3dConfig(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.three_config FROM products p WHERE p.slug = :slug AND ' . self::VISIBLE . ' LIMIT 1'
        );
        $statement->execute(['slug' => $slug]);
        $config = $statement->fetchColumn();
        if (!is_string($config) || trim($config) === '') {
            return null;
        }

        $decoded = json_decode($config, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Active products of one fiber in deterministic id order — the home
     * page material scenes link to the first one (R1).
     *
     * @return list<array<string, mixed>>
     */
    public function byFiber(string $fiber, int $limit = 3): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM products p
             WHERE ' . self::VISIBLE . ' AND p.fiber = :fiber
             ORDER BY p.id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':fiber', $fiber, PDO::PARAM_STR);
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /**
     * A weaver's active products in deterministic id order (R30 profile).
     *
     * @return list<array<string, mixed>>
     */
    public function byWeaver(int $weaverId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM products p
             WHERE ' . self::VISIBLE . ' AND p.weaver_id = :weaver
             ORDER BY p.id ASC'
        );
        $statement->bindValue(':weaver', $weaverId, PDO::PARAM_INT);
        $statement->execute();

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /**
     * Builds the WHERE clause shared by the count and listing queries.
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function filters(string $q, string $category, string $fiber, string $region): array
    {
        $conditions = [self::VISIBLE];
        $parameters = [];

        if ($q !== '') {
            // Literal text search: LIKE wildcards in the input are escaped so
            // a user typing "%" or "_" matches those characters only.
            $escaped = '%' . $this->escapeLike($q) . '%';
            $conditions[] = "(p.name LIKE :q_name ESCAPE '\\'"
                . " OR p.description LIKE :q_description ESCAPE '\\'"
                . " OR p.materials LIKE :q_materials ESCAPE '\\')";
            $parameters['q_name'] = $escaped;
            $parameters['q_description'] = $escaped;
            $parameters['q_materials'] = $escaped;
        }

        if ($category !== '') {
            $conditions[] = 'p.category = :category';
            $parameters['category'] = $category;
        }

        if ($fiber !== '') {
            $conditions[] = 'p.fiber = :fiber';
            $parameters['fiber'] = $fiber;
        }

        if ($region !== '') {
            $conditions[] = 'p.region = :region';
            $parameters['region'] = $region;
        }

        return [$conditions, $parameters];
    }

    /**
     * Escapes LIKE wildcards for use with an ESCAPE '\' clause.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * Normalizes a query parameter to a trimmed string; anything that is not
     * a string (e.g. a malformed array) falls back to the default.
     */
    private function scalar(mixed $value, string $default = ''): string
    {
        return is_string($value) ? trim($value) : $default;
    }

    /**
     * Normalizes a query parameter to a positive integer. Numeric strings
     * (the usual shape of query parameters) are accepted; anything else
     * falls back to the first page.
     */
    private function positiveInt(mixed $value): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return max(1, (int) $value);
        }

        return 1;
    }

    /**
     * Casts database columns to their public types and decodes JSON fields.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $dimensions = json_decode((string) $row['dimensions'], true);
        if (!is_array($dimensions)) {
            $dimensions = [];
        }

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'category_label' => $this->locale === 'fil' && (string) $row['category_fil'] !== ''
                ? (string) $row['category_fil']
                : (string) $row['category'],
            'description' => (string) $row[$this->locale === 'fil' ? 'description_fil' : 'description'],
            'materials' => (string) $row['materials'],
            'place_of_make' => (string) $row['place_of_make'],
            'price_centavos' => (int) $row['price_centavos'],
            'stock' => (int) $row['stock'],
            'low_stock_threshold' => (int) $row['low_stock_threshold'],
            'dimensions' => $dimensions,
            'fiber' => (string) $row['fiber'],
            'fiber_label' => self::FIBER_LABELS[(string) $row['fiber']] ?? self::FIBER_LABELS['other'],
            'community' => (string) $row['community'],
            'region' => (string) $row['region'],
            'technique' => (string) $row['technique'],
            'image_path' => $row['image_path'] === null ? null : (string) $row['image_path'],
        ];
    }
}
