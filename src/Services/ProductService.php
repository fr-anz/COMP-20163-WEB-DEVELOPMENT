<?php

declare(strict_types=1);

namespace Habi\Services;

use PDO;

/**
 * Read-only queries for the public storefront catalog. Only active products
 * are returned; procedural configs are fetched separately from catalog data.
 *
 * Money is integer centavos. Listing pages hold 6 products. Sorting is a
 * fixed allowlist so no user input ever reaches the ORDER BY clause.
 */
final class ProductService
{
    private const PER_PAGE = 6;

    /**
     * Deterministic sort fragments; the user-facing sort key must exist here.
     *
     * @var array<string, string>
     */
    private const SORTS = [
        'newest' => 'p.created_at DESC, p.id DESC',
        'price_asc' => 'p.price_centavos ASC, p.id ASC',
        'price_desc' => 'p.price_centavos DESC, p.id DESC',
    ];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Paginated, filtered catalog listing.
     *
     * Accepted filters: q (string), category (string), sort (allowlist key),
     * page (positive integer). Malformed values fall back to their defaults;
     * the page is clamped to the available range.
     *
     * @return array{
     *     products: list<array<string, mixed>>, total: int, page: int,
     *     pages: int, per_page: int, q: string, category: string, sort: string
     * }
     */
    public function list(array $filters = []): array
    {
        $q = $this->scalar($filters['q'] ?? '');
        $category = $this->scalar($filters['category'] ?? '');
        $sort = self::SORTS[$this->scalar($filters['sort'] ?? '')] ?? self::SORTS['newest'];
        $page = $this->positiveInt($filters['page'] ?? 1);

        [$conditions, $parameters] = $this->filters($q, $category);
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $countStatement = $this->pdo->prepare('SELECT COUNT(*) FROM products p' . $where);
        $countStatement->execute($parameters);
        $total = (int) $countStatement->fetchColumn();

        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $statement = $this->pdo->prepare(
            'SELECT p.id, p.slug, p.name, p.category, p.description, p.materials,
                    p.place_of_make, p.price_centavos, p.stock, p.low_stock_threshold,
                    p.dimensions,
                    (SELECT pi.path FROM product_images pi
                     WHERE pi.product_id = p.id
                     ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                     LIMIT 1) AS image_path
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
            'sort' => array_search($sort, self::SORTS, true),
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
             WHERE p.is_active = 1
             ORDER BY p.category ASC"
        );
        $statement->execute();

        return array_map(
            static fn (array $row): string => (string) $row['category'],
            $statement->fetchAll()
        );
    }

    /**
     * A single active product by slug, or null when missing or inactive.
     *
     * @return array<string, mixed>|null
     */
    public function getBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.slug, p.name, p.category, p.description, p.materials,
                    p.place_of_make, p.price_centavos, p.stock, p.low_stock_threshold,
                    p.dimensions,
                    (SELECT pi.path FROM product_images pi
                     WHERE pi.product_id = p.id
                     ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                     LIMIT 1) AS image_path
             FROM products p
             WHERE p.slug = :slug AND p.is_active = 1
             LIMIT 1'
        );
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $product = $this->hydrate($row);
        $images = $this->pdo->prepare(
            'SELECT path FROM product_images WHERE product_id = :id
             ORDER BY is_primary DESC, sort_order ASC, id ASC'
        );
        $images->execute(['id' => $product['id']]);
        $product['images'] = $images->fetchAll();

        return $product;
    }

    /**
     * Per-product procedural parameters, including for sold-out products.
     *
     * @return array<string, mixed>|null
     */
    public function get3dConfig(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT three_config FROM products WHERE slug = :slug AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['slug' => $slug]);
        $config = $statement->fetchColumn();

        return $config === false ? null : json_decode((string) $config, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Builds the WHERE clause shared by the count and listing queries.
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function filters(string $q, string $category): array
    {
        $conditions = ['p.is_active = 1'];
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
            'description' => (string) $row['description'],
            'materials' => (string) $row['materials'],
            'place_of_make' => (string) $row['place_of_make'],
            'price_centavos' => (int) $row['price_centavos'],
            'stock' => (int) $row['stock'],
            'low_stock_threshold' => (int) $row['low_stock_threshold'],
            'dimensions' => $dimensions,
            'image_path' => $row['image_path'] === null ? null : (string) $row['image_path'],
        ];
    }
}
