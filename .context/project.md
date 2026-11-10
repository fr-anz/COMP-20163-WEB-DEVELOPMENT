# COMP-20163-WEB-DEVELOPMENT

Purpose/completion criteria: build **Habi** — educational PHP handbag storefront with
craft-first discovery, 3D product viewer (rotate + peek inside), real SQLite demo inventory,
simulated checkout and admin panel; deploy to Railway/Render via Docker. Completion =
live URL + all PRD R#/N# acceptance criteria verified. M0–M7 are implemented locally; current verification/DoD status is in state.md and docs/BUILD.md.
Constraints/conventions: PHP 8.2+ required (course), Composer allowed, PSR-12, no real
payments, no secrets in repo; follow .context/rules.md; docs pipeline in docs/ (PRD owns R# IDs).
Structure: PHP/Slim front controller in public/, PSR-4 src/, Twig views/, migrations/, seed/, tests/.
Planning docs in docs/; PRD owns R# IDs, DSD owns visual/asset policy and implemented shell APIs.
Commands: `composer install`; copy/configure `.env` per README; `php -S localhost:8000 -t public public/index.php`
(router arg required on PHP 8.2); `vendor/bin/phpunit`; `composer cs`; `composer lint`; `docker build -t habi .`.
First request initializes SQLite. `php seed/seed.php --force` wipes demo data. M0–M7 implemented; M8 adds Dockerfile, render.yaml, CI; Railway uses dashboard settings (README Deploy).

## When to read

- Writing a checkpoint or other context: [protocol](protocol.md)
- Revisiting a consequential choice: [decisions](decisions.md)
- Citing past findings or checks: [knowledge](knowledge.md)
- Requirements/design/build details: docs/PRD.md, docs/SDD.md, docs/DSD.md, docs/BUILD.md, docs/USER-JOURNEY.md
