# State

Updated: 2026-11-10.
Objective: issue #8 M7 polish on `abcd/m7-polish` (from `main`; M6 merged via PR #17).
Progress: SEO head blocks/OG/canonical/favicon.ico, Habi 404/405/500 pages, coarse-pointer 44 px targets, listing LCP (gzip + eager images), display_errors off (fixed 500→200 + path leak), audits recorded in BUILD M7.
Checks: PHPUnit 118/953, PSR-12, lint pass; Lighthouse 4 pages a11y 100, perf 82–100; N1 LCP ≤2.21 s, viewer 0.975 s after load; keyboard-only 360 touch/reduced-motion journey pass; no overflow 39 screens.
Evidence: BUILD M7; `/tmp/habi-m7/`.
Blocker/next: PR #18 open (commit 99cffec); human cross-track review/merge; real-device pass not run. M6 PR #17 merged without review; M6 human timing open.
