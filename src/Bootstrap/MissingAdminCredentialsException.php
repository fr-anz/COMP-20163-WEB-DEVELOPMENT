<?php

declare(strict_types=1);

namespace Habi\Bootstrap;

use RuntimeException;

/**
 * Thrown when an empty database cannot be initialized because the required
 * first-boot administrator settings (ADMIN_SEED_EMAIL / ADMIN_SEED_PASSWORD_HASH)
 * are missing or invalid. Never carries credential values.
 */
final class MissingAdminCredentialsException extends RuntimeException
{
}
