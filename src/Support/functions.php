<?php

declare(strict_types=1);

use Habi\Support\Database;
use Habi\Support\Env;

/**
 * Reads a configuration value. Process environment always wins over .env values.
 */
function env(string $key, ?string $default = null): ?string
{
    return Env::get($key, $default);
}

/**
 * Shared PDO connection for the configured DB_DSN.
 */
function db(): PDO
{
    return Database::connection();
}

/**
 * Absolute path of the repository root (the directory containing composer.json).
 */
function habi_repo_root(): string
{
    return dirname(__DIR__, 2);
}
