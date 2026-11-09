<?php

declare(strict_types=1);

namespace Habi\Tests\Bootstrap;

use Habi\Bootstrap\Initializer;
use Habi\Bootstrap\MissingAdminCredentialsException;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use PDO;

/**
 * First boot on an empty database without valid administrator settings must
 * fail cleanly: the transaction rolls back, no partial schema remains, and a
 * correctly configured retry succeeds without manual cleanup.
 */
final class RollbackMissingCredentialsTest extends IsolatedDatabaseTestCase
{
    public function testEmptyDatabaseWithoutAdminCredentialsLeavesNoSchema(): void
    {
        $this->clearAdminCredentials();

        $pdo = $this->database();

        try {
            Initializer::initialize($pdo, $this->migrationsDir());
            self::fail('Expected MissingAdminCredentialsException on empty database without admin settings.');
        } catch (MissingAdminCredentialsException) {
            // Expected.
        }

        self::assertSame(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            )->fetchColumn(),
            'A failed first boot must roll back completely: no tables may remain.'
        );
    }

    public function testPlaintextPasswordInsteadOfHashIsRejected(): void
    {
        $this->clearAdminCredentials();

        $_ENV['ADMIN_SEED_EMAIL'] = 'admin@example.test';
        $_ENV['ADMIN_SEED_PASSWORD_HASH'] = 'super-secret-plaintext-password';

        $pdo = $this->database();

        try {
            Initializer::initialize($pdo, $this->migrationsDir());
            self::fail('Expected MissingAdminCredentialsException for a plaintext password.');
        } catch (MissingAdminCredentialsException) {
            // Expected.
        }

        self::assertSame(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            )->fetchColumn()
        );
    }

    public function testRetryWithValidCredentialsSucceedsAfterFailedBoot(): void
    {
        $this->clearAdminCredentials();

        $pdo = $this->database();
        try {
            Initializer::initialize($pdo, $this->migrationsDir());
            self::fail('Expected MissingAdminCredentialsException.');
        } catch (MissingAdminCredentialsException) {
            // Expected.
        }

        // Restore valid settings (as the test base class would) and retry.
        $_ENV['ADMIN_SEED_EMAIL'] = 'admin@example.test';
        $_ENV['ADMIN_SEED_PASSWORD_HASH'] = password_hash('habi-demo-password', PASSWORD_DEFAULT);
        $_ENV['ADMIN_SEED_NAME'] = 'Demo administrator';

        Initializer::initialize($pdo, $this->migrationsDir());

        self::assertSame(10, (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn());
    }
}
