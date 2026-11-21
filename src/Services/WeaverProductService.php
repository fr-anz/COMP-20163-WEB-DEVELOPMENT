<?php

declare(strict_types=1);

namespace Habi\Services;

use Habi\Bootstrap\HeritageUpgrade;
use PDO;
use Throwable;

/**
 * A weaver's own listings (R36, R37, N10).
 *
 * Every read and write filters by `weaver_id = :current` in SQL; a product
 * owned by someone else behaves exactly like a missing one. New listings
 * start as pending_review (hidden from the storefront until an admin
 * approves them, R40) with a generated SKU and no 3D config: weavers never
 * edit 3D configs (R6), SKUs, owners or listing approval. A listing an
 * admin deactivated is read-only for its weaver, so it cannot be
 * reactivated, edited, archived or switched available. Validation follows
 * AdminProductService's rules and messages for the shared fields; stock is
 * set once on creation (audited as initial_stock) and afterwards changes
 * only through StockService.
 */
final class WeaverProductService
{
    public const LISTING_LABELS = [
        'pending_review' => 'Pending review',
        'active' => 'Active',
        'archived' => 'Archived',
        'deactivated' => 'Deactivated',
    ];

    public const FIBER_LABELS = [
        'buri' => 'Buri', 'pandan' => 'Pandan', 'tikog' => 'Tikog', 'abaca' => 'Abaca', 'other' => 'Other',
    ];

    private const DEACTIVATED = 'This listing was deactivated by Habi and can no longer be changed.';
    private const MISSING = 'That bag no longer exists.';

    /** Optional free-text fields => maximum length. */
    private const TEXT_LIMITS = [
        'community' => 120,
        'region' => 120,
        'technique' => 120,
        'description' => 2000,
        'story' => 4000,
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The weaver's listings in every status, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $weaverId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.sku, p.slug, p.name, p.category, p.fiber, p.price_centavos, p.stock,
                    p.low_stock_threshold, p.listing_status, p.is_available,
                    (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id
                     ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path
             FROM products p WHERE p.weaver_id = :weaver
             ORDER BY p.id DESC'
        );
        $statement->execute(['weaver' => $weaverId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'sku' => (string) $row['sku'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'fiber' => (string) $row['fiber'],
            'price_centavos' => (int) $row['price_centavos'],
            'stock' => (int) $row['stock'],
            'low_stock_threshold' => (int) $row['low_stock_threshold'],
            'listing_status' => (string) $row['listing_status'],
            'is_available' => (int) $row['is_available'] === 1,
            'image_path' => $row['image_path'] === null ? null : (string) $row['image_path'],
        ], $statement->fetchAll());
    }

    /**
     * Live (active or pending) listings at or below their low-stock threshold.
     *
     * @return list<array<string, mixed>>
     */
    public function lowStock(int $weaverId): array
    {
        return array_values(array_filter(
            $this->list($weaverId),
            static fn (array $product): bool => in_array($product['listing_status'], ['active', 'pending_review'], true)
                && $product['stock'] <= $product['low_stock_threshold']
        ));
    }

    /**
     * One of the weaver's own products with decoded dimensions and images,
     * or null when it is missing or belongs to another weaver.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $weaverId, int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, sku, slug, name, category, description, materials, place_of_make, fiber, community, region,
                    technique, story, price_centavos, stock, low_stock_threshold, dimensions, listing_status,
                    is_available
             FROM products WHERE id = :id AND weaver_id = :weaver'
        );
        $statement->execute(['id' => $id, 'weaver' => $weaverId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        $images = $this->pdo->prepare(
            'SELECT pi.id, pi.path, pi.is_primary FROM product_images pi
             JOIN products p ON p.id = pi.product_id
             WHERE pi.product_id = :id AND p.weaver_id = :weaver
             ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC'
        );
        $images->execute(['id' => $id, 'weaver' => $weaverId]);
        $dimensions = json_decode((string) $row['dimensions'], true);

        return [
            'id' => (int) $row['id'],
            'sku' => (string) $row['sku'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'description' => (string) $row['description'],
            'materials' => (string) $row['materials'],
            'place_of_make' => (string) $row['place_of_make'],
            'fiber' => (string) $row['fiber'],
            'community' => (string) $row['community'],
            'region' => (string) $row['region'],
            'technique' => (string) $row['technique'],
            'story' => (string) $row['story'],
            'price_centavos' => (int) $row['price_centavos'],
            'stock' => (int) $row['stock'],
            'low_stock_threshold' => (int) $row['low_stock_threshold'],
            'dimensions' => is_array($dimensions) ? $dimensions : [],
            'listing_status' => (string) $row['listing_status'],
            'is_available' => (int) $row['is_available'] === 1,
            'images' => $images->fetchAll(),
        ];
    }

    /**
     * Form values for an existing product, or defaults from the weaver's profile.
     *
     * @param array<string, mixed>|null $product
     * @param array<string, mixed> $weaver WeaverService::findActive() row
     *
     * @return array<string, string>
     */
    public function formValues(?array $product, array $weaver): array
    {
        if ($product === null) {
            $place = trim((string) ($weaver['community'] ?? ''));

            return [
                'name' => '', 'category' => 'Tote', 'fiber' => self::defaultFiber((string) ($weaver['fibers'] ?? '')),
                'materials' => '', 'place_of_make' => $place === '' ? AdminProductService::DEFAULT_ORIGIN : $place,
                'community' => (string) ($weaver['community'] ?? ''), 'region' => (string) ($weaver['region'] ?? ''),
                'technique' => (string) ($weaver['techniques'] ?? ''), 'description' => '', 'story' => '',
                'price' => '', 'stock' => '1', 'low_stock_threshold' => '1',
                'width' => '', 'height' => '', 'depth' => '',
            ];
        }
        $dimensions = $product['dimensions'];

        return [
            'name' => $product['name'], 'category' => $product['category'], 'fiber' => $product['fiber'],
            'materials' => $product['materials'], 'place_of_make' => $product['place_of_make'],
            'community' => $product['community'], 'region' => $product['region'],
            'technique' => $product['technique'], 'description' => $product['description'],
            'story' => $product['story'], 'price' => AdminProductService::formatPesos($product['price_centavos']),
            'stock' => (string) $product['stock'], 'low_stock_threshold' => (string) $product['low_stock_threshold'],
            'width' => (string) ($dimensions['width'] ?? ''), 'height' => (string) ($dimensions['height'] ?? ''),
            'depth' => (string) ($dimensions['depth'] ?? ''),
        ];
    }

    /**
     * Validates submitted listing fields. Fields a weaver may not set (SKU,
     * owner, listing status, 3D config) are ignored. Stock is read only when
     * creating.
     *
     * @param array<string, mixed> $input
     *
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function validate(array $input, bool $creating): array
    {
        $text = static fn (string $key): string => is_string($input[$key] ?? null)
            ? trim(str_replace("\r\n", "\n", $input[$key])) : '';
        $values = [];
        $errors = [];

        $values['name'] = preg_replace('/\s+/u', ' ', $text('name')) ?? '';
        if ($values['name'] === '' || mb_strlen($values['name']) > 80) {
            $errors['name'] = 'Enter a product name of up to 80 characters.';
        }
        $values['category'] = $text('category');
        if (!in_array($values['category'], AdminProductService::CATEGORIES, true)) {
            $errors['category'] = 'Choose a category.';
        }
        $values['fiber'] = $text('fiber');
        if (!isset(self::FIBER_LABELS[$values['fiber']])) {
            $errors['fiber'] = 'Choose one of the listed fibers.';
        }
        foreach (['materials' => 'materials label', 'place_of_make' => 'place-of-make label'] as $key => $label) {
            $values[$key] = preg_replace('/\s+/u', ' ', $text($key)) ?? '';
            if ($values[$key] === '' || mb_strlen($values[$key]) > 200) {
                $errors[$key] = sprintf('Enter a %s of up to 200 characters.', $label);
            }
        }
        foreach (self::TEXT_LIMITS as $key => $limit) {
            $values[$key] = in_array($key, ['description', 'story'], true)
                ? $text($key) : (preg_replace('/\s+/u', ' ', $text($key)) ?? '');
            if (mb_strlen($values[$key]) > $limit) {
                $errors[$key] = sprintf('Keep this under %s characters.', number_format($limit));
            }
        }

        $values['price'] = $text('price');
        $values['price_centavos'] = AdminProductService::parsePesos($values['price']);
        if ($values['price_centavos'] === null) {
            $errors['price'] = 'Enter a price in pesos, e.g. 1650 or 1,650.50.';
        }
        if ($creating) {
            $values['stock'] = $text('stock');
            if (preg_match('/^\d{1,4}$/', $values['stock']) !== 1) {
                $errors['stock'] = sprintf('Enter starting stock from 0 to %d.', AdminProductService::MAX_STOCK);
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

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Inserts a pending_review listing owned by the weaver, its first image
     * and the initial-stock audit row in one transaction.
     *
     * @param array<string, mixed> $values validate()['values'] without errors
     */
    public function create(int $weaverId, array $values, string $imagePath): int
    {
        return $this->transaction(function () use ($weaverId, $values, $imagePath): int {
            $stock = (int) $values['stock'];
            $this->pdo->prepare(
                "INSERT INTO products (sku, slug, name, weaver_id, category_id, category, description, materials,
                     place_of_make, fiber, community, region, technique, story, price_centavos, stock,
                     low_stock_threshold, dimensions, three_config, listing_status, is_available)
                 SELECT ?, ?, ?, w.id, (SELECT id FROM categories WHERE name = ?), ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, NULL, 'pending_review', 1
                 FROM weavers w WHERE w.id = ? AND w.is_active = 1"
            )->execute([
                $this->uniqueSku($weaverId), $this->uniqueSlug($values['name']), $values['name'],
                $values['category'], $values['category'], $values['description'], $values['materials'],
                $values['place_of_make'], $values['fiber'], $values['community'], $values['region'],
                $values['technique'], $values['story'], $values['price_centavos'], $stock,
                (int) $values['low_stock_threshold'], self::dimensionsJson($values), $weaverId,
            ]);
            if ((int) $this->pdo->query('SELECT changes()')->fetchColumn() !== 1) {
                throw new AdminActionException('Your account is no longer active.');
            }
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                'INSERT INTO product_images (product_id, path, sort_order, is_primary) VALUES (?, ?, 0, 1)'
            )->execute([$id, $imagePath]);
            if ($stock > 0) {
                $this->pdo->prepare(
                    "INSERT INTO stock_audit (product_id, weaver_id, old_stock, new_stock, reason)
                     VALUES (?, ?, 0, ?, 'initial_stock')"
                )->execute([$id, $weaverId, $stock]);
            }

            return $id;
        });
    }

    /**
     * Updates the weaver's own listing except stock, status, SKU and 3D
     * config; an optional new image becomes primary.
     *
     * @param array<string, mixed> $values validate()['values'] without errors
     *
     * @throws AdminActionException When missing, not owned or deactivated.
     */
    public function update(int $weaverId, int $id, array $values, ?string $newImagePath): void
    {
        $this->transaction(function () use ($weaverId, $id, $values, $newImagePath): void {
            $update = $this->pdo->prepare(
                "UPDATE products SET name = ?, category = ?, category_id = (SELECT id FROM categories WHERE name = ?),
                     description = ?, materials = ?, place_of_make = ?, fiber = ?, community = ?, region = ?,
                     technique = ?, story = ?, price_centavos = ?, low_stock_threshold = ?, dimensions = ?,
                     updated_at = datetime('now')
                 WHERE id = ? AND weaver_id = ? AND listing_status <> 'deactivated'"
            );
            $update->execute([
                $values['name'], $values['category'], $values['category'], $values['description'],
                $values['materials'], $values['place_of_make'], $values['fiber'], $values['community'],
                $values['region'], $values['technique'], $values['story'], $values['price_centavos'],
                (int) $values['low_stock_threshold'], self::dimensionsJson($values), $id, $weaverId,
            ]);
            if ($update->rowCount() !== 1) {
                throw $this->refusal($weaverId, $id);
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

    /**
     * Archives (removes) a pending or active listing; order history is kept.
     *
     * @throws AdminActionException
     */
    public function archive(int $weaverId, int $id): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE products SET listing_status = 'archived', updated_at = datetime('now')
             WHERE id = ? AND weaver_id = ? AND listing_status IN ('pending_review', 'active')"
        );
        $statement->execute([$id, $weaverId]);
        if ($statement->rowCount() !== 1) {
            $product = $this->find($weaverId, $id);
            if ($product !== null && $product['listing_status'] === 'archived') {
                return;
            }
            throw $this->refusal($weaverId, $id);
        }
    }

    /**
     * Marks the weaver's listing available or sold out (R37).
     *
     * @throws AdminActionException
     */
    public function setAvailable(int $weaverId, int $id, bool $available): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE products SET is_available = ?, updated_at = datetime('now')
             WHERE id = ? AND weaver_id = ? AND listing_status <> 'deactivated'"
        );
        $statement->execute([$available ? 1 : 0, $id, $weaverId]);
        if ($statement->rowCount() !== 1) {
            throw $this->refusal($weaverId, $id);
        }
    }

    /**
     * Counts of the weaver's listings per status.
     *
     * @return array<string, int>
     */
    public function statusCounts(int $weaverId): array
    {
        $counts = array_fill_keys(array_keys(self::LISTING_LABELS), 0);
        foreach ($this->list($weaverId) as $product) {
            $counts[$product['listing_status']]++;
        }

        return $counts;
    }

    private function refusal(int $weaverId, int $id): AdminActionException
    {
        $product = $this->find($weaverId, $id);

        return new AdminActionException(
            $product !== null && $product['listing_status'] === 'deactivated' ? self::DEACTIVATED : self::MISSING
        );
    }

    /** First listed profile fiber that is on the controlled list, else "other". */
    private static function defaultFiber(string $fibers): string
    {
        foreach (preg_split('/[\s,\/]+/', strtolower($fibers)) ?: [] as $fiber) {
            if (in_array($fiber, HeritageUpgrade::FIBERS, true)) {
                return $fiber;
            }
        }

        return 'other';
    }

    private function uniqueSku(int $weaverId): string
    {
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
        do {
            $sku = sprintf('HBI-W%d-%s', $weaverId, strtoupper(bin2hex(random_bytes(3))));
            $check->execute([$sku]);
        } while ((int) $check->fetchColumn() > 0);

        return $sku;
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
    private static function dimensionsJson(array $values): string
    {
        return json_encode([
            'width' => (int) $values['width'],
            'height' => (int) $values['height'],
            'depth' => (int) $values['depth'],
        ], JSON_THROW_ON_ERROR);
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
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // Already clean or broken; the original failure matters.
            }

            throw $exception;
        }
    }
}
