<?php

declare(strict_types=1);

namespace Habi\Services;

use Habi\Bootstrap\HeritageUpgrade;
use Habi\Support\PlainText;
use PDO;
use PDOException;

/**
 * Weaver accounts managed by administrators (PRD R40, R43; D9: there is no
 * self-registration). Admins create a weaver with a login email, a password
 * they enter or one generated here, and the public profile; they edit the
 * profile (plain text, N2), deactivate or reactivate the account and reset
 * its password. Passwords are stored only as password_hash() values; a
 * generated password is returned once so the caller can show it a single
 * time. Deactivation blocks sign-in (AuthService checks is_active) and the
 * next weaver request. The inactive placeholder weaver is never listed,
 * edited, activated or given a password.
 */
final class AdminWeaverService
{
    /** Profile field => maximum length (WeaverService::PROFILE_LIMITS plus email). */
    public const LIMITS = ['email' => 254] + WeaverService::PROFILE_LIMITS;

    public const MIN_PASSWORD_LENGTH = 10;

    /** bcrypt reads at most 72 bytes. */
    public const MAX_PASSWORD_BYTES = 72;

    private const GENERATED_ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Every real weaver with product and pending-listing counts.
     *
     * @return list<array{id: int, slug: string, email: string, display_name: string, community: string,
     *     region: string, is_active: bool, products: int, pending: int}>
     */
    public function list(): array
    {
        $statement = $this->pdo->prepare(
            "SELECT w.id, w.slug, w.email, w.display_name, w.community, w.region, w.is_active,
                    (SELECT COUNT(*) FROM products p WHERE p.weaver_id = w.id) AS products,
                    (SELECT COUNT(*) FROM products p WHERE p.weaver_id = w.id
                        AND p.listing_status = 'pending_review') AS pending
             FROM weavers w WHERE w.slug <> ? ORDER BY w.is_active DESC, w.display_name COLLATE NOCASE, w.id"
        );
        $statement->execute([HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'community' => (string) $row['community'],
            'region' => (string) $row['region'],
            'is_active' => (int) $row['is_active'] === 1,
            'products' => (int) $row['products'],
            'pending' => (int) $row['pending'],
        ], $statement->fetchAll());
    }

    /**
     * One real weaver (active or not), or null; never the placeholder.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, slug, display_name, community, region, fibers, techniques, story, photo_path,
                    is_active, created_at, updated_at FROM weavers WHERE id = ? AND slug <> ?'
        );
        $statement->execute([$id, HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (int) $row['is_active'] === 1;

        return $row;
    }

    /**
     * @param array<string, mixed>|null $weaver
     *
     * @return array<string, string>
     */
    public function formValues(?array $weaver): array
    {
        $values = ['password' => ''];
        foreach (array_keys(self::LIMITS) as $field) {
            $values[$field] = $weaver === null ? '' : (string) $weaver[$field];
        }

        return $values;
    }

    /**
     * Validates the account/profile form. The password is only read when
     * creating ($weaverId null): empty means "generate one".
     *
     * @param array<string, mixed> $input
     *
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    public function validate(array $input, ?int $weaverId): array
    {
        $values = [];
        $errors = [];
        foreach (self::LIMITS as $field => $limit) {
            $raw = is_string($input[$field] ?? null) ? $input[$field] : '';
            $value = $field === 'story'
                ? PlainText::paragraphs($raw)
                : trim((string) preg_replace('/\s+/u', ' ', $raw));
            $values[$field] = $value;
            if (mb_strlen($value) > $limit) {
                $errors[$field] = sprintf('Keep this under %s characters.', number_format($limit));
            } elseif (PlainText::hasMarkup($value)) {
                $errors[$field] = 'Use plain text; HTML tags are not allowed.';
            }
        }
        if ($values['display_name'] === '' && !isset($errors['display_name'])) {
            $errors['display_name'] = 'Enter the name shown on the weaver profile.';
        }
        if (!isset($errors['email'])) {
            if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Enter a valid email address, e.g. name@example.com.';
            } elseif ($this->emailTaken($values['email'], $weaverId)) {
                $errors['email'] = 'Another weaver already uses this email.';
            }
        }
        if ($weaverId === null) {
            $values['password'] = is_string($input['password'] ?? null) ? $input['password'] : '';
            $error = $values['password'] === '' ? null : self::passwordError($values['password']);
            if ($error !== null) {
                $errors['password'] = $error;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** Null when the entered password is acceptable, otherwise the message. */
    public static function passwordError(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH || strlen($password) > self::MAX_PASSWORD_BYTES) {
            return sprintf(
                'Use %d to %d characters, or leave it empty to generate one.',
                self::MIN_PASSWORD_LENGTH,
                self::MAX_PASSWORD_BYTES
            );
        }

        return null;
    }

    /**
     * Creates an active weaver. Returns the new id and the generated
     * password (null when the admin entered one).
     *
     * @param array<string, string> $values validate()['values'] without errors
     *
     * @return array{id: int, password: ?string}
     */
    public function create(array $values, int $adminId, ?string $photoPath): array
    {
        $generated = ($values['password'] ?? '') === '' ? self::generatePassword() : null;
        try {
            $this->pdo->prepare(
                'INSERT INTO weavers (email, password_hash, slug, display_name, community, region, fibers,
                     techniques, story, photo_path, is_active, created_by_admin_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
            )->execute([
                $values['email'], password_hash($generated ?? $values['password'], PASSWORD_DEFAULT),
                $this->uniqueSlug($values['display_name']), $values['display_name'], $values['community'],
                $values['region'], $values['fibers'], $values['techniques'], $values['story'], $photoPath, $adminId,
            ]);
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'weavers.email')) {
                throw new AdminActionException('Another weaver already uses this email.', 'email');
            }

            throw $exception;
        }

        return ['id' => (int) $this->pdo->lastInsertId(), 'password' => $generated];
    }

    /**
     * Saves the account email and profile (R43). The slug, and with it the
     * public profile URL, stays unchanged; a new photo replaces the old one.
     *
     * @param array<string, string> $values validate()['values'] without errors
     */
    public function update(int $id, array $values, ?string $photoPath): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE weavers SET email = ?, display_name = ?, community = ?, region = ?, fibers = ?, techniques = ?,
                 story = ?, photo_path = COALESCE(?, photo_path), updated_at = datetime('now')
             WHERE id = ? AND slug <> ?"
        );
        try {
            $statement->execute([
                $values['email'], $values['display_name'], $values['community'], $values['region'],
                $values['fibers'], $values['techniques'], $values['story'], $photoPath, $id,
                HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG,
            ]);
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'weavers.email')) {
                throw new AdminActionException('Another weaver already uses this email.', 'email');
            }

            throw $exception;
        }
        if ($statement->rowCount() !== 1) {
            throw new AdminActionException('That weaver no longer exists.');
        }
    }

    /**
     * Sets an entered password, or generates one when $password is empty.
     * Returns the generated password (shown once), or null.
     *
     * @throws AdminActionException With field "password" for an unacceptable password.
     */
    public function setPassword(int $id, string $password): ?string
    {
        if ($password !== '') {
            $error = self::passwordError($password);
            if ($error !== null) {
                throw new AdminActionException($error, 'password');
            }
        }
        $generated = $password === '' ? self::generatePassword() : null;
        $statement = $this->pdo->prepare(
            "UPDATE weavers SET password_hash = ?, updated_at = datetime('now') WHERE id = ? AND slug <> ?"
        );
        $statement->execute([
            password_hash($generated ?? $password, PASSWORD_DEFAULT), $id, HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new AdminActionException('That weaver no longer exists.');
        }

        return $generated;
    }

    /** Deactivates (false) or reactivates (true) a weaver account; orders are kept. */
    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE weavers SET is_active = ?, updated_at = datetime('now') WHERE id = ? AND slug <> ?"
        );
        $statement->execute([$active ? 1 : 0, $id, HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG]);
        if ($statement->rowCount() !== 1) {
            throw new AdminActionException('That weaver no longer exists.');
        }
    }

    /** 16 random characters from an alphabet without look-alike characters. */
    public static function generatePassword(): string
    {
        $password = '';
        $last = strlen(self::GENERATED_ALPHABET) - 1;
        for ($i = 0; $i < 16; $i++) {
            $password .= self::GENERATED_ALPHABET[random_int(0, $last)];
        }

        return $password;
    }

    private function emailTaken(string $email, ?int $exceptId): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM weavers WHERE lower(email) = lower(?) AND id != ?');
        $statement->execute([$email, $exceptId ?? 0]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(
            (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)
        )), '-');
        $base = $base === '' || $base === HeritageUpgrade::PLACEHOLDER_WEAVER_SLUG ? 'weaver' : substr($base, 0, 60);
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM weavers WHERE slug = ?');
        for ($suffix = 1;; $suffix++) {
            $slug = $suffix === 1 ? $base : $base . '-' . $suffix;
            $check->execute([$slug]);
            if ((int) $check->fetchColumn() === 0) {
                return $slug;
            }
        }
    }
}
