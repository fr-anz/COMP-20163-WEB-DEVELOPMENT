# State

Updated: 2026-11-10.
Objective: issue #9 M8 deploy & CI — merged via PR #19 (`8a1349a`); live-evidence PR on `ad/m8-live-evidence`.
Progress: live at https://habi.theavenir.dev (GCE e2-micro us-west1-b, 136.118.96.127, Caddy + Let's Encrypt); GitHub deploy job works (run 42813150250); stock 13 survived reboot.
Checks: main CI green incl. deploy; PHPUnit 118/953; container + Debian rehearsal + live HTTPS/route checks (BUILD M8).
Evidence: BUILD M8 "Live deployment".
Blocker/next: §2.5 cross-track review not recorded (PR #19 merged with 0 reviews); real-device pass not run. IPv4 ≈ $3.65/mo against $10 credit — budget alert advised.
