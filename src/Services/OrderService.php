<?php

declare(strict_types=1);

namespace Habi\Services;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Order placement and lookup (M5, PRD R10/R14/R15/R16, SDD §8).
 *
 * placeOrder() runs entirely inside a SQLite BEGIN IMMEDIATE write
 * transaction: the idempotency check wins first (a committed retry returns
 * the same order untouched, even with an empty or changed payload), then
 * shipping/payment are revalidated, live prices must match the reviewed
 * snapshots, stock is decremented with a guarded UPDATE + affected-row
 * check, stock_audit rows record old/new stock with reason order_placed,
 * and the paid order plus its item snapshots are inserted. Any failure
 * rolls the whole attempt back — no partial order, no partial decrement.
 *
 * The order number is H-YYYYMMDD-NNNN: today's date plus a daily
 * four-digit sequence read under the serialized write lock. The unique
 * constraint guards the insert; capacity exhaustion (9999 orders in one
 * day) fails safely instead of emitting a wider number.
 *
 * Money is integer centavos. Card data never reaches this service — only
 * the approved result with a 4-digit last4 and a bounded brand label (N4).
 * PDO errors propagate so the controller can show a generic friendly retry.
 */
final class OrderService
{
    /** Highest daily sequence the H-YYYYMMDD-NNNN format can hold. */
    private const MAX_DAILY_SEQUENCE = 9999;

    /** Forward fulfilment steps an administrator may apply (PRD R22). */
    public const NEXT_STATUS = ['paid' => 'packed', 'packed' => 'shipped', 'shipped' => 'delivered'];

    /** Statuses that can still be cancelled: the bags have not left. */
    public const CANCELLABLE = ['paid', 'packed'];

    /** Statuses shown in admin filters; checkout only ever creates paid orders. */
    public const ADMIN_STATUSES = ['paid', 'packed', 'shipped', 'delivered', 'cancelled'];

    public const ADMIN_PER_PAGE = 25;

    private const MAX_BRAND_LENGTH = 30;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Places a paid order atomically.
     *
     * @param list<array{product_id: int, quantity: int, price_centavos: int}> $lines
     *     Reviewed cart lines; price_centavos is the snapshot the customer
     *     saw and must still match the live price. Duplicate product ids
     *     are rejected.
     * @param array<string, string> $shipping
     *     Validated shipping fields (name, email, phone, address1, address2,
     *     city, postal_code, region).
     * @param array{result: string, last4?: string, brand?: string} $payment
     *     Approved simulation result only.
     * @param string $idempotencyKey
     *     Session-issued key from before the Place form was rendered.
     *
     * @return array<string, mixed> The committed order, including items.
     *
     * @throws CheckoutException With reason stock|changed|invalid.
     */
    public function placeOrder(
        array $lines,
        array $shipping,
        array $payment,
        string $idempotencyKey,
    ): array {
        $key = trim($idempotencyKey);
        $began = false;

        try {
            // BEGIN IMMEDIATE serializes writers for the whole attempt:
            // stock checks, the daily sequence and the idempotency lookup
            // all see one consistent snapshot.
            $this->pdo->exec('BEGIN IMMEDIATE');
            $began = true;

            // A committed retry wins before any input validation, so a
            // duplicated Place submission can never create a second order.
            if ($key !== '') {
                $existing = $this->findByKey($key);
                if ($existing !== null) {
                    $this->pdo->exec('COMMIT');
                    $began = false;

                    return $existing;
                }
            }

            $this->assertValidInput($lines, $shipping, $payment, $key);

            $subtotal = 0;
            foreach ($lines as $line) {
                $subtotal += $line['price_centavos'] * $line['quantity'];
            }
            $shippingFee = ShippingService::fee($shipping['region'], $subtotal);

            // Revalidate every line against live catalog state. The
            // decrements below happen line by line; a later failure rolls
            // earlier ones back with the transaction.
            $rows = [];
            foreach ($lines as $line) {
                $product = $this->fetchProduct((int) $line['product_id']);

                if ($product === null || (int) $product['is_active'] !== 1) {
                    throw new CheckoutException(
                        'Some bags in your order are no longer available.'
                        . ' Please check your cart and try again.',
                        CheckoutException::REASON_INVALID
                    );
                }

                if ((int) $product['price_centavos'] !== (int) $line['price_centavos']) {
                    throw new CheckoutException(
                        sprintf(
                            'The price of %s changed since you reviewed'
                            . ' your order. Please review your cart again.',
                            (string) $product['name']
                        ),
                        CheckoutException::REASON_CHANGED
                    );
                }

                $stock = (int) $product['stock'];
                if ($stock < (int) $line['quantity']) {
                    throw new CheckoutException(
                        sprintf(
                            'Only %d of %s left in stock — your order'
                            . ' cannot be completed.',
                            $stock,
                            (string) $product['name']
                        ),
                        CheckoutException::REASON_STOCK
                    );
                }

                $rows[] = ['line' => $line, 'product' => $product];
            }

            // Stock decrement + audit, one line at a time, all guarded.
            foreach ($rows as ['line' => $line, 'product' => $product]) {
                $this->decrementStock($line, $product);
            }

            $orderId = $this->insertOrder($lines, $shipping, $payment, $key, $subtotal, $shippingFee);
            $this->insertItems($orderId, $rows);

            $lookup = $this->pdo->prepare('SELECT order_number FROM orders WHERE id = ?');
            $lookup->execute([$orderId]);

            // Read the full order back inside the transaction: after
            // COMMIT this lookup can no longer fail, so a committed order
            // is never reported as a failed attempt.
            $order = $this->findByNumber((string) $lookup->fetchColumn());
            if ($order === null) {
                throw new RuntimeException('The placed order could not be read back.');
            }

            $this->pdo->exec('COMMIT');
            $began = false;

            return $order;
        } catch (Throwable $exception) {
            // Roll back explicitly: PDO does not reliably track a manual
            // BEGIN IMMEDIATE, so inTransaction() is not trusted here.
            if ($began) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // The connection is already clean or broken; either way
                    // the original failure is what matters.
                }
            }

            throw $exception;
        }
    }

    /**
     * The full order (including item snapshots) by order number, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findByNumber(string $orderNumber): ?array
    {
        return $this->fetchOrder('order_number', $orderNumber);
    }

    /**
     * The full order (including item snapshots) by idempotency key, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findByKey(string $idempotencyKey): ?array
    {
        return $this->fetchOrder('idempotency_key', $idempotencyKey);
    }

    /**
     * Orders for the admin list, newest first, filtered by status and an
     * inclusive created_at date range (YYYY-MM-DD, UTC as stored).
     *
     * @param array<string, mixed> $filters status, from, to, page
     *
     * @return array{orders: list<array<string, mixed>>, status: string, from: string, to: string,
     *     page: int, pages: int, total: int}
     */
    public function listForAdmin(array $filters): array
    {
        $status = is_string($filters['status'] ?? null) && in_array($filters['status'], self::ADMIN_STATUSES, true)
            ? $filters['status'] : '';
        $from = self::dateFilter($filters['from'] ?? null);
        $to = self::dateFilter($filters['to'] ?? null);
        $conditions = [];
        $parameters = [];
        if ($status !== '') {
            $conditions[] = 'o.status = :status';
            $parameters['status'] = $status;
        }
        if ($from !== '') {
            $conditions[] = 'o.created_at >= :from';
            $parameters['from'] = $from;
        }
        if ($to !== '') {
            $conditions[] = "o.created_at < date(:to, '+1 day')";
            $parameters['to'] = $to;
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM orders o' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / self::ADMIN_PER_PAGE));
        $page = is_string($filters['page'] ?? null) && ctype_digit($filters['page']) ? (int) $filters['page'] : 1;
        $page = min(max(1, $page), $pages);

        $statement = $this->pdo->prepare(
            'SELECT o.id, o.order_number, o.status, o.customer_name, o.shipping_region,
                    o.total_centavos, o.created_at,
                    (SELECT COALESCE(SUM(i.qty), 0) FROM order_items i WHERE i.order_id = o.id) AS items
             FROM orders o' . $where . '
             ORDER BY o.created_at DESC, o.id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':limit', self::ADMIN_PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * self::ADMIN_PER_PAGE, PDO::PARAM_INT);
        $statement->execute();

        return [
            'orders' => $statement->fetchAll(),
            'status' => $status,
            'from' => $from,
            'to' => $to,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /**
     * Dashboard counts and the five most recent orders.
     *
     * @return array{to_fulfil: int, today: int, recent: list<array<string, mixed>>}
     */
    public function adminSummary(): array
    {
        $counts = $this->pdo->query(
            "SELECT COALESCE(SUM(status IN ('paid', 'packed')), 0) AS to_fulfil,
                    COALESCE(SUM(date(created_at) = date('now')), 0) AS today
             FROM orders"
        )->fetch();
        $recent = $this->pdo->query(
            'SELECT order_number, status, shipping_region, total_centavos, created_at
             FROM orders ORDER BY created_at DESC, id DESC LIMIT 5'
        )->fetchAll();

        return ['to_fulfil' => (int) $counts['to_fulfil'], 'today' => (int) $counts['today'], 'recent' => $recent];
    }

    /**
     * Moves an order one fulfilment step forward (paid → packed → shipped
     * → delivered). The guarded UPDATE rejects a stale form.
     *
     * @throws AdminActionException
     */
    public function advanceStatus(string $orderNumber, string $target): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $order = $this->lockedOrder($orderNumber);
            if ((self::NEXT_STATUS[$order['status']] ?? null) !== $target) {
                throw new AdminActionException(sprintf(
                    'Order %s is %s, so it cannot be marked %s. Reload to see its current status.',
                    $orderNumber,
                    $order['status'],
                    $target
                ));
            }
            $update = $this->pdo->prepare(
                "UPDATE orders SET status = ?, updated_at = datetime('now') WHERE id = ? AND status = ?"
            );
            $update->execute([$target, $order['id'], $order['status']]);
            if ($update->rowCount() !== 1) {
                throw new AdminActionException('The order changed during the update. Reload and try again.');
            }
            $this->pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            $this->rollBackQuietly();

            throw $exception;
        }
    }

    /**
     * Cancels a paid or packed order and restores every item's stock in the
     * same transaction, with one stock_audit row per item
     * (reason "order_cancelled: H-…", acting administrator). Any failure
     * leaves the order and stock untouched.
     *
     * @throws AdminActionException
     */
    public function cancel(string $orderNumber, int $adminId): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $order = $this->lockedOrder($orderNumber);
            if (!in_array($order['status'], self::CANCELLABLE, true)) {
                throw new AdminActionException(sprintf(
                    'Order %s is %s and can no longer be cancelled.',
                    $orderNumber,
                    $order['status']
                ));
            }

            $items = $this->pdo->prepare(
                'SELECT i.product_id, i.qty, p.stock FROM order_items i
                 JOIN products p ON p.id = i.product_id WHERE i.order_id = ? ORDER BY i.id'
            );
            $items->execute([$order['id']]);
            $restore = $this->pdo->prepare(
                "UPDATE products SET stock = stock + ?, updated_at = datetime('now') WHERE id = ? AND stock = ?"
            );
            $audit = $this->pdo->prepare(
                'INSERT INTO stock_audit (product_id, admin_id, old_stock, new_stock, reason) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($items->fetchAll() as $item) {
                $old = (int) $item['stock'];
                $restore->execute([(int) $item['qty'], (int) $item['product_id'], $old]);
                if ($restore->rowCount() !== 1) {
                    throw new RuntimeException('Stock changed while restoring a cancelled order.');
                }
                $audit->execute([
                    (int) $item['product_id'],
                    $adminId,
                    $old,
                    $old + (int) $item['qty'],
                    'order_cancelled: ' . $orderNumber,
                ]);
            }

            $update = $this->pdo->prepare(
                "UPDATE orders SET status = 'cancelled', updated_at = datetime('now') WHERE id = ? AND status = ?"
            );
            $update->execute([$order['id'], $order['status']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The order changed while it was being cancelled.');
            }
            $this->pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            $this->rollBackQuietly();

            throw $exception;
        }
    }

    /**
     * @return array{id: int, status: string}
     */
    private function lockedOrder(string $orderNumber): array
    {
        $statement = $this->pdo->prepare('SELECT id, status FROM orders WHERE order_number = ?');
        $statement->execute([$orderNumber]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new AdminActionException('That order does not exist.');
        }

        return ['id' => (int) $row['id'], 'status' => (string) $row['status']];
    }

    private function rollBackQuietly(): void
    {
        try {
            $this->pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // Already clean or broken; the original failure matters.
        }
    }

    private static function dateFilter(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : '';
    }

    /**
     * @param list<array{product_id: int, quantity: int, price_centavos: int}> $lines
     * @param array{result: string, last4?: string, brand?: string} $payment
     */
    private function assertValidInput(
        array $lines,
        array $shipping,
        array $payment,
        string $key,
    ): void {
        if ($key === '') {
            throw new CheckoutException(
                'Your order could not be submitted. Please try again.',
                CheckoutException::REASON_INVALID
            );
        }

        if ($lines === []) {
            throw new CheckoutException(
                'Your cart is empty.',
                CheckoutException::REASON_INVALID
            );
        }

        $seenProductIds = [];
        foreach ($lines as $line) {
            if (
                !is_array($line)
                || !is_int($line['product_id'] ?? null) || (int) $line['product_id'] < 1
                || !is_int($line['quantity'] ?? null) || (int) $line['quantity'] < 1
                || !is_int($line['price_centavos'] ?? null) || (int) $line['price_centavos'] < 0
            ) {
                throw new CheckoutException(
                    'Your cart could not be read. Please review your cart'
                    . ' and try again.',
                    CheckoutException::REASON_INVALID
                );
            }

            // Duplicate lines would double-decrement one product while the
            // per-line stock guard still saw each quantity in isolation.
            if (isset($seenProductIds[$line['product_id']])) {
                throw new CheckoutException(
                    'Your cart contains a duplicated bag. Please review it'
                    . ' and try again.',
                    CheckoutException::REASON_INVALID
                );
            }
            $seenProductIds[$line['product_id']] = true;
        }

        $validated = ShippingService::validate($shipping);
        if ($validated['errors'] !== []) {
            throw new CheckoutException(
                'Your shipping details are no longer valid. Please review'
                . ' them and try again.',
                CheckoutException::REASON_INVALID
            );
        }

        // Only the sanitized display fields of an approved simulation are
        // ever persisted: exactly four digits for last4 and a short brand
        // label — never a full card number (N4).
        if (
            ($payment['result'] ?? null) !== PaymentSimulator::RESULT_APPROVED
            || !is_string($payment['last4'] ?? null)
            || preg_match('/^\d{4}$/', $payment['last4']) !== 1
            || !is_string($payment['brand'] ?? null)
            || $payment['brand'] === ''
            || mb_strlen($payment['brand']) > self::MAX_BRAND_LENGTH
        ) {
            throw new CheckoutException(
                'Your payment was not approved, so no order was placed.',
                CheckoutException::REASON_INVALID
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProduct(int $productId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, price_centavos, stock, is_active'
            . ' FROM products WHERE id = ?'
        );
        $statement->execute([$productId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Guarded decrement plus the order_placed audit row. The WHERE stock
     * >= ? clause makes overselling impossible even under concurrency; the
     * affected-row check catches anything the earlier read missed.
     *
     * @param array{product_id: int, quantity: int, price_centavos: int} $line
     * @param array<string, mixed> $product
     */
    private function decrementStock(array $line, array $product): void
    {
        $oldStock = (int) $product['stock'];
        $newStock = $oldStock - (int) $line['quantity'];

        $update = $this->pdo->prepare(
            'UPDATE products SET stock = stock - ?, updated_at = datetime(\'now\')'
            . ' WHERE id = ? AND stock >= ?'
        );
        $update->execute([
            (int) $line['quantity'],
            (int) $line['product_id'],
            (int) $line['quantity'],
        ]);

        if ($update->rowCount() !== 1) {
            throw new CheckoutException(
                sprintf(
                    'Only %d of %s left in stock — your order cannot be'
                    . ' completed.',
                    max($oldStock, 0),
                    (string) $product['name']
                ),
                CheckoutException::REASON_STOCK
            );
        }

        $audit = $this->pdo->prepare(
            'INSERT INTO stock_audit (product_id, admin_id, old_stock, new_stock, reason)'
            . ' VALUES (?, NULL, ?, ?, \'order_placed\')'
        );
        $audit->execute([(int) $line['product_id'], $oldStock, $newStock]);
    }

    /**
     * Inserts the paid order and returns its id. The daily H-YYYYMMDD-NNNN
     * number is read from the current maximum suffix under the write lock;
     * the unique constraint is the only guard the insert needs. Capacity
     * exhaustion fails safely instead of emitting a wider number.
     *
     * @param list<array{product_id: int, quantity: int, price_centavos: int}> $lines
     * @param array{result: string, last4?: string, brand?: string} $payment
     */
    private function insertOrder(
        array $lines,
        array $shipping,
        array $payment,
        string $key,
        int $subtotal,
        int $shippingFee,
    ): int {
        $insert = $this->pdo->prepare(
            'INSERT INTO orders (order_number, status, customer_name, customer_email,'
            . ' customer_phone, shipping_address1, shipping_address2, shipping_city,'
            . ' shipping_postal_code, shipping_region, shipping_fee_centavos,'
            . ' subtotal_centavos, total_centavos, payment_last4, payment_brand,'
            . ' payment_result, idempotency_key)'
            . ' VALUES (?, \'paid\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,'
            . ' \'approved\', ?)'
        );

        $insert->execute([
            $this->nextOrderNumber(),
            (string) $shipping['name'],
            (string) $shipping['email'],
            (string) $shipping['phone'],
            (string) $shipping['address1'],
            (string) $shipping['address2'],
            (string) $shipping['city'],
            (string) $shipping['postal_code'],
            (string) $shipping['region'],
            $shippingFee,
            $subtotal,
            $subtotal + $shippingFee,
            (string) $payment['last4'],
            (string) $payment['brand'],
            $key,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Today's date plus the next free four-digit daily sequence, read from
     * the maximum existing suffix under the serialized write lock. Fixed
     * width makes the lexicographic maximum the numeric maximum.
     */
    private function nextOrderNumber(): string
    {
        $prefix = 'H-' . date('Ymd') . '-';

        $statement = $this->pdo->prepare(
            'SELECT order_number FROM orders WHERE order_number LIKE ?'
            . ' ORDER BY order_number DESC LIMIT 1'
        );
        $statement->execute([$prefix . '%']);
        $latest = $statement->fetchColumn();

        $sequence = is_string($latest) && strlen($latest) === strlen($prefix) + 4
            ? (int) substr($latest, strlen($prefix))
            : 0;

        if ($sequence >= self::MAX_DAILY_SEQUENCE) {
            // Fail safely rather than emit a five-digit suffix that breaks
            // the H-YYYYMMDD-NNNN format (PRD R10).
            throw new RuntimeException(
                'Daily order capacity reached; no order number can be issued.'
            );
        }

        return $prefix . sprintf('%04d', $sequence + 1);
    }

    /**
     * @param list<array{
     *     line: array{product_id: int, quantity: int, price_centavos: int},
     *     product: array<string, mixed>
     * }> $rows
     */
    private function insertItems(int $orderId, array $rows): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, name_snapshot,'
            . ' price_centavos, qty, line_total_centavos)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($rows as ['line' => $line, 'product' => $product]) {
            $insert->execute([
                $orderId,
                (int) $line['product_id'],
                (string) $product['name'],
                (int) $line['price_centavos'],
                (int) $line['quantity'],
                (int) $line['price_centavos'] * (int) $line['quantity'],
            ]);
        }
    }

    /**
     * Shared lookup by a unique column, with item snapshots attached.
     *
     * @return array<string, mixed>|null
     */
    private function fetchOrder(string $column, string $value): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM orders WHERE {$column} = ? LIMIT 1"
        );
        $statement->execute([$value]);
        $order = $statement->fetch();

        if ($order === false) {
            return null;
        }

        $items = $this->pdo->prepare(
            'SELECT id, product_id, name_snapshot, price_centavos, qty,'
            . ' line_total_centavos FROM order_items WHERE order_id = ?'
            . ' ORDER BY id ASC'
        );
        $items->execute([(int) $order['id']]);
        $order['items'] = $items->fetchAll();

        return $order;
    }
}
