<?php

declare(strict_types=1);

namespace Habi\Support;

/**
 * Loads KEY=VALUE pairs from a .env file without touching the real process
 * environment. Process environment variables always take precedence over
 * .env values. Values may be wrapped in single or double quotes; quoting is
 * stripped and the raw content (for example a bcrypt hash with `$` signs) is
 * preserved verbatim. No variable interpolation is performed.
 */
final class Env
{
    /** @var array<string, string>|null */
    private static ?array $values = null;

    public static function load(string $path): void
    {
        self::$values = [];

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $parsed = self::parseLine($line);
            if ($parsed !== null) {
                self::$values[$parsed[0]] = $parsed[1];
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER) && is_scalar($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }

        $fromEnvironment = getenv($key);
        if ($fromEnvironment !== false) {
            return $fromEnvironment;
        }

        return self::$values[$key] ?? $default;
    }

    /**
     * Clears parsed .env values; used by tests to isolate fixtures.
     */
    public static function reset(): void
    {
        self::$values = null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            return null;
        }

        $key = trim(substr($line, 0, $separator));
        if ($key === '') {
            return null;
        }

        return [$key, self::parseValue(trim(substr($line, $separator + 1)))];
    }

    private static function parseValue(string $value): string
    {
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            if (strlen($value) >= 2 && str_ends_with($value, $quote)) {
                return substr($value, 1, -1);
            }

            return substr($value, 1);
        }

        $comment = strpos($value, ' #');
        if ($comment !== false) {
            $value = rtrim(substr($value, 0, $comment));
        }

        return trim($value);
    }
}
