<?php

declare(strict_types=1);

namespace Habi\Bootstrap;

use PDO;
use RuntimeException;
use Throwable;

/**
 * First-boot initialization: runs migrations and seeds the demo catalog
 * exactly once. Subsequent boots (and concurrent boots) preserve all data.
 *
 * Safety against concurrent boots: the whole run happens inside a single
 * SQLite BEGIN IMMEDIATE transaction, so only one process can hold the write
 * lock at a time. The schema check is repeated after the lock is acquired;
 * the loser of the race sees the winner's committed schema and exits without
 * touching data.
 */
final class Initializer
{
    public const REQUIRED_TABLES = [
        'admins',
        'products',
        'product_images',
        'orders',
        'order_items',
        'stock_audit',
    ];

    /**
     * @param callable(PDO): void|null $seeder
     */
    public static function initialize(PDO $pdo, string $migrationsDir, ?callable $seeder = null): void
    {
        if (self::isInitialized($pdo)) {
            return;
        }

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (self::isInitialized($pdo)) {
                // Another process finished initialization while we waited for
                // the write lock; keep its data untouched.
                $pdo->exec('COMMIT');

                return;
            }

            self::runMigrations($pdo, $migrationsDir);

            if ($seeder === null) {
                Seeder::seed($pdo);
            } else {
                $seeder($pdo);
            }

            $pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            $pdo->exec('ROLLBACK');
            throw $exception;
        }
    }

    public static function isInitialized(PDO $pdo): bool
    {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?"
        );

        foreach (self::REQUIRED_TABLES as $table) {
            $statement->execute([$table]);
            $exists = (int) $statement->fetchColumn() > 0;
            $statement->closeCursor();

            if (!$exists) {
                return false;
            }
        }

        return true;
    }

    private static function runMigrations(PDO $pdo, string $migrationsDir): void
    {
        $files = glob($migrationsDir . '/*.sql');
        if ($files === false || $files === []) {
            throw new RuntimeException('No migrations found.');
        }

        sort($files);
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Cannot read a migration file.');
            }

            $pdo->exec($sql);
        }
    }
}
