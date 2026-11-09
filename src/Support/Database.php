<?php

declare(strict_types=1);

namespace Habi\Support;

use PDO;
use RuntimeException;

/**
 * PDO factory for the configured DB_DSN. Connections are cached per DSN so
 * repeated db() calls within a request reuse one handle. Relative SQLite
 * paths are resolved against the repository root so the working directory of
 * the web server or CLI process does not matter.
 */
final class Database
{
    private const SQLITE_PREFIX = 'sqlite:';

    /** @var array<string, PDO> */
    private static array $connections = [];

    public static function connection(?string $dsn = null): PDO
    {
        $dsn ??= (string) Env::get('DB_DSN', 'sqlite:data/app.sqlite');

        return self::$connections[$dsn] ??= self::connect($dsn);
    }

    /**
     * Closes every cached connection; used by tests to release fixture files.
     */
    public static function closeAll(): void
    {
        self::$connections = [];
    }

    private static function connect(string $dsn): PDO
    {
        $resolved = self::resolveDsn($dsn);
        $path = str_starts_with($resolved, self::SQLITE_PREFIX)
            ? substr($resolved, strlen(self::SQLITE_PREFIX))
            : null;

        if ($path !== null && $path !== '' && $path !== ':memory:') {
            self::ensureDirectory($path);
        }

        $pdo = new PDO($resolved, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        if ($path !== null) {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return $pdo;
    }

    private static function resolveDsn(string $dsn): string
    {
        if (!str_starts_with($dsn, self::SQLITE_PREFIX)) {
            return $dsn;
        }

        $path = substr($dsn, strlen(self::SQLITE_PREFIX));
        if ($path === '' || $path === ':memory:' || str_starts_with($path, '/')) {
            return $dsn;
        }

        return self::SQLITE_PREFIX . habi_repo_root() . '/' . $path;
    }

    private static function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create database directory "%s".', $directory));
        }
    }
}
