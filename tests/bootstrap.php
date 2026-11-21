<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// N9: a translation key missing from the active catalog fails the test
// instead of silently falling back to English.
Habi\Support\Translator::failOnMissing(true);
