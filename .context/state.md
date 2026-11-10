# State

Updated: 2026-11-10.
Objective: issue #9 M8 deploy & CI on `ad/m8-deploy` (from `main` after PR #18 merge).
Progress: Dockerfile/.dockerignore, render.yaml, CI workflow, router-script start command (fixes PHP 8.2 `/uploads/*.png` 404), stale product copy removed, README Deploy/architecture, SDD §12, PRD §9, BUILD M8 evidence; D5 recorded.
Checks: PHPUnit 118/953 (8.2 + 8.5), PSR-12, lint, actionlint; container: health, 3 cards, 8-way race → 1 order, persistence across restart/recreate/rebuild, a11y 100 ×4.
Evidence: BUILD M8; `/tmp/habi-m8/`.
Blocker/next: PR #19 open, CI green on PR (run 42804495037); needs cross-track review/merge. Owner creates Railway service (volume `/app/data`, secrets, Wait for CI) → verify live URL + restart/redeploy persistence; then CI green on main.
