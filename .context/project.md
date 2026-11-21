# COMP-20163-WEB-DEVELOPMENT

Purpose/completion criteria: build **Habi** — heritage-driven PHP e-commerce prototype (Group 1, 5 members)
selling sample native-weaver bags as museum-style artifacts: 3D viewer, weavers, journal, simulated COD/transfer/card
checkout, tracking, EN/FIL, weaver + admin modules; Docker on Google Cloud e2-micro (Render alt).
Completion = live habi.theavenir.dev + PRD 2.0 R#/N# verified + evaluation (M15). M0–M8 implemented;
M9–M15 planned (D9); status in state.md and docs/BUILD.md.
Constraints/conventions: PHP 8.2+ required (course), Composer allowed, PSR-12, no real
payments, no secrets in repo; follow .context/rules.md; docs pipeline in docs/ (PRD owns R# IDs).
Structure: PHP/Slim front controller in public/, PSR-4 src/, Twig views/, migrations/, seed/, tests/.
Planning docs in docs/; PRD owns R# IDs, DSD owns visual/asset policy and implemented shell APIs.
Commands: `composer install`; copy/configure `.env` per README; `php -S localhost:8000 -t public public/index.php`
(router arg required on PHP 8.2); `vendor/bin/phpunit`; `composer cs`; `composer lint`; `docker build -t habi .`.
First request initializes SQLite. `php seed/seed.php --force` wipes demo data. M0–M7 implemented; M8 adds Dockerfile, CI, deploy/gce (README Deploy), render.yaml.

## When to read

- Writing a checkpoint or other context: [protocol](protocol.md)
- Revisiting a consequential choice: [decisions](decisions.md)
- Citing past findings or checks: [knowledge](knowledge.md)
- Requirements/design/build details: docs/PRD.md, docs/SDD.md, docs/DSD.md, docs/BUILD.md, docs/USER-JOURNEY.md
