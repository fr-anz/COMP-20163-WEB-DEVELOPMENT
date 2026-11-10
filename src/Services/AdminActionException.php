<?php

declare(strict_types=1);

namespace Habi\Services;

use RuntimeException;

/**
 * A rejected administrator action with a message safe to show to the
 * administrator. Nothing was written when it is thrown. `field` names the
 * form field to highlight; `context` carries fresh values (for example the
 * current stock after a concurrent change).
 */
final class AdminActionException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly ?string $field = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
