<?php

declare(strict_types=1);

namespace Habi\Services;

use PDO;
use Throwable;

/**
 * Administrator stock adjustments and the stock audit log (PRD R18, R21).
 *
 * Adjustments are two-step: preview() validates and shows current → new
 * without writing; adjust() repeats the validation inside a SQLite write
 * transaction and applies the change only if stock still equals the value
 * the administrator reviewed. A concurrent checkout or adjustment therefore
 * never gets silently overwritten. Every applied change writes one
 * stock_audit row with product, old → new, reason, timestamp and admin.
 */
final class StockService
{
    /** Allowed adjustment reasons (published admin reference). */
    public const REASONS = [
        'Restock received',
        'Damaged in storage',
        'Count correction',
        'Returned item',
    ];

    public const MAX_CHANGE = 9999;
    public const MAX_NOTE_LENGTH = 200;
    public const AUDIT_PER_PAGE = 50;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Validates a proposed change against live stock without writing.
     *
     * @return array{product: array{id: int, name: string}, old_stock: int, new_stock: int,
     *     change: int, reason: string, note: string}
     *
     * @throws AdminActionException
     */
    public function preview(int $productId, mixed $change, mixed $reason, mixed $note): array
    {
        [$change, $reason, $note] = $this->validate($change, $reason, $note);
        $product = $this->product($productId);
        $new = $product['stock'] + $change;
        if ($new < 0) {
            throw new AdminActionException(
                sprintf('Only %d in stock — you cannot remove %d.', $product['stock'], -$change),
                'change'
            );
        }

        return [
            'product' => ['id' => $product['id'], 'name' => $product['name']],
            'old_stock' => $product['stock'],
            'new_stock' => $new,
            'change' => $change,
            'reason' => $reason,
            'note' => $note,
        ];
    }

    /**
     * Applies a reviewed change atomically and records the audit row.
     *
     * @return array{old_stock: int, new_stock: int}
     *
     * @throws AdminActionException When invalid, or when stock no longer
     *     equals $expectedStock (context: current_stock).
     */
    public function adjust(
        int $productId,
        int $expectedStock,
        mixed $change,
        mixed $reason,
        mixed $note,
        int $adminId,
    ): array {
        [$change, $reason, $note] = $this->validate($change, $reason, $note);

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $product = $this->product($productId);
            if ($product['stock'] !== $expectedStock) {
                throw new AdminActionException(
                    sprintf(
                        'Stock for %s changed to %d while you were reviewing. Check the new numbers and confirm again.',
                        $product['name'],
                        $product['stock']
                    ),
                    null,
                    ['current_stock' => $product['stock']]
                );
            }
            $new = $product['stock'] + $change;
            if ($new < 0) {
                throw new AdminActionException(
                    sprintf('Only %d in stock — you cannot remove %d.', $product['stock'], -$change),
                    'change'
                );
            }

            $update = $this->pdo->prepare(
                "UPDATE products SET stock = ?, updated_at = datetime('now') WHERE id = ? AND stock = ?"
            );
            $update->execute([$new, $productId, $expectedStock]);
            if ($update->rowCount() !== 1) {
                throw new AdminActionException('Stock changed during the update. Review it and try again.');
            }

            $this->pdo->prepare(
                'INSERT INTO stock_audit (product_id, admin_id, old_stock, new_stock, reason) VALUES (?, ?, ?, ?, ?)'
            )->execute([$productId, $adminId, $expectedStock, $new, $note === '' ? $reason : $reason . ' — ' . $note]);

            $this->pdo->exec('COMMIT');

            return ['old_stock' => $expectedStock, 'new_stock' => $new];
        } catch (Throwable $exception) {
            $this->rollBack();

            throw $exception;
        }
    }

    /**
     * Newest-first audit rows, optionally for one product.
     *
     * @return array{entries: list<array<string, mixed>>, page: int, pages: int, total: int}
     */
    public function audit(int $page = 1, ?int $productId = null): array
    {
        $where = $productId === null ? '' : ' WHERE a.product_id = :product';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM stock_audit a' . $where);
        if ($productId !== null) {
            $count->bindValue(':product', $productId, PDO::PARAM_INT);
        }
        $count->execute();
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / self::AUDIT_PER_PAGE));
        $page = min(max(1, $page), $pages);

        $statement = $this->pdo->prepare(
            'SELECT a.id, a.product_id, p.name AS product_name, a.old_stock, a.new_stock,
                    a.reason, a.created_at, ad.email AS admin_email
             FROM stock_audit a
             JOIN products p ON p.id = a.product_id
             LEFT JOIN admins ad ON ad.id = a.admin_id' . $where . '
             ORDER BY a.id DESC
             LIMIT :limit OFFSET :offset'
        );
        if ($productId !== null) {
            $statement->bindValue(':product', $productId, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', self::AUDIT_PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * self::AUDIT_PER_PAGE, PDO::PARAM_INT);
        $statement->execute();

        $entries = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'product_name' => (string) $row['product_name'],
            'old_stock' => (int) $row['old_stock'],
            'new_stock' => (int) $row['new_stock'],
            'change' => (int) $row['new_stock'] - (int) $row['old_stock'],
            'reason' => self::describeReason((string) $row['reason']),
            'created_at' => (string) $row['created_at'],
            'actor' => $row['admin_email'] === null ? 'system' : (string) $row['admin_email'],
        ], $statement->fetchAll());

        return ['entries' => $entries, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    /**
     * Human-readable reason for stored machine reasons (checkout, cancel,
     * initial stock); administrator reasons are stored as written.
     */
    public static function describeReason(string $reason): string
    {
        if ($reason === 'order_placed') {
            return 'Order placed (simulated checkout)';
        }
        if ($reason === 'initial_stock') {
            return 'Initial stock (new product)';
        }
        if (str_starts_with($reason, 'order_cancelled')) {
            $number = trim(substr($reason, strlen('order_cancelled')), ': ');

            return $number === ''
                ? 'Order cancelled — stock restored'
                : 'Order ' . $number . ' cancelled — stock restored';
        }

        return $reason;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     *
     * @throws AdminActionException
     */
    private function validate(mixed $change, mixed $reason, mixed $note): array
    {
        $change = is_int($change) ? (string) $change : (is_string($change) ? trim($change) : '');
        if (preg_match('/^[+-]?\d{1,4}$/', $change) !== 1 || (int) $change === 0) {
            throw new AdminActionException(
                sprintf(
                    'Enter a whole number of units between -%d and %d, other than 0.',
                    self::MAX_CHANGE,
                    self::MAX_CHANGE
                ),
                'change'
            );
        }
        if (!is_string($reason) || !in_array($reason, self::REASONS, true)) {
            throw new AdminActionException('Choose a reason for this change.', 'reason');
        }
        $note = is_string($note) ? trim(preg_replace('/\s+/u', ' ', $note) ?? '') : '';
        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new AdminActionException(
                sprintf('Keep the note under %d characters.', self::MAX_NOTE_LENGTH),
                'note'
            );
        }

        return [(int) $change, $reason, $note];
    }

    /**
     * @return array{id: int, name: string, stock: int}
     */
    private function product(int $productId): array
    {
        $statement = $this->pdo->prepare('SELECT id, name, stock FROM products WHERE id = ?');
        $statement->execute([$productId]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new AdminActionException('That product no longer exists.');
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'stock' => (int) $row['stock']];
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
