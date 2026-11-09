<?php

declare(strict_types=1);

// The PHP development server serves static JS without compression. Let zlib
// negotiate gzip for the pinned module so the same viewer meets N1 locally.
ini_set('zlib.output_compression', '1');
header('Content-Type: text/javascript; charset=UTF-8');
header('Cache-Control: public, max-age=31536000, immutable');
header('Vary: Accept-Encoding');
readfile(__DIR__ . '/three.module.js');
