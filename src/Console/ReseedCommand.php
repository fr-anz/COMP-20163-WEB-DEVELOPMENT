<?php

declare(strict_types=1);

namespace Habi\Console;

use Habi\Bootstrap\Initializer;
use Habi\Bootstrap\MissingAdminCredentialsException;
use Habi\Bootstrap\Seeder;
use Habi\Support\Database;
use Habi\Support\Env;
use PDO;
use Throwable;

/**
 * CLI entry point behind seed/seed.php: explicit destructive demo-data reset.
 * Refuses to run without the --force confirmation flag; validates the current
 * environment's administrator settings before destroying anything.
 */
final class ReseedCommand
{
    private const DROP_ORDER = [
        'login_attempts',
        'stock_audit',
        'order_items',
        'orders',
        'product_images',
        'products',
        'admins',
    ];

    public static function run(bool $force, ?string $repoRoot = null): int
    {
        $repoRoot ??= habi_repo_root();
        Env::load($repoRoot . '/.env');

        if (!$force) {
            fwrite(STDERR, <<<TXT
                Refusing to reset the Habi demo database.
                This command wipes ALL data: products, orders, inventory history and the administrator.
                Run it again with --force to confirm the destructive reset, e.g.:
                    php seed/seed.php --force

                TXT);

            return 1;
        }

        try {
            // Validate credentials before destroying anything, so a bad .env
            // can never leave the demo database dropped and unseeded.
            Seeder::adminFromEnv();
        } catch (MissingAdminCredentialsException $exception) {
            fwrite(STDERR, 'Cannot reseed: ' . $exception->getMessage() . PHP_EOL);

            return 1;
        }

        $pdo = Database::connection();

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach (self::DROP_ORDER as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $table);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            $pdo->exec('ROLLBACK');
            fwrite(STDERR, 'Reset failed: ' . $exception->getMessage() . PHP_EOL);

            return 1;
        }

        try {
            Initializer::initialize($pdo, $repoRoot . '/migrations');
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Reseed failed: ' . $exception->getMessage() . PHP_EOL);

            return 1;
        }

        $productCount = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        fwrite(STDOUT, sprintf(
            'Habi demo data reset: schema recreated, %d products seeded from the current environment.' . PHP_EOL,
            $productCount
        ));

        return 0;
    }
}
