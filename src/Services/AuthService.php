<?php

declare(strict_types=1);

namespace Habi\Services;

use Closure;
use PDO;
use Throwable;

/**
 * Administrator and weaver sign-in with throttling (PRD R19, R34, SDD §10,
 * §15.1).
 *
 * Passwords are checked with password_verify. An unknown email is verified
 * against an existing administrator's hash (same algorithm and cost), so
 * both failure kinds take the same time and return the same generic result.
 * Deactivated weavers are treated as unknown. Failures are counted per
 * hashed role+email+client-IP key in login_attempts (the role prefix,
 * "admin:" or "weaver:", is added before hashing so roles never share a
 * counter): the fifth consecutive failure locks that key for 60 seconds,
 * during which even a correct password is not checked. A success clears
 * the key.
 *
 * The slow password hashing runs outside any transaction, so sign-in
 * traffic never holds SQLite's write lock against checkout or inventory.
 * The counter update and a lock re-check then run in one short
 * BEGIN IMMEDIATE transaction: concurrent guesses cannot race past the
 * counter, and a key locked meanwhile refuses even a correct password.
 */
final class AuthService
{
    public const MAX_FAILURES = 5;
    public const LOCKOUT_SECONDS = 60;

    public const STATUS_OK = 'ok';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_LOCKED = 'locked';

    /** Fallback only when no administrator exists (never after seeding). */
    private const DUMMY_HASH = '$2y$10$z78AA7b4pVDBRYGu92hQz.ELaBT0Hbl7c7ffU1/4zrqAagvowD.WW';

    private readonly Closure $clock;

    public function __construct(private readonly PDO $pdo, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array{
     *     status: string,
     *     admin?: array{id: int, email: string, name: string},
     *     retry_after?: int
     * }
     */
    public function attempt(string $email, string $password, string $clientIp): array
    {
        return $this->authenticate('admin', $email, $password, $clientIp);
    }

    /**
     * Weaver sign-in (R34): same rules, own throttle counters, active weavers only.
     *
     * @return array{
     *     status: string,
     *     weaver?: array{id: int, email: string, name: string},
     *     retry_after?: int
     * }
     */
    public function attemptWeaver(string $email, string $password, string $clientIp): array
    {
        return $this->authenticate('weaver', $email, $password, $clientIp);
    }

    /**
     * @param 'admin'|'weaver' $role
     *
     * @return array<string, mixed>
     */
    private function authenticate(string $role, string $email, string $password, string $clientIp): array
    {
        $email = trim($email);
        $key = hash('sha256', $role . ':' . mb_strtolower($email) . '|' . $clientIp);

        $locked = $this->lockedFor($key);
        if ($locked > 0) {
            return ['status' => self::STATUS_LOCKED, 'retry_after' => $locked];
        }

        $account = $this->findAccount($role, $email);
        $valid = password_verify($password, $account['password_hash'] ?? $this->timingHash())
            && $account !== null && $email !== '' && $password !== '';

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $now = ($this->clock)();
            $state = $this->pdo->prepare(
                'SELECT failures, locked_until FROM login_attempts WHERE throttle_key = ?'
            );
            $state->execute([$key]);
            $row = $state->fetch();
            $failures = $row === false ? 0 : (int) $row['failures'];
            $lockedUntil = $row === false ? 0 : (int) $row['locked_until'];

            if ($lockedUntil > $now) {
                // Concurrent failures locked the key while this one was hashing.
                $this->pdo->exec('COMMIT');

                return ['status' => self::STATUS_LOCKED, 'retry_after' => $lockedUntil - $now];
            }

            if ($valid) {
                $this->pdo->prepare('DELETE FROM login_attempts WHERE throttle_key = ?')->execute([$key]);
                $this->pdo->exec('COMMIT');
                if (password_needs_rehash($account['password_hash'], PASSWORD_DEFAULT)) {
                    $table = $role === 'weaver' ? 'weavers' : 'admins';
                    $this->pdo->prepare('UPDATE ' . $table . ' SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $account['id']]);
                }

                return ['status' => self::STATUS_OK, $role => [
                    'id' => (int) $account['id'],
                    'email' => (string) $account['email'],
                    'name' => (string) $account['name'],
                ]];
            }

            // An expired lock starts a fresh count of five.
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
                : ['status' => self::STATUS_INVALID];
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // The original failure is what matters.
            }

            throw $exception;
        }
    }

    /** Seconds the key stays locked, or 0. A plain read; no write lock. */
    private function lockedFor(string $key): int
    {
        $statement = $this->pdo->prepare('SELECT locked_until FROM login_attempts WHERE throttle_key = ?');
        $statement->execute([$key]);
        $until = (int) $statement->fetchColumn();

        return max(0, $until - ($this->clock)());
    }

    /**
     * A real administrator hash for unknown-email verification: identical
     * algorithm and cost, and it cannot authenticate because no account row
     * matched the submitted email.
     */
    private function timingHash(): string
    {
        $hash = $this->pdo->query('SELECT password_hash FROM admins ORDER BY id LIMIT 1')->fetchColumn();

        return is_string($hash) && $hash !== '' ? $hash : self::DUMMY_HASH;
    }

    /**
     * The administrator, or the active weaver, with this email.
     *
     * @return array<string, mixed>|null
     */
    private function findAccount(string $role, string $email): ?array
    {
        if ($email === '') {
            return null;
        }
        $statement = $this->pdo->prepare($role === 'weaver'
            ? 'SELECT id, email, display_name AS name, password_hash FROM weavers
               WHERE lower(email) = lower(?) AND is_active = 1 LIMIT 1'
            : 'SELECT id, email, name, password_hash FROM admins WHERE lower(email) = lower(?) LIMIT 1');
        $statement->execute([$email]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
