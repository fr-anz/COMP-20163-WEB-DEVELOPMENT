<?php

declare(strict_types=1);

namespace Habi\Services;

use Habi\Support\PlainText;
use PDO;
use Throwable;

/**
 * Category management (PRD R41). Categories are created, renamed and
 * deactivated — never deleted, so products and order history keep them.
 * The slug (catalog filter value) is set once on creation and stays stable
 * across renames; a rename also updates the products' denormalized
 * category label, which the v1 catalog still reads. Rule (SDD §15.3): a
 * category cannot be deactivated while it has live products (listing
 * status active or pending review); archived or deactivated products do
 * not block it. Deactivated categories are not offered for new products.
 */
final class CategoryService
{
    public const MAX_NAME_LENGTH = 40;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{id: int, slug: string, name: string, sort_order: int, is_active: bool,
     *     products: int, live: int}>
     */
    public function list(): array
    {
        $rows = $this->pdo->query(
            "SELECT c.id, c.slug, c.name, c.sort_order, c.is_active,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS products,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id
                        AND p.listing_status IN ('active', 'pending_review')) AS live
             FROM categories c ORDER BY c.is_active DESC, c.sort_order, c.name COLLATE NOCASE"
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'sort_order' => (int) $row['sort_order'],
            'is_active' => (int) $row['is_active'] === 1,
            'products' => (int) $row['products'],
            'live' => (int) $row['live'],
        ], $rows);
    }

    /** @return array{id: int, slug: string, name: string, is_active: bool}|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, slug, name, is_active FROM categories WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'is_active' => (int) $row['is_active'] === 1,
        ];
    }

    /**
     * Active categories for product forms (id => name), plus $includeId
     * when a product already uses a deactivated one.
     *
     * @return array<int, string>
     */
    public function activeOptions(?int $includeId = null): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name FROM categories WHERE is_active = 1 OR id = ? ORDER BY sort_order, name COLLATE NOCASE'
        );
        $statement->execute([$includeId ?? 0]);
        $options = [];
        foreach ($statement->fetchAll() as $row) {
            $options[(int) $row['id']] = (string) $row['name'];
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{values: array{name: string}, errors: array<string, string>}
     */
    public function validate(array $input, ?int $categoryId): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', is_string($input['name'] ?? null) ? $input['name'] : ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = sprintf('Enter a name of up to %d characters.', self::MAX_NAME_LENGTH);
        } elseif (PlainText::hasMarkup($name)) {
            $errors['name'] = 'Use plain text; HTML tags are not allowed.';
        } else {
            $taken = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE lower(name) = lower(?) AND id != ?');
            $taken->execute([$name, $categoryId ?? 0]);
            if ((int) $taken->fetchColumn() > 0) {
                $errors['name'] = 'Another category already has this name.';
            }
        }

        return ['values' => ['name' => $name], 'errors' => $errors];
    }

    /** @param array{name: string} $values */
    public function create(array $values): int
    {
        $this->pdo->prepare(
            'INSERT INTO categories (slug, name, sort_order, is_active)
             VALUES (?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories), 1)'
        )->execute([$this->uniqueSlug($values['name']), $values['name']]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Renames a category and its products' labels in one transaction. @param array{name: string} $values */
    public function rename(int $id, array $values): void
    {
        $this->transaction(function () use ($id, $values): void {
            $update = $this->pdo->prepare('UPDATE categories SET name = ? WHERE id = ?');
            $update->execute([$values['name'], $id]);
            if ($update->rowCount() !== 1) {
                throw new AdminActionException('That category no longer exists.');
            }
            $this->pdo->prepare(
                "UPDATE products SET category = ?, updated_at = datetime('now') WHERE category_id = ?"
            )->execute([$values['name'], $id]);
        });
    }

    /**
     * Deactivates a category without live products (the check and the
     * update share one write transaction).
     *
     * @throws AdminActionException
     */
    public function deactivate(int $id): void
    {
        $this->transaction(function () use ($id): void {
            $live = $this->pdo->prepare(
                "SELECT COUNT(*) FROM products WHERE category_id = ? AND listing_status IN ('active', 'pending_review')"
            );
            $live->execute([$id]);
            $count = (int) $live->fetchColumn();
            if ($count > 0) {
                throw new AdminActionException(sprintf(
                    'This category still has %d live %s. Move, archive or deactivate %s first.',
                    $count,
                    $count === 1 ? 'product' : 'products',
                    $count === 1 ? 'it' : 'them'
                ));
            }
            $this->setActive($id, false);
        });
    }

    public function reactivate(int $id): void
    {
        $this->setActive($id, true);
    }

    private function setActive(int $id, bool $active): void
    {
        $update = $this->pdo->prepare('UPDATE categories SET is_active = ? WHERE id = ?');
        $update->execute([$active ? 1 : 0, $id]);
        if ($update->rowCount() !== 1) {
            throw new AdminActionException('That category no longer exists.');
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(
            (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)
        )), '-');
        $base = $base === '' ? 'category' : substr($base, 0, 40);
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE slug = ?');
        for ($suffix = 1;; $suffix++) {
            $slug = $suffix === 1 ? $base : $base . '-' . $suffix;
            $check->execute([$slug]);
            if ((int) $check->fetchColumn() === 0) {
                return $slug;
            }
        }
    }

    private function transaction(callable $work): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $work();
            $this->pdo->exec('COMMIT');
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
