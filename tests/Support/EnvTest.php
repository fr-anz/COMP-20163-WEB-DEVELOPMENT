<?php

declare(strict_types=1);

namespace Habi\Tests\Support;

use Habi\Support\Env;
use Habi\Tests\TestSupport\EnvironmentSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Environment parsing boundary: quoted values (such as bcrypt hashes with
 * `$` signs) must survive verbatim, and process environment always wins
 * over .env values.
 */
final class EnvTest extends TestCase
{
    private string $tempFile;
    private EnvironmentSnapshot $environmentSnapshot;
    private const MANAGED_KEYS = [
        'APP_ENV', 'PLAIN_VALUE', 'QUOTED_DOUBLE', 'QUOTED_SINGLE', 'INLINE_COMMENT', 'EMPTY_VALUE',
    ];

    protected function setUp(): void
    {
        $this->environmentSnapshot = new EnvironmentSnapshot(self::MANAGED_KEYS);
        foreach (self::MANAGED_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        Env::reset();
        $this->tempFile = (string) tempnam(sys_get_temp_dir(), 'habi-env-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempFile)) {
            @unlink($this->tempFile);
        }

        foreach (self::MANAGED_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        Env::reset();
        $this->environmentSnapshot->restore();
    }

    public function testQuotedHashValueIsParsedVerbatim(): void
    {
        $hash = '$2y$10$AbCdEfGhIjKlMnOpQrStUu1V2W3X4Y5Z6a7B8c9D0e1F2g3H4iJkL';
        file_put_contents($this->tempFile, <<<ENV
            # Habi demo settings
            APP_ENV=local
            PLAIN_VALUE=hello world
            QUOTED_DOUBLE=" spaced  value "
            QUOTED_SINGLE='{$hash}'
            INLINE_COMMENT=value # trailing comment
            EMPTY_VALUE=

            ENV);

        Env::load($this->tempFile);

        self::assertSame('local', Env::get('APP_ENV'));
        self::assertSame('hello world', Env::get('PLAIN_VALUE'));
        self::assertSame(' spaced  value ', Env::get('QUOTED_DOUBLE'));
        self::assertSame($hash, Env::get('QUOTED_SINGLE'), 'A quoted hash must survive parsing byte for byte.');
        self::assertSame('value', Env::get('INLINE_COMMENT'));
        self::assertSame('', Env::get('EMPTY_VALUE'));
    }

    public function testProcessEnvironmentWinsOverDotEnv(): void
    {
        file_put_contents($this->tempFile, "APP_ENV=local\nPLAIN_VALUE=from-file\n");

        $_ENV['APP_ENV'] = 'production';
        putenv('PLAIN_VALUE=from-process');

        Env::load($this->tempFile);

        self::assertSame('production', Env::get('APP_ENV'), '$_ENV must override the .env file.');
        self::assertSame('from-process', Env::get('PLAIN_VALUE'), 'getenv() must override the .env file.');

        unset($_ENV['APP_ENV']);
        putenv('PLAIN_VALUE');

        self::assertSame('local', Env::get('APP_ENV'), 'Without process env, the .env value applies.');
        self::assertSame('from-file', Env::get('PLAIN_VALUE'));
    }

    public function testMissingFileAndMissingKeysFallBackToDefault(): void
    {
        Env::load(sys_get_temp_dir() . '/habi-env-does-not-exist-' . uniqid());

        self::assertNull(Env::get('APP_ENV'));
        self::assertSame('fallback', Env::get('APP_ENV', 'fallback'));
    }
}
