# COMP-20163-WEB-DEVELOPMENT

Purpose/completion criteria: build **Habi** — educational PHP handbag storefront with
craft-first discovery, 3D product viewer (rotate + peek inside), real SQLite demo inventory,
simulated checkout and admin panel; deploy to Railway/Render via Docker. Completion =
live URL + all PRD R#/N# acceptance criteria verified. No app exists yet.
Constraints/conventions: PHP 8.2+ required (course), Composer allowed, PSR-12, no real
payments, no secrets in repo; follow .context/rules.md; docs pipeline in docs/ (PRD owns R# IDs).
Structure: five planning docs in docs/ (PRD.md, SDD.md, DSD.md, BUILD.md, USER-JOURNEY.md).
DSD owns approved visual direction and reference-only asset policy (§9); decisions.md D4
records approval. BUILD M0 defines the planned app layout.
Commands: planned, not yet app-verified — `composer install`, `cp .env.example .env`,
`php -S localhost:8000 -t public`, `vendor/bin/phpunit` (BUILD §3); reseeding wipes demo data.

## When to read

- Writing a checkpoint or other context: [protocol](protocol.md)
- Revisiting a consequential choice: [decisions](decisions.md)
- Citing past findings or checks: [knowledge](knowledge.md)
- Requirements/design/build details: docs/PRD.md, docs/SDD.md, docs/DSD.md, docs/BUILD.md, docs/USER-JOURNEY.md
