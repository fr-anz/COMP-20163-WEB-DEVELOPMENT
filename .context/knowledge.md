# Project knowledge

- **PHP 8.2 `php -S` dotted paths** (verified 2026-11-10, php:8.2-cli 8.2.35): without a router, a path containing `.` that is absent from the docroot gets the server's own 404 and never runs `public/index.php`; PHP 8.5 locally routes it. Source: php-src #12604; BUILD M8.
- **Railway Config as Code** (verified 2026-11-10, docs.railway.com/infrastructure-as-code): `railway.json`/`railway.toml` deprecated, unavailable to new services, unread after 2026-12-01.

For each useful finding, record the fact, scope, source file or document, and
last-verified date. Label hypotheses clearly. Reverify commands and behavior
after relevant code or environment changes. Move detailed topic notes into
separate files when this document grows, and add them to project.md's "When to read" list.
