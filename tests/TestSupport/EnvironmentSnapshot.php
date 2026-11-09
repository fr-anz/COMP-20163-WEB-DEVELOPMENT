<?php

declare(strict_types=1);

namespace Habi\Tests\TestSupport;

/** Restores only the environment keys an isolated test is allowed to change. */
final class EnvironmentSnapshot
{
    private array $values = [];

    public function __construct(array $keys)
    {
        foreach ($keys as $key) {
            $this->values[$key] = [
                'process' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
        }
    }

    public function restore(): void
    {
        foreach ($this->values as $key => $value) {
            unset($_ENV[$key], $_SERVER[$key]);
            if ($value['env_exists']) {
                $_ENV[$key] = $value['env'];
            }
            if ($value['server_exists']) {
                $_SERVER[$key] = $value['server'];
            }
            putenv($value['process'] === false ? $key : $key . '=' . $value['process']);
        }
    }
}
