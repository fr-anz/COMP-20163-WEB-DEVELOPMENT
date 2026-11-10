<?php

declare(strict_types=1);

namespace Habi\Tests\Console;

use Habi\Bootstrap\Initializer;
use Habi\Console\ReseedCommand;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use PDO;

/**
 * The CLI reset is destructive and must refuse to run without --force; with
 * --force it wipes all data and reseeds from the current environment. The
 * command is pointed at an isolated repository directory (symlinked
 * migrations, no .env) so the developer's real settings cannot interfere.
 */
final class ReseedCommandTest extends IsolatedDatabaseTestCase
{
    private ?string $tempRepo = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRepo = sys_get_temp_dir() . '/habi-reseed-' . getmypid() . '-' . uniqid();
        $this->assertTrue(mkdir($this->tempRepo, 0775, true), 'Failed to create isolated repo directory.');
        $this->assertTrue(
            symlink($this->migrationsDir(), $this->tempRepo . '/migrations'),
            'Failed to link migrations into the isolated repo directory.'
        );
    }

    protected function tearDown(): void
    {
        if ($this->tempRepo !== null) {
            @unlink($this->tempRepo . '/migrations');
            @rmdir($this->tempRepo);
        }
        $this->tempRepo = null;

        parent::tearDown();
    }

    public function testWithoutForceFlagTheResetIsRefusedAndDataIsPreserved(): void
    {
        $pdo = $this->database();
        Initializer::initialize($pdo, $this->migrationsDir());
        $pdo->exec('UPDATE products SET stock = 123 WHERE slug = \'sinag-tote\'');

        $exitCode = ReseedCommand::run(false, $this->tempRepo);

        self::assertNotSame(0, $exitCode, 'Reset without --force must exit non-zero.');
        self::assertSame(
            123,
            (int) $this->scalar($pdo, "SELECT stock FROM products WHERE slug = 'sinag-tote'"),
            'Refused reset must not touch existing data.'
        );
        self::assertSame(4, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM products'));
    }

    public function testWithForceFlagTheResetWipesAndReseedsFromCurrentEnvironment(): void
    {
        $pdo = $this->database();
        Initializer::initialize($pdo, $this->migrationsDir());
        $pdo->exec('UPDATE products SET stock = 123 WHERE slug = \'sinag-tote\'');
        $pdo->exec("UPDATE admins SET email = 'old-admin@example.test'");

        $_ENV['ADMIN_SEED_EMAIL'] = 'reseed-admin@example.test';

        $exitCode = ReseedCommand::run(true, $this->tempRepo);

        self::assertSame(0, $exitCode);
        self::assertSame(4, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM products'));
        self::assertSame(
            12,
            (int) $this->scalar($pdo, "SELECT stock FROM products WHERE slug = 'sinag-tote'"),
            'Forced reset must restore the seeded stock value.'
        );
        self::assertSame(
            'reseed-admin@example.test',
            (string) $pdo->query('SELECT email FROM admins')->fetchColumn(),
            'Forced reset must seed the administrator from the current environment.'
        );
    }

    public function testWithForceFlagButMissingCredentialsNothingIsDestroyed(): void
    {
        $pdo = $this->database();
        Initializer::initialize($pdo, $this->migrationsDir());

        $dsn = (string) $_ENV['DB_DSN'];
        $this->clearEnvironment();
        $_ENV['DB_DSN'] = $dsn;

        $exitCode = ReseedCommand::run(true, $this->tempRepo);

        self::assertNotSame(0, $exitCode, 'Forced reset without valid credentials must fail.');
        self::assertSame(4, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM products'));
        self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM admins'));
    }

    private function scalar(PDO $pdo, string $sql): bool|int|string|null
    {
        return $pdo->query($sql)->fetchColumn();
    }
}
