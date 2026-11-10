<?php

declare(strict_types=1);

namespace Habi\Support;

/**
 * minimal array-access wrapper around PHP session storage. Taking the session
 * array by reference keeps the store live: writes through the wrapper land in
 * $_SESSION immediately, and tests can share state by wrapping the same array.
 */
final class SessionStore
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array &$data)
    {
        $this->data = &$data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function unset(string $key): void
    {
        unset($this->data[$key]);
    }
}
