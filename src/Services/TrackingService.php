<?php

declare(strict_types=1);

namespace Habi\Services;

use Closure;
use PDO;
use Throwable;

/**
 * Guest order tracking (PRD R32, N4; SDD §15.1, §15.10).
 *
 * An order is shown only when its reference number and the email used at
 * checkout both match; a wrong email, an unknown or a malformed reference
 * all return the same not-found result. Results carry status, payment
 * status, their events, items, totals and the delivery city/region — never
 * the street address, phone, email or customer name.
 *
 * Failed lookups are counted in login_attempts under the client IP hashed
 * with a `track:` prefix, so tracking never shares counters with staff
 * sign-in. The fifth failure locks the client for 60 seconds, during which
 * even a correct lookup is refused. Successful lookups do not reset the
 * count, so knowing one's own order cannot be used to keep guessing others.
 * Blank fields are inline errors and are not counted.
 */
final class TrackingService
{
    public const MAX_FAILURES = 5;
    public const LOCKOUT_SECONDS = 60;

    public const STATUS_FOUND = 'found';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_LOCKED = 'locked';
    public const STATUS_INVALID = 'invalid';

    private const REFERENCE_PATTERN = '/^H-\d{8}-\d{4}$/';

    private readonly Closure $clock;

    public function __construct(private readonly PDO $pdo, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array{status: string, order?: array<string, mixed>, errors?: array<string, string>,
     *     retry_after?: int}
     */
    public function lookup(string $reference, string $email, string $clientIp): array
    {
        $reference = strtoupper(trim($reference));
        $email = mb_strtolower(trim($email));
        $errors = [];
        if ($reference === '') {
            $errors['reference'] = 'Enter your order reference number.';
        }
        if ($email === '') {
            $errors['email'] = 'Enter the email you used at checkout.';
        }
        if ($errors !== []) {
            return ['status' => self::STATUS_INVALID, 'errors' => $errors];
        }

        $key = hash('sha256', 'track:' . $clientIp);
        $locked = $this->lockedFor($key);
        if ($locked > 0) {
            return ['status' => self::STATUS_LOCKED, 'retry_after' => $locked];
        }

        $order = preg_match(self::REFERENCE_PATTERN, $reference) === 1 ? $this->findOrder($reference) : null;
        if ($order !== null && hash_equals(mb_strtolower((string) $order['customer_email']), $email)) {
            unset($order['customer_email']);

            return ['status' => self::STATUS_FOUND, 'order' => $this->withDetails($order)];
        }

        return $this->recordFailure($key);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOrder(string $reference): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_number, status, payment_method, payment_status, customer_email,
                    shipping_city, shipping_region, subtotal_centavos, shipping_fee_centavos,
                    total_centavos, rejection_reason, status_changed_at, created_at
             FROM orders WHERE order_number = ?'
        );
        $statement->execute([$reference]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function withDetails(array $order): array
    {
        $items = $this->pdo->prepare(
            'SELECT name_snapshot, qty, price_centavos, line_total_centavos
             FROM order_items WHERE order_id = ? ORDER BY id'
        );
        $items->execute([(int) $order['id']]);
        $events = $this->pdo->prepare(
            'SELECT field, old_value, new_value, created_at
             FROM order_events WHERE order_id = ? ORDER BY created_at, id'
        );
        $events->execute([(int) $order['id']]);
        unset($order['id']);
        $order['items'] = $items->fetchAll();
        $order['events'] = $events->fetchAll();

        return $order;
    }

    /**
     * Counts one failure in a short write transaction and returns the
     * generic not-found, or locked on the failure that reaches the limit.
     *
     * @return array{status: string, retry_after?: int}
     */
    private function recordFailure(string $key): array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $now = ($this->clock)();
            $state = $this->pdo->prepare('SELECT failures, locked_until FROM login_attempts WHERE throttle_key = ?');
            $state->execute([$key]);
            $row = $state->fetch();
            $failures = $row === false ? 0 : (int) $row['failures'];
            $lockedUntil = $row === false ? 0 : (int) $row['locked_until'];
            if ($lockedUntil > $now) {
                $this->pdo->exec('COMMIT');

                return ['status' => self::STATUS_LOCKED, 'retry_after' => $lockedUntil - $now];
            }

            // An expired lock starts a fresh count.
            $failures = ($lockedUntil > 0 ? 0 : $failures) + 1;
            $locks = $failures >= self::MAX_FAILURES;
            $this->pdo->prepare(
                'INSERT INTO login_attempts (throttle_key, failures, locked_until) VALUES (?, ?, ?)'
                . ' ON CONFLICT (throttle_key) DO UPDATE SET failures = excluded.failures,'
                . ' locked_until = excluded.locked_until'
            )->execute([$key, $locks ? 0 : $failures, $locks ? $now + self::LOCKOUT_SECONDS : 0]);
            $this->pdo->exec('COMMIT');

            return $locks
                ? ['status' => self::STATUS_LOCKED, 'retry_after' => self::LOCKOUT_SECONDS]
                : ['status' => self::STATUS_NOT_FOUND];
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // The original failure is what matters.
            }

            throw $exception;
        }
    }

    private function lockedFor(string $key): int
    {
        $statement = $this->pdo->prepare('SELECT locked_until FROM login_attempts WHERE throttle_key = ?');
        $statement->execute([$key]);

        return max(0, (int) $statement->fetchColumn() - ($this->clock)());
    }
}
