<?php

declare(strict_types=1);

namespace Habi\Support;

use LogicException;

/**
 * A translation key is missing from the active catalog while the
 * Translator runs in strict mode (the test suite, N9).
 */
final class MissingTranslationException extends LogicException
{
}
