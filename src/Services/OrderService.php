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
 * and the pending order with its payment method and status, item snapshots
 * (including the product's weaver), the opening order_events rows and, for
 * a transfer, the payment_proofs row are inserted (SDD §15.4–15.6). Any
 * failure rolls the whole attempt back — no partial order, no partial
 * decrement, no stored proof.
 *
 * The order number is H-YYYYMMDD-NNNN: today's date plus a daily
 * four-digit sequence read under the serialized write lock. The unique
 * constraint guards the insert; capacity exhaustion (9999 orders in one
 * day) fails safely instead of emitting a wider number.
 *
 * Money is integer centavos. Card data never reaches this service — only
 * the approved result with a 4-digit last4 and a bounded brand label (N4).
 * Payment status starts unpaid (COD), for_verification (transfer) or paid
 * (approved card).
 * PDO errors propagate so the controller can show a generic friendly retry.
 */
final class OrderService
{
    /** Highest daily sequence the H-YYYYMMDD-NNNN format can hold. */
    private const MAX_DAILY_SEQUENCE = 9999;

    /** Forward fulfilment steps an administrator may apply (PRD R22, SDD §15.4). */
    public const NEXT_STATUS = [
        'pending' => 'confirmed', 'confirmed' => 'packed', 'packed' => 'shipped', 'shipped' => 'delivered',
    ];

    /** Statuses that can still be cancelled: confirmed and the bags have not left. */
    public const CANCELLABLE = ['confirmed', 'packed'];

    /** Statuses shown in admin filters (SDD §15.4). */
    public const ADMIN_STATUSES = ['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'rejected', 'cancelled'];

    /** Payment statuses shown in admin filters (SDD §15.4). */
    public const PAYMENT_STATUSES = ['unpaid', 'for_verification', 'paid', 'rejected'];

    /** Statuses from which cash on delivery can be marked paid. */
    public const COD_PAYABLE = ['shipped', 'delivered'];

    /** Longest reject, cancel or refuse reason. */
    public const MAX_REASON_LENGTH = 300;

    public const ADMIN_PER_PAGE = 25;

    private const MAX_BRAND_LENGTH = 30;

    public const MAX_SENDER_REFERENCE_LENGTH = 64;

    /** Payment methods (PRD R13) and the payment status each starts with. */
    public const PAYMENT_METHODS = ['cod' => 'unpaid', 'transfer' => 'for_verification', 'card' => 'paid'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?PaymentProofService $proofs = null,
    ) {
    }

    /**
     * Places a pending order atomically.
     *
     * @param list<array{product_id: int, quantity: int, price_centavos: int}> $lines
     *     Reviewed cart lines; price_centavos is the snapshot the customer
     *     saw and must still match the live price. Duplicate product ids
     *     are rejected.
     * @param array<string, string> $shipping
     *     Validated shipping fields (name, email, phone, address1, address2,
     *     city, postal_code, region).
     * @param array<string, mixed> $payment
     *     {method: cod}; {method: transfer, proof: staged name,
     *     sender_reference}; or the approved card simulation result
     *     {method?: card, result: approved, last4, brand}.
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
        $promoted = null;

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

            $payment = $this->assertValidInput($lines, $shipping, $payment, $key);

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

            $orderId = $this->insertOrder($shipping, $payment, $key, $subtotal, $shippingFee);
            $this->insertItems($orderId, $rows);
            $this->recordEvent($orderId, 'status', null, 'pending', 'customer', null);
            $this->recordEvent($orderId, 'payment_status', null, $payment['status'], 'customer', null);
            if ($payment['method'] === 'transfer') {
                $promoted = $this->requireProofs()->promote($payment['proof']);
                $this->pdo->prepare(
                    'INSERT INTO payment_proofs (order_id, path, sender_reference) VALUES (?, ?, ?)'
                )->execute([$orderId, $promoted, $payment['sender_reference']]);
            }

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
            if ($promoted !== null) {
                $this->requireProofs()->release($payment['proof']);
            }

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
            if ($promoted !== null && $began) {
                $this->requireProofs()->discard($promoted);
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
     * Orders for the admin list, newest first, filtered by status, payment
     * status, payment method and an inclusive created_at date range
     * (YYYY-MM-DD, UTC as stored). Unknown filter values are ignored.
     *
     * @param array<string, mixed> $filters status, payment, method, from, to, page
     *
     * @return array{orders: list<array<string, mixed>>, status: string, payment: string, method: string,
     *     from: string, to: string, page: int, pages: int, total: int}
     */
    public function listForAdmin(array $filters): array
    {
        $pick = static fn (string $key, array $allowed): string =>
            is_string($filters[$key] ?? null) && in_array($filters[$key], $allowed, true) ? $filters[$key] : '';
        $status = $pick('status', self::ADMIN_STATUSES);
        $payment = $pick('payment', self::PAYMENT_STATUSES);
        $method = $pick('method', array_keys(self::PAYMENT_METHODS));
        $from = self::dateFilter($filters['from'] ?? null);
        $to = self::dateFilter($filters['to'] ?? null);
        $conditions = [];
        $parameters = [];
        $columns = ['status' => $status, 'payment_status' => $payment, 'payment_method' => $method];
        foreach ($columns as $column => $value) {
            if ($value !== '') {
                $conditions[] = "o.{$column} = :{$column}";
                $parameters[$column] = $value;
            }
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
            'SELECT o.id, o.order_number, o.status, o.payment_method, o.payment_status, o.customer_name,
                    o.shipping_region, o.total_centavos, o.created_at,
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
            'payment' => $payment,
            'method' => $method,
            'from' => $from,
            'to' => $to,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /**
     * Dashboard order and sales summaries (R18) and the five most recent
     * orders. Sales are order totals (items plus shipping) of every order
     * that is not rejected or cancelled, by UTC placement date.
     *
     * @return array{to_fulfil: int, today: int, to_confirm: int, payments_to_verify: int, sales_today: int,
     *     sales_month: int, sales_total: int, by_status: array<string, int>, recent: list<array<string, mixed>>}
     */
    public function adminSummary(): array
    {
        $counts = $this->pdo->query(
            "SELECT COALESCE(SUM(status IN ('pending', 'confirmed', 'packed')), 0) AS to_fulfil,
                    COALESCE(SUM(date(created_at) = date('now')), 0) AS today,
                    COALESCE(SUM(status = 'pending'), 0) AS to_confirm,
                    COALESCE(SUM(payment_method = 'transfer' AND payment_status = 'for_verification'
                        AND status NOT IN ('rejected', 'cancelled')), 0) AS payments_to_verify,
                    COALESCE(SUM(CASE WHEN status NOT IN ('rejected', 'cancelled')
                        AND date(created_at) = date('now') THEN total_centavos END), 0) AS sales_today,
                    COALESCE(SUM(CASE WHEN status NOT IN ('rejected', 'cancelled')
                        AND strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')
                        THEN total_centavos END), 0) AS sales_month,
                    COALESCE(SUM(CASE WHEN status NOT IN ('rejected', 'cancelled')
                        THEN total_centavos END), 0) AS sales_total
             FROM orders"
        )->fetch();
        $byStatus = array_fill_keys(self::ADMIN_STATUSES, 0);
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS n FROM orders GROUP BY status')->fetchAll() as $row) {
            $byStatus[(string) $row['status']] = (int) $row['n'];
        }
        $recent = $this->pdo->query(
            'SELECT order_number, status, payment_method, payment_status, shipping_region, total_centavos, created_at
             FROM orders ORDER BY created_at DESC, id DESC LIMIT 5'
        )->fetchAll();

        $summary = ['by_status' => $byStatus, 'recent' => $recent];
        $keys = ['to_fulfil', 'today', 'to_confirm', 'payments_to_verify', 'sales_today', 'sales_month', 'sales_total'];
        foreach ($keys as $key) {
            $summary[$key] = (int) $counts[$key];
        }

        return $summary;
    }

    /**
     * Moves an order one fulfilment step forward (pending → confirmed →
     * packed → shipped → delivered). Confirming needs a paid payment unless
     * it is cash on delivery. The guarded UPDATE rejects a stale form; the
     * change is recorded in order_events (actor admin, R44).
     *
     * @param ?string $expected "status/payment_status" the form was rendered for; a mismatch is stale
     *
     * @throws AdminActionException
     */
    public function advanceStatus(
        string $orderNumber,
        string $target,
        ?int $adminId = null,
        ?string $expected = null,
    ): void {
        $this->transition($orderNumber, $expected, function (array $order) use ($orderNumber, $target, $adminId): void {
            if ((self::NEXT_STATUS[$order['status']] ?? null) !== $target) {
                throw self::changed($orderNumber, $order);
            }
            if ($target === 'confirmed' && $order['payment_method'] !== 'cod' && $order['payment_status'] !== 'paid') {
                throw new AdminActionException(sprintf(
                    'Order %s cannot be confirmed until its payment is verified.',
                    $orderNumber
                ));
            }
            $this->guardedStatus($order, $target, null);
            $this->recordEvent($order['id'], 'status', $order['status'], $target, 'admin', $adminId);
        });
    }

    /**
     * Rejects a pending order (reason required, shown to the customer when
     * tracking) and restores every item's stock with one stock_audit row
     * each (reason "order_rejected: H-…"), all in one transaction.
     *
     * @throws AdminActionException
     */
    public function reject(string $orderNumber, int $adminId, string $reason, ?string $expected = null): void
    {
        $reason = self::reason($reason);
        $this->transition($orderNumber, $expected, function (array $order) use ($orderNumber, $adminId, $reason): void {
            if ($order['status'] !== 'pending') {
                throw self::changed($orderNumber, $order);
            }
            $this->restoreStock($order['id'], 'order_rejected: ' . $orderNumber, $adminId);
            $this->guardedStatus($order, 'rejected', $reason);
            $this->recordEvent($order['id'], 'status', 'pending', 'rejected', 'admin', $adminId, $reason);
        });
    }

    /**
     * Cancels a confirmed or packed order (reason required, kept on the
     * event) and restores every item's stock in the same transaction, with
     * one stock_audit row per item (reason "order_cancelled: H-…", acting
     * administrator). Any failure leaves the order and stock untouched.
     *
     * @throws AdminActionException
     */
    public function cancel(string $orderNumber, int $adminId, string $reason, ?string $expected = null): void
    {
        $reason = self::reason($reason);
        $this->transition($orderNumber, $expected, function (array $order) use ($orderNumber, $adminId, $reason): void {
            if (!in_array($order['status'], self::CANCELLABLE, true)) {
                throw new AdminActionException(sprintf(
                    'Order %s is %s and can no longer be cancelled. Review it again.',
                    $orderNumber,
                    $order['status']
                ));
            }
            $this->restoreStock($order['id'], 'order_cancelled: ' . $orderNumber, $adminId);
            $this->guardedStatus($order, 'cancelled', null);
            $this->recordEvent($order['id'], 'status', $order['status'], 'cancelled', 'admin', $adminId, $reason);
        });
    }

    /**
     * Accepts a transfer's proof of payment: for_verification → paid, and
     * the proof is marked reviewed by this administrator.
     *
     * @throws AdminActionException
     */
    public function acceptPayment(string $orderNumber, int $adminId, ?string $expected = null): void
    {
        $this->reviewTransfer($orderNumber, $adminId, 'paid', null, $expected);
    }

    /**
     * Refuses a transfer's proof (reason required): for_verification →
     * rejected. The order stays pending so it can then be rejected.
     *
     * @throws AdminActionException
     */
    public function refusePayment(string $orderNumber, int $adminId, string $reason, ?string $expected = null): void
    {
        $this->reviewTransfer($orderNumber, $adminId, 'rejected', self::reason($reason), $expected);
    }

    /**
     * Records cash collected on delivery: unpaid → paid, only once the
     * order is shipped or delivered.
     *
     * @throws AdminActionException
     */
    public function markCodPaid(string $orderNumber, int $adminId, ?string $expected = null): void
    {
        $this->transition($orderNumber, $expected, function (array $order) use ($orderNumber, $adminId): void {
            if ($order['payment_method'] !== 'cod' || $order['payment_status'] !== 'unpaid') {
                throw self::changed($orderNumber, $order);
            }
            if (!in_array($order['status'], self::COD_PAYABLE, true)) {
                throw new AdminActionException(sprintf(
                    'Order %s is %s. Cash on delivery is marked paid once it has shipped or been delivered.',
                    $orderNumber,
                    $order['status']
                ));
            }
            $this->guardedPayment($order, 'paid');
            $this->recordEvent($order['id'], 'payment_status', 'unpaid', 'paid', 'admin', $adminId);
        });
    }

    /**
     * Every status and payment-status change of an order, oldest first,
     * with the acting administrator's email when there is one (R44).
     *
     * @return list<array<string, mixed>>
     */
    public function events(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT e.id, e.field, e.old_value, e.new_value, e.actor_role, e.actor_id, e.note, e.created_at,
                    a.email AS actor_email
             FROM order_events e LEFT JOIN admins a ON e.actor_role = 'admin' AND a.id = e.actor_id
             WHERE e.order_id = ? ORDER BY e.id ASC"
        );
        $statement->execute([$orderId]);

        return $statement->fetchAll();
    }

    /**
     * The order's proof-of-payment row, or null (N11: the file itself is
     * only streamed by the admin proof route).
     *
     * @return array{path: string, sender_reference: string, uploaded_at: string,
     *     reviewed_by_admin_id: ?int, reviewed_at: ?string, review_note: ?string}|null
     */
    public function proofFor(int $orderId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT path, sender_reference, uploaded_at, reviewed_by_admin_id, reviewed_at, review_note
             FROM payment_proofs WHERE order_id = ?'
        );
        $statement->execute([$orderId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'path' => (string) $row['path'],
            'sender_reference' => (string) $row['sender_reference'],
            'uploaded_at' => (string) $row['uploaded_at'],
            'reviewed_by_admin_id' => $row['reviewed_by_admin_id'] === null ? null : (int) $row['reviewed_by_admin_id'],
            'reviewed_at' => $row['reviewed_at'] === null ? null : (string) $row['reviewed_at'],
            'review_note' => $row['review_note'] === null ? null : (string) $row['review_note'],
        ];
    }

    /** The "status/payment_status" token a form sends back to detect a stale view. */
    public static function stateToken(array $order): string
    {
        return $order['status'] . '/' . $order['payment_status'];
    }

    /**
     * Runs one transition inside BEGIN IMMEDIATE on the locked order; a
     * mismatching expected state is refused before anything is written.
     *
     * @param callable(array{id: int, status: string, payment_method: string, payment_status: string}): void $work
     */
    private function transition(string $orderNumber, ?string $expected, callable $work): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $order = $this->lockedOrder($orderNumber);
            if ($expected !== null && $expected !== self::stateToken($order)) {
                throw self::changed($orderNumber, $order);
            }
            $work($order);
            $this->pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            $this->rollBackQuietly();

            throw $exception;
        }
    }

    private function reviewTransfer(
        string $orderNumber,
        int $adminId,
        string $target,
        ?string $note,
        ?string $expected,
    ): void {
        $this->transition($orderNumber, $expected, function (array $order) use (
            $orderNumber,
            $adminId,
            $target,
            $note
        ): void {
            if (
                $order['payment_method'] !== 'transfer' || $order['payment_status'] !== 'for_verification'
                || in_array($order['status'], ['rejected', 'cancelled'], true)
            ) {
                throw self::changed($orderNumber, $order);
            }
            $this->guardedPayment($order, $target);
            $this->pdo->prepare(
                "UPDATE payment_proofs SET reviewed_by_admin_id = ?, reviewed_at = datetime('now'), review_note = ?
                 WHERE order_id = ?"
            )->execute([$adminId, $note, $order['id']]);
            $this->recordEvent($order['id'], 'payment_status', 'for_verification', $target, 'admin', $adminId, $note);
        });
    }

    /** @param array{id: int, status: string} $order */
    private function guardedStatus(array $order, string $target, ?string $rejectionReason): void
    {
        $update = $this->pdo->prepare(
            "UPDATE orders SET status = ?, rejection_reason = COALESCE(?, rejection_reason),
                 status_changed_at = datetime('now'), updated_at = datetime('now')
             WHERE id = ? AND status = ?"
        );
        $update->execute([$target, $rejectionReason, $order['id'], $order['status']]);
        if ($update->rowCount() !== 1) {
            throw new AdminActionException('The order changed during the update. Review it again.');
        }
    }

    /** @param array{id: int, payment_status: string} $order */
    private function guardedPayment(array $order, string $target): void
    {
        $update = $this->pdo->prepare(
            "UPDATE orders SET payment_status = ?, updated_at = datetime('now') WHERE id = ? AND payment_status = ?"
        );
        $update->execute([$target, $order['id'], $order['payment_status']]);
        if ($update->rowCount() !== 1) {
            throw new AdminActionException('The order changed during the update. Review it again.');
        }
    }

    /**
     * Guarded per-item stock restore plus one stock_audit row each; any
     * mismatch or failure aborts the caller's transaction.
     */
    private function restoreStock(int $orderId, string $reason, int $adminId): void
    {
        $items = $this->pdo->prepare(
            'SELECT i.product_id, i.qty, p.stock FROM order_items i
             JOIN products p ON p.id = i.product_id WHERE i.order_id = ? ORDER BY i.id'
        );
        $items->execute([$orderId]);
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
                throw new RuntimeException('Stock changed while restoring an order.');
            }
            $audit->execute([(int) $item['product_id'], $adminId, $old, $old + (int) $item['qty'], $reason]);
        }
    }

    /** A required reason: whitespace collapsed, 1–MAX_REASON_LENGTH characters. */
    private static function reason(string $reason): string
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new AdminActionException(
                sprintf('Enter a reason of up to %d characters.', self::MAX_REASON_LENGTH),
                'reason'
            );
        }

        return $reason;
    }

    /** @param array{status: string, payment_status: string} $order */
    private static function changed(string $orderNumber, array $order): AdminActionException
    {
        return new AdminActionException(sprintf(
            'Order %s changed: it is now %s with payment %s. Review it again.',
            $orderNumber,
            $order['status'],
            str_replace('_', ' ', $order['payment_status'])
        ));
    }
    /**
     * @return array{id: int, status: string, payment_method: string, payment_status: string}
     */
    private function lockedOrder(string $orderNumber): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status, payment_method, payment_status FROM orders WHERE order_number = ?'
        );
        $statement->execute([$orderNumber]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new AdminActionException('That order does not exist.');
        }

        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'payment_method' => (string) $row['payment_method'],
            'payment_status' => (string) $row['payment_status'],
        ];
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
     * Validates the attempt and returns the normalized payment.
     *
     * @param list<array{product_id: int, quantity: int, price_centavos: int}> $lines
     * @param array<string, mixed> $payment
     *
     * @return array{method: string, status: string, last4: ?string, brand: ?string, result: ?string,
     *     proof: string, sender_reference: string}
     */
    private function assertValidInput(
        array $lines,
        array $shipping,
        array $payment,
        string $key,
    ): array {
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

        return $this->normalizePayment($payment);
    }

    /**
     * @param array<string, mixed> $payment
     *
     * @return array{method: string, status: string, last4: ?string, brand: ?string, result: ?string,
     *     proof: string, sender_reference: string}
     */
    private function normalizePayment(array $payment): array
    {
        $method = $payment['method'] ?? 'card';
        if (!is_string($method) || !isset(self::PAYMENT_METHODS[$method])) {
            throw new CheckoutException(
                'Choose how you would like to pay.',
                CheckoutException::REASON_INVALID
            );
        }
        $normalized = [
            'method' => $method,
            'status' => self::PAYMENT_METHODS[$method],
            'last4' => null,
            'brand' => null,
            'result' => null,
            'proof' => '',
            'sender_reference' => '',
        ];

        if ($method === 'cod') {
            return $normalized;
        }

        if ($method === 'transfer') {
            $reference = is_string($payment['sender_reference'] ?? null) ? trim($payment['sender_reference']) : '';
            $proof = $payment['proof'] ?? null;
            if (
                $reference === '' || mb_strlen($reference) > self::MAX_SENDER_REFERENCE_LENGTH
                || !is_string($proof) || !$this->requireProofs()->isStaged($proof)
            ) {
                throw new CheckoutException(
                    'Your proof of payment could not be found. Please upload it again.',
                    CheckoutException::REASON_INVALID
                );
            }

            return array_merge($normalized, ['proof' => $proof, 'sender_reference' => $reference]);
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

        return array_merge($normalized, [
            'last4' => $payment['last4'],
            'brand' => $payment['brand'],
            'result' => PaymentSimulator::RESULT_APPROVED,
        ]);
    }

    private function requireProofs(): PaymentProofService
    {
        if ($this->proofs === null) {
            throw new RuntimeException('Transfer orders need the payment proof store.');
        }

        return $this->proofs;
    }

    /** Appends one immutable status or payment-status change (R44). */
    private function recordEvent(
        int $orderId,
        string $field,
        ?string $old,
        string $new,
        string $actorRole,
        ?int $actorId,
        ?string $note = null,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO order_events (order_id, field, old_value, new_value, actor_role, actor_id, note)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$orderId, $field, $old, $new, $actorRole, $actorId, $note]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProduct(int $productId): ?array
    {
        // Archived, unlisted or switched-off bags fail like a stock conflict.
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.name, p.price_centavos, p.stock,"
            . " p.listing_status = 'active' AND p.is_available = 1 AS is_active,"
            . ' p.weaver_id, w.display_name AS weaver_name'
            . ' FROM products p LEFT JOIN weavers w ON w.id = p.weaver_id WHERE p.id = ?'
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
     * Inserts the pending order with its payment method/status and returns its id. The daily H-YYYYMMDD-NNNN
     * number is read from the current maximum suffix under the write lock;
     * the unique constraint is the only guard the insert needs. Capacity
     * exhaustion fails safely instead of emitting a wider number.
     *
     * @param array{method: string, status: string, last4: ?string, brand: ?string, result: ?string} $payment
     */
    private function insertOrder(
        array $shipping,
        array $payment,
        string $key,
        int $subtotal,
        int $shippingFee,
    ): int {
        $insert = $this->pdo->prepare(
            'INSERT INTO orders (order_number, customer_name, customer_email,'
            . ' customer_phone, shipping_address1, shipping_address2, shipping_city,'
            . ' shipping_postal_code, shipping_region, shipping_fee_centavos,'
            . ' subtotal_centavos, total_centavos, payment_last4, payment_brand,'
            . ' payment_result, idempotency_key, status, payment_method, payment_status)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?)'
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
            $payment['last4'],
            $payment['brand'],
            $payment['result'],
            $key,
            $payment['method'],
            $payment['status'],
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
            . ' price_centavos, qty, line_total_centavos, weaver_id, weaver_name_snapshot)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($rows as ['line' => $line, 'product' => $product]) {
            $insert->execute([
                $orderId,
                (int) $line['product_id'],
                (string) $product['name'],
                (int) $line['price_centavos'],
                (int) $line['quantity'],
                (int) $line['price_centavos'] * (int) $line['quantity'],
                $product['weaver_id'] === null ? null : (int) $product['weaver_id'],
                $product['weaver_name'] === null ? null : (string) $product['weaver_name'],
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
            . ' line_total_centavos, weaver_id, weaver_name_snapshot FROM order_items WHERE order_id = ?'
            . ' ORDER BY id ASC'
        );
        $items->execute([(int) $order['id']]);
        $order['items'] = $items->fetchAll();

        return $order;
    }
}
