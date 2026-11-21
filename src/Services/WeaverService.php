<?php

declare(strict_types=1);

namespace Habi\Services;

use PDO;

/**
 * A weaver's own account and public profile (R34, R35, N10).
 *
 * Every query takes the signed-in weaver's id from the session-backed
 * middleware, never from form input, and filters by it in SQL. Only active
 * weavers are returned or updated, so a weaver deactivated by an admin
 * loses access on the next request. Email and password are managed by
 * admins (M13) and are not editable here.
 */
final class WeaverService
{
    /** Field => maximum length for the profile form. */
    public const PROFILE_LIMITS = [
        'display_name' => 80,
        'community' => 120,
        'region' => 120,
        'fibers' => 200,
        'techniques' => 200,
        'story' => 4000,
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The active weaver with this id, or null.
     *
     * @return array{id: int, email: string, slug: string, display_name: string, community: string,
     *     region: string, fibers: string, techniques: string, story: string, photo_path: ?string}|null
     */
    public function findActive(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, slug, display_name, community, region, fibers, techniques, story, photo_path
             FROM weavers WHERE id = ? AND is_active = 1'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'slug' => (string) $row['slug'],
            'display_name' => (string) $row['display_name'],
            'community' => (string) $row['community'],
            'region' => (string) $row['region'],
            'fibers' => (string) $row['fibers'],
            'techniques' => (string) $row['techniques'],
            'story' => (string) $row['story'],
            'photo_path' => $row['photo_path'] === null ? null : (string) $row['photo_path'],
        ];
    }

    /**
     * Validates the profile form. Line breaks are kept only in the story.
     *
     * @param array<string, mixed> $input
     *
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    public function validateProfile(array $input): array
    {
        $values = [];
        $errors = [];
        foreach (self::PROFILE_LIMITS as $field => $limit) {
            $value = is_string($input[$field] ?? null) ? trim(str_replace("\r\n", "\n", $input[$field])) : '';
            if ($field !== 'story') {
                $value = preg_replace('/\s+/u', ' ', $value) ?? '';
            }
            $values[$field] = $value;
            if (mb_strlen($value) > $limit) {
                $errors[$field] = sprintf('Keep this under %s characters.', number_format($limit));
            }
        }
        if ($values['display_name'] === '') {
            $errors['display_name'] = 'Enter the name shown on your profile.';
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Saves the signed-in weaver's profile; a new photo replaces the old one.
     *
     * @param array<string, string> $values validateProfile()['values'] without errors
     *
     * @throws AdminActionException When the weaver is no longer active.
     */
    public function updateProfile(int $weaverId, array $values, ?string $photoPath): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE weavers SET display_name = ?, community = ?, region = ?, fibers = ?, techniques = ?,
                 story = ?, photo_path = COALESCE(?, photo_path), updated_at = datetime('now')
             WHERE id = ? AND is_active = 1"
        );
        $statement->execute([
            $values['display_name'], $values['community'], $values['region'], $values['fibers'],
            $values['techniques'], $values['story'], $photoPath, $weaverId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new AdminActionException('Your account is no longer active.');
        }
    }
}
