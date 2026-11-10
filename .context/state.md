# State

Updated: 2026-11-10.
Objective: issue #9 M8 deploy & CI on `ad/m8-deploy` (from `main` after PR #18 merge).
Progress: M8 image/CI/router fix done; Google Cloud e2-micro stack `deploy/gce/` (Caddy, setup/update, SSH deploy job) replaces Railway (D6); docs updated.
Checks: PHPUnit 118/953 (8.2 + 8.5), PSR-12, lint, actionlint; container: health, 3 cards, 8-way race → 1 order, persistence across restart/recreate/rebuild, a11y 100 ×4.
Evidence: BUILD M8; `/tmp/habi-m8/`.
Blocker/next: PR #19 open; owner runs README Google Cloud runbook (VM, DNS A record, deploy key, `gh` secret/vars) → verify live HTTPS + reboot/redeploy persistence; review/merge → CI + deploy green on main.
