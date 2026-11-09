<?php

declare(strict_types=1);

namespace Habi\Tests\TestSupport;

use Habi\Support\Database;
use Habi\Support\Env;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Gives every test an isolated temporary SQLite database and a controlled
 * environment, independent of the developer's real .env file.
 */
abstract class IsolatedDatabaseTestCase extends TestCase
{
    private const MANAGED_KEYS = [
        'DB_DSN',
        'ADMIN_SEED_EMAIL',
        'ADMIN_SEED_PASSWORD_HASH',
        'ADMIN_SEED_NAME',
    ];

    private ?string $tempDir = null;
    private EnvironmentSnapshot $environmentSnapshot;

    protected function setUp(): void
    {
        $this->environmentSnapshot = new EnvironmentSnapshot(self::MANAGED_KEYS);
        $this->clearEnvironment();

        $this->tempDir = sys_get_temp_dir() . '/habi-test-' . getmypid() . '-' . uniqid();
        $this->assertTrue(mkdir($this->tempDir, 0775, true), 'Failed to create test fixture directory.');

        $_ENV['DB_DSN'] = 'sqlite:' . $this->tempDir . '/app.sqlite';
        $_ENV['ADMIN_SEED_EMAIL'] = 'admin@example.test';
        $_ENV['ADMIN_SEED_PASSWORD_HASH'] = password_hash('habi-demo-password', PASSWORD_DEFAULT);
        $_ENV['ADMIN_SEED_NAME'] = 'Demo administrator';

        Env::reset();
    }

    protected function tearDown(): void
    {
        Database::closeAll();

        if ($this->tempDir !== null) {
            foreach (['app.sqlite', 'app.sqlite-wal', 'app.sqlite-shm'] as $file) {
                if (is_file($this->tempDir . '/' . $file)) {
                    @unlink($this->tempDir . '/' . $file);
                }
            }
            @rmdir($this->tempDir);
        }

        $this->clearEnvironment();
        $this->environmentSnapshot->restore();
    }

    protected function database(): PDO
    {
        return Database::connection();
    }

    protected function migrationsDir(): string
    {
        return habi_repo_root() . '/migrations';
    }

    /** Clear credentials without losing the isolated database selected in setUp(). */
    protected function clearAdminCredentials(): void
    {
        foreach (['ADMIN_SEED_EMAIL', 'ADMIN_SEED_PASSWORD_HASH', 'ADMIN_SEED_NAME'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        Env::reset();
    }

    /**
     * Removes the managed keys from every environment layer, so tests see a
     * clean slate regardless of the parent process or a loaded .env file.
     */
    protected function clearEnvironment(): void
    {
        foreach (self::MANAGED_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        Env::reset();
    }
}
