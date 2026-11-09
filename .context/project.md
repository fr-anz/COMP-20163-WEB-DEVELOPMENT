# COMP-20163-WEB-DEVELOPMENT

Purpose/completion criteria: build **Habi** — educational PHP handbag storefront with
craft-first discovery, 3D product viewer (rotate + peek inside), real SQLite demo inventory,
simulated checkout and admin panel; deploy to Railway/Render via Docker. Completion =
live URL + all PRD R#/N# acceptance criteria verified. M0 shell/data foundation is implemented locally.
Constraints/conventions: PHP 8.2+ required (course), Composer allowed, PSR-12, no real
payments, no secrets in repo; follow .context/rules.md; docs pipeline in docs/ (PRD owns R# IDs).
Structure: PHP/Slim front controller in public/, PSR-4 src/, Twig views/, migrations/, seed/, tests/.
Planning docs in docs/; PRD owns R# IDs, DSD owns visual/asset policy and implemented shell APIs.
Commands: `composer install`; copy/configure `.env` per README; `php -S localhost:8000 -t public`;
`vendor/bin/phpunit`; `composer cs`; `composer lint`. First request initializes SQLite.
`php seed/seed.php --force` wipes demo data. Later commerce/viewer/admin/deploy milestones remain unimplemented.

## When to read

- Writing a checkpoint or other context: [protocol](protocol.md)
- Revisiting a consequential choice: [decisions](decisions.md)
- Citing past findings or checks: [knowledge](knowledge.md)
- Requirements/design/build details: docs/PRD.md, docs/SDD.md, docs/DSD.md, docs/BUILD.md, docs/USER-JOURNEY.md
