<?php

declare(strict_types=1);

namespace Habi\Services;

use RuntimeException;

/**
 * Checkout failure with a stable machine reason and a human message
 * (M5, PRD R15/R16). Reasons:
 *
 * - stock:   an item sold out before Place could decrement it;
 * - changed: a live price no longer matches the reviewed snapshot;
 * - invalid: malformed input, an unavailable product or a payment that
 *            was never approved.
 *
 * PDO errors are deliberately not wrapped — the controller turns them
 * into a generic friendly retry.
 */
final class CheckoutException extends RuntimeException
{
    public const REASON_STOCK = 'stock';
    public const REASON_CHANGED = 'changed';
    public const REASON_INVALID = 'invalid';

    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }
}
