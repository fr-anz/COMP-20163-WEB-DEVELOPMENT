<?php

declare(strict_types=1);

namespace Habi\Services;

use PDO;
use PDOException;
use Throwable;

/**
 * Administrator product management (PRD R20, R23).
 *
 * Products are created, edited and archived (is_active = 0) — never deleted,
 * so order items keep their product. Prices are entered in pesos and stored
 * as exact integer centavos without floating point. Stock is set once on
 * creation (audited as initial_stock); afterwards it changes only through
 * StockService so every change is audited. The procedural 3D config is
 * built from an allowlist of shapes, handles, materials, linings and
 * pockets that the viewer understands, plus validated hex colors.
 */
final class AdminProductService
{
    public const CATEGORIES = ['Tote', 'Satchel', 'Sling', 'Clutch'];
    public const SHAPES = ['tote' => 'Tote', 'satchel' => 'Satchel', 'sling' => 'Sling', 'clutch' => 'Clutch'];
    /** Handle styles bagFactory.js actually builds for each shape (or none). */
    public const SHAPE_HANDLES = [
        'tote' => ['double_handles', 'none'],
        'satchel' => ['top_handle', 'none'],
        'sling' => ['crossbody_strap', 'none'],
        'clutch' => ['none'],
    ];
    public const HANDLE_STYLES = [
        'double_handles' => 'Double handles',
        'top_handle' => 'Top handle',
        'crossbody_strap' => 'Crossbody strap',
        'none' => 'No handle',
    ];
    public const MATERIALS = ['rattan' => 'Rattan-style weave', 'canvas' => 'Canvas', 'leather' => 'Leather-style'];
    public const LININGS = ['cotton' => 'Cotton', 'canvas' => 'Canvas'];
    public const POCKETS = [
        'interior_zip' => 'Interior zip pocket',
        'slip_pocket' => 'Slip pocket',
        'interior_slip' => 'Interior slip',
        'bottle_slip' => 'Bottle slip',
        'pen_slip' => 'Pen slip',
        'divider_pocket' => 'Divider pocket',
        'back_slip' => 'Back slip',
        'key_leash' => 'Key leash',
        'exterior_zip' => 'Exterior zip (outside, not modeled inside)',
    ];
    public const STOCK_FILTERS = ['all', 'in', 'low', 'out', 'archived'];
    public const DEFAULT_ORIGIN = 'Illustrative sample — origin unverified.';
    public const DEFAULT_MATERIALS = 'Illustrative materials: ';
    public const MAX_PRICE_CENTAVOS = 99_999_999;
    public const MAX_STOCK = 9999;

    public function __construct(private readonly PDO $pdo, private readonly string $publicDir)
    {
    }

    /**
     * Products for the admin tables, including archived ones.
     *
     * @param array<string, mixed> $filters q (name/SKU text), stock (STOCK_FILTERS)
     *
     * @return array{products: list<array<string, mixed>>, q: string, stock: string}
     */
    public function list(array $filters = []): array
    {
        $q = is_string($filters['q'] ?? null) ? trim($filters['q']) : '';
        $stock = is_string($filters['stock'] ?? null) && in_array($filters['stock'], self::STOCK_FILTERS, true)
            ? $filters['stock'] : 'all';

        $conditions = [];
        $parameters = [];
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
            $conditions[] = "(p.name LIKE :q_name ESCAPE '\\' OR p.sku LIKE :q_sku ESCAPE '\\')";
            $parameters['q_name'] = $like;
            $parameters['q_sku'] = $like;
        }
        $conditions[] = match ($stock) {
            'in' => 'p.is_active = 1 AND p.stock > p.low_stock_threshold',
            'low' => 'p.is_active = 1 AND p.stock > 0 AND p.stock <= p.low_stock_threshold',
            'out' => 'p.is_active = 1 AND p.stock = 0',
            'archived' => 'p.is_active = 0',
            default => '1 = 1',
        };

        $statement = $this->pdo->prepare(
            'SELECT p.id, p.sku, p.slug, p.name, p.category, p.price_centavos, p.stock,
                    p.low_stock_threshold, p.is_active,
                    (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id
                     ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path
             FROM products p WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY p.is_active DESC, p.id ASC'
        );
        $statement->execute($parameters);

        return [
            'products' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'sku' => (string) $row['sku'],
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'category' => (string) $row['category'],
                'price_centavos' => (int) $row['price_centavos'],
                'stock' => (int) $row['stock'],
                'low_stock_threshold' => (int) $row['low_stock_threshold'],
                'is_active' => (int) $row['is_active'] === 1,
                'image_path' => $row['image_path'] === null ? null : (string) $row['image_path'],
            ], $statement->fetchAll()),
            'q' => $q,
            'stock' => $stock,
        ];
    }

    /**
     * Dashboard inventory metrics plus active low-stock products.
     *
     * @return array{active: int, units: int, low_or_out: int, thresholds: list<int>,
     *     low_stock: list<array<string, mixed>>}
     */
    public function metrics(): array
    {
        $totals = $this->pdo->query(
            'SELECT COUNT(*) AS active, COALESCE(SUM(stock), 0) AS units,
                    COALESCE(SUM(stock <= low_stock_threshold), 0) AS low_or_out
             FROM products WHERE is_active = 1'
        )->fetch();
        $thresholds = $this->pdo->query(
            'SELECT DISTINCT low_stock_threshold FROM products WHERE is_active = 1 ORDER BY 1'
        )->fetchAll(PDO::FETCH_COLUMN);
        $low = $this->pdo->query(
            'SELECT id, name, stock, low_stock_threshold FROM products
             WHERE is_active = 1 AND stock <= low_stock_threshold ORDER BY stock ASC, id ASC'
        )->fetchAll();

        return [
            'active' => (int) $totals['active'],
            'units' => (int) $totals['units'],
            'low_or_out' => (int) $totals['low_or_out'],
            'thresholds' => array_map('intval', $thresholds),
            'low_stock' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'stock' => (int) $row['stock'],
                'low_stock_threshold' => (int) $row['low_stock_threshold'],
            ], $low),
        ];
    }

    /**
     * One product (active or archived) with decoded JSON and images.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        $images = $this->pdo->prepare(
            'SELECT id, path, is_primary FROM product_images WHERE product_id = ?
             ORDER BY is_primary DESC, sort_order ASC, id ASC'
        );
        $images->execute([$id]);
        $dimensions = json_decode((string) $row['dimensions'], true);
        $config = json_decode((string) $row['three_config'], true);

        return [
            'id' => (int) $row['id'],
            'sku' => (string) $row['sku'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'description' => (string) $row['description'],
            'materials' => (string) $row['materials'],
            'place_of_make' => (string) $row['place_of_make'],
            'price_centavos' => (int) $row['price_centavos'],
            'stock' => (int) $row['stock'],
            'low_stock_threshold' => (int) $row['low_stock_threshold'],
            'dimensions' => is_array($dimensions) ? $dimensions : [],
            'three_config' => is_array($config) ? $config : [],
            'is_active' => (int) $row['is_active'] === 1,
            'images' => $images->fetchAll(),
        ];
    }

    /**
     * Form values for an existing product (or defaults for a new one), in
     * the same shape validate() accepts and returns.
     *
     * @param array<string, mixed>|null $product
     *
     * @return array<string, mixed>
     */
    public function formValues(?array $product): array
    {
        $demo = $this->demoImages();
        if ($product === null) {
            return [
                'name' => '', 'sku' => '', 'category' => 'Tote', 'description' => 'Fictional demo: ',
                'materials' => self::DEFAULT_MATERIALS, 'place_of_make' => self::DEFAULT_ORIGIN,
                'price' => '', 'stock' => '0', 'low_stock_threshold' => '3',
                'width' => '', 'height' => '', 'depth' => '',
                'shape' => 'tote', 'handle_style' => 'double_handles', 'material' => 'canvas',
                'lining' => 'cotton', 'color_body' => '#C9A47A', 'color_trim' => '#6D5A4B',
                'color_lining' => '#F3E7D3', 'pockets' => ['interior_zip'],
                'demo_image' => $demo[0] ?? '',
            ];
        }
        $config = $product['three_config'];
        $dimensions = $product['dimensions'];

        return [
            'name' => $product['name'], 'sku' => $product['sku'], 'category' => $product['category'],
            'description' => $product['description'], 'materials' => $product['materials'],
            'place_of_make' => $product['place_of_make'],
            'price' => self::formatPesos($product['price_centavos']),
            'stock' => (string) $product['stock'],
            'low_stock_threshold' => (string) $product['low_stock_threshold'],
            'width' => (string) ($dimensions['width'] ?? ''), 'height' => (string) ($dimensions['height'] ?? ''),
            'depth' => (string) ($dimensions['depth'] ?? ''),
            'shape' => (string) ($config['shape'] ?? 'tote'),
            'handle_style' => (string) ($config['handle_style'] ?? 'none'),
            'material' => (string) ($config['material'] ?? 'canvas'),
            'lining' => (string) ($config['lining'] ?? 'cotton'),
            'color_body' => (string) ($config['colors']['body'] ?? '#C9A47A'),
            'color_trim' => (string) ($config['colors']['trim'] ?? '#6D5A4B'),
            'color_lining' => (string) ($config['colors']['lining'] ?? '#F3E7D3'),
            'pockets' => is_array($config['pockets'] ?? null) ? array_values($config['pockets']) : [],
            'demo_image' => '',
        ];
    }

    /**
     * Validates submitted form fields. Stock is only read when creating.
     *
     * @param array<string, mixed> $input
     *
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function validate(array $input, ?int $productId): array
    {
        $creating = $productId === null;
        $text = static fn (string $key): string => is_string($input[$key] ?? null)
            ? trim(str_replace("\r\n", "\n", $input[$key])) : '';
        $values = [];
        $errors = [];

        $values['name'] = preg_replace('/\s+/u', ' ', $text('name')) ?? '';
        if ($values['name'] === '' || mb_strlen($values['name']) > 80) {
            $errors['name'] = 'Enter a product name of up to 80 characters.';
        }

        $values['sku'] = strtoupper($text('sku'));
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{2,31}$/', $values['sku']) !== 1) {
            $errors['sku'] = 'Use 3–32 letters, numbers or hyphens, e.g. HBI-TOT-011.';
        } elseif ($this->skuTaken($values['sku'], $productId)) {
            $errors['sku'] = 'Another product already uses this SKU.';
        }

        $values['category'] = $text('category');
        if (!in_array($values['category'], self::CATEGORIES, true)) {
            $errors['category'] = 'Choose a category.';
        }

        $values['description'] = $text('description');
        if (mb_strlen($values['description']) > 2000) {
            $errors['description'] = 'Keep the description under 2,000 characters.';
        }
        foreach (['materials' => 'materials label', 'place_of_make' => 'place-of-make label'] as $key => $label) {
            $values[$key] = preg_replace('/\s+/u', ' ', $text($key)) ?? '';
            if ($values[$key] === '' || mb_strlen($values[$key]) > 200) {
                $errors[$key] = sprintf('Enter a %s of up to 200 characters.', $label);
            }
        }

        $values['price'] = $text('price');
        $values['price_centavos'] = self::parsePesos($values['price']);
        if ($values['price_centavos'] === null) {
            $errors['price'] = 'Enter a price in pesos, e.g. 1650 or 1,650.50.';
        }

        if ($creating) {
            $values['stock'] = $text('stock');
            if (preg_match('/^\d{1,4}$/', $values['stock']) !== 1) {
                $errors['stock'] = sprintf('Enter starting stock from 0 to %d.', self::MAX_STOCK);
            }
        }
        $values['low_stock_threshold'] = $text('low_stock_threshold');
        if (preg_match('/^\d{1,2}$/', $values['low_stock_threshold']) !== 1) {
            $errors['low_stock_threshold'] = 'Enter a threshold from 0 to 99.';
        }

        foreach (['width', 'height', 'depth'] as $key) {
            $values[$key] = $text($key);
            $centimetres = (int) $values[$key];
            if (preg_match('/^\d{1,3}$/', $values[$key]) !== 1 || $centimetres < 1 || $centimetres > 200) {
                $errors[$key] = 'Enter whole centimetres from 1 to 200.';
            }
        }

        foreach (
            [
                'shape' => self::SHAPES,
                'handle_style' => self::HANDLE_STYLES,
                'material' => self::MATERIALS,
                'lining' => self::LININGS,
            ] as $key => $allowed
        ) {
            $values[$key] = $text($key);
            if (!isset($allowed[$values[$key]])) {
                $errors[$key] = 'Choose one of the listed options.';
            }
        }
        if (
            !isset($errors['shape']) && !isset($errors['handle_style'])
            && !in_array($values['handle_style'], self::SHAPE_HANDLES[$values['shape']], true)
        ) {
            $errors['handle_style'] = sprintf(
                'A %s model supports: %s.',
                strtolower(self::SHAPES[$values['shape']]),
                implode(' or ', array_map(
                    static fn (string $style): string => strtolower(self::HANDLE_STYLES[$style]),
                    self::SHAPE_HANDLES[$values['shape']]
                ))
            );
        }
        foreach (['color_body', 'color_trim', 'color_lining'] as $key) {
            $values[$key] = strtoupper($text($key));
            if (preg_match('/^#[0-9A-F]{6}$/', $values[$key]) !== 1) {
                $errors[$key] = 'Use a hex colour such as #C9A47A.';
            }
        }
        $pockets = is_array($input['pockets'] ?? null) ? $input['pockets'] : [];
        $values['pockets'] = array_values(array_intersect(array_keys(self::POCKETS), $pockets));
        if (count($values['pockets']) !== count(array_unique(array_filter($pockets, 'is_string')))) {
            $errors['pockets'] = 'Choose pockets from the list.';
        }

        $values['demo_image'] = $text('demo_image');
        if ($values['demo_image'] !== '' && !in_array($values['demo_image'], $this->demoImages(), true)) {
            $errors['demo_image'] = 'Choose one of the demo illustrations.';
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Inserts a validated product, its first image and the initial-stock
     * audit row in one transaction.
     *
     * @param array<string, mixed> $values validate()['values'] without errors
     */
    public function create(array $values, string $imagePath, int $adminId): int
    {
        return $this->transaction(function () use ($values, $imagePath, $adminId): int {
            $stock = (int) $values['stock'];
            $this->pdo->prepare(
                'INSERT INTO products (sku, slug, name, category, description, materials, place_of_make,
                     price_centavos, stock, low_stock_threshold, dimensions, three_config, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            )->execute([
                $values['sku'], $this->uniqueSlug($values['name']), $values['name'], $values['category'],
                $values['description'], $values['materials'], $values['place_of_make'],
                $values['price_centavos'], $stock, (int) $values['low_stock_threshold'],
                $this->dimensionsJson($values), $this->configJson($values),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                'INSERT INTO product_images (product_id, path, sort_order, is_primary) VALUES (?, ?, 0, 1)'
            )->execute([$id, $imagePath]);
            if ($stock > 0) {
                $this->pdo->prepare(
                    "INSERT INTO stock_audit (product_id, admin_id, old_stock, new_stock, reason)
                     VALUES (?, ?, 0, ?, 'initial_stock')"
                )->execute([$id, $adminId, $stock]);
            }

            return $id;
        });
    }

    /**
     * Updates everything except stock; an optional new image becomes primary.
     *
     * @param array<string, mixed> $values validate()['values'] without errors
     */
    public function update(int $id, array $values, ?string $newImagePath): void
    {
        $this->transaction(function () use ($id, $values, $newImagePath): void {
            $update = $this->pdo->prepare(
                "UPDATE products SET sku = ?, name = ?, category = ?, description = ?, materials = ?,
                     place_of_make = ?, price_centavos = ?, low_stock_threshold = ?, dimensions = ?,
                     three_config = ?, updated_at = datetime('now')
                 WHERE id = ?"
            );
            $update->execute([
                $values['sku'], $values['name'], $values['category'], $values['description'],
                $values['materials'], $values['place_of_make'], $values['price_centavos'],
                (int) $values['low_stock_threshold'], $this->dimensionsJson($values),
                $this->configJson($values), $id,
            ]);
            if ($update->rowCount() !== 1) {
                throw new AdminActionException('That product no longer exists.');
            }
            if ($newImagePath !== null) {
                $this->pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$id]);
                $this->pdo->prepare(
                    'INSERT INTO product_images (product_id, path, sort_order, is_primary)
                     VALUES (?, ?, (SELECT COALESCE(MAX(sort_order), -1) + 1
                                    FROM product_images WHERE product_id = ?), 1)'
                )->execute([$id, $newImagePath, $id]);
            }
        });
    }

    /** Archives (false) or restores (true) a product; history is kept. */
    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE products SET is_active = ?, updated_at = datetime('now') WHERE id = ?"
        );
        $statement->execute([$active ? 1 : 0, $id]);
        if ($statement->rowCount() !== 1) {
            throw new AdminActionException('That product no longer exists.');
        }
    }

    /**
     * Seeded demo illustrations a new product may use instead of an upload.
     *
     * @return list<string> Public paths
     */
    public function demoImages(): array
    {
        $files = glob($this->publicDir . '/assets/img/products/*.svg') ?: [];
        sort($files);

        return array_map(static fn (string $file): string => '/assets/img/products/' . basename($file), $files);
    }

    /**
     * Exact peso string → centavos: digits with optional thousands commas and
     * up to two decimals. No floating point is involved.
     */
    public static function parsePesos(string $value): ?int
    {
        $value = trim(str_replace(['₱', ' '], '', $value));
        if (preg_match('/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?$/', $value, $match) !== 1) {
            return null;
        }
        $whole = ltrim(str_replace(',', '', $match[1]), '0');
        if (strlen($whole) > 6) {
            return null;
        }
        $centavos = (int) ($whole === '' ? '0' : $whole) * 100 + (int) str_pad($match[2] ?? '', 2, '0');

        return $centavos <= self::MAX_PRICE_CENTAVOS ? $centavos : null;
    }

    public static function formatPesos(int $centavos): string
    {
        return $centavos % 100 === 0
            ? (string) intdiv($centavos, 100)
            : sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }

    private function skuTaken(string $sku, ?int $exceptId): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ? AND id != ?');
        $statement->execute([$sku, $exceptId ?? 0]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(
            (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)
        )), '-');
        $base = $base === '' ? 'bag' : substr($base, 0, 60);
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE slug = ?');
        for ($suffix = 1;; $suffix++) {
            $slug = $suffix === 1 ? $base : $base . '-' . $suffix;
            $check->execute([$slug]);
            if ((int) $check->fetchColumn() === 0) {
                return $slug;
            }
        }
    }

    /** @param array<string, mixed> $values */
    private function dimensionsJson(array $values): string
    {
        return json_encode([
            'width' => (int) $values['width'],
            'height' => (int) $values['height'],
            'depth' => (int) $values['depth'],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $values */
    private function configJson(array $values): string
    {
        return json_encode([
            'shape' => $values['shape'],
            'dimensions' => [
                'width' => (int) $values['width'],
                'height' => (int) $values['height'],
                'depth' => (int) $values['depth'],
            ],
            'colors' => [
                'body' => $values['color_body'],
                'trim' => $values['color_trim'],
                'lining' => $values['color_lining'],
            ],
            'handle_style' => $values['handle_style'],
            'material' => $values['material'],
            'lining' => $values['lining'],
            'pockets' => $values['pockets'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (PDOException $exception) {
            $this->rollBack();
            if (str_contains($exception->getMessage(), 'products.sku')) {
                throw new AdminActionException('Another product already uses this SKU.', 'sku');
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBack();

            throw $exception;
        }
    }

    private function rollBack(): void
    {
        try {
            $this->pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // Already clean or broken; the original failure matters.
        }
    }
}
