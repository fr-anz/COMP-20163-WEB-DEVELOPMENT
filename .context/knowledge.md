# Project knowledge

- **PHP 8.2 `php -S` dotted paths** (verified 2026-11-10, php:8.2-cli 8.2.35): without a router, a path containing `.` that is absent from the docroot gets the server's own 404 and never runs `public/index.php`; PHP 8.5 locally routes it. Source: php-src #12604; BUILD M8.
- **GCE Always Free** (verified 2026-11-10, docs.cloud.google.com/free + cloud.google.com/vpc/network-pricing): e2-micro in us-west1/us-central1/us-east1, 30 GB pd-standard, 1 GB egress; external IPv4 on a VM $0.005/h (1 h/month free).
- **Compose env_file** (verified 2026-11-10, Compose v5.7.0): default interpolation truncates `$2y$10$…`; `format: raw` keeps it (and keeps quotes literally).
- **Railway Config as Code** (verified 2026-11-10, docs.railway.com/infrastructure-as-code): `railway.json`/`railway.toml` deprecated, unavailable to new services, unread after 2026-12-01.

For each useful finding, record the fact, scope, source file or document, and
last-verified date. Label hypotheses clearly. Reverify commands and behavior
after relevant code or environment changes. Move detailed topic notes into
separate files when this document grows, and add them to project.md's "When to read" list.
