# Decision history

## D1 — 2026-11-05 — accepted by user (via Q&A) — E-commerce platform decisions for Habi (then unnamed)
- **Decision:** PHP 8.2+ backend (course-required) with Composer, Slim 4 + Twig 3, PDO **SQLite** (WAL) as DB, server-rendered pages + vanilla JS ES modules, vendored Three.js for procedural 3D bags, Docker deploy to **Railway** (Render documented as alternative), SQLite on a mounted volume.
- **Reason:** PHP is mandated by COMP-20163; SQLite keeps dev zero-setup (`php -S`) while remaining portable to MySQL later; procedural 3D reduces dependence on third-party model files (asset rights still require review); Railway volume solves DB persistence for the "deploy to web" requirement.
- **Alternatives considered:** vanilla PHP without framework (rejected: more boilerplate for routing/CSRF), MySQL from day one (rejected: setup friction), GLB model downloads (rejected: licensing + no real "inside" view), client-side-only cart (rejected: weak inventory story).
- **Consequences:** SDD isolates SQLite-vs-MySQL differences in `OrderService`; the formerly open brand name is resolved by D4.
- **Source:** docs/PRD.md, docs/SDD.md, docs/BUILD.md (all 2026-11-05).

## D2 — 2026-11-05 — accepted by user — Commerce scope
- **Decision:** Guest checkout + admin login (no customer accounts); **simulated** card payment with Luhn test cards (`4242…` approve, `4000…0002` decline, `4000…9995` insufficient funds), card numbers never stored; inventory decrements atomically on simulated success; ~10 seeded products; admin CRUD/stock/orders.
- **Reason:** Covers full checkout experience + inventory system without real-payment/PII scope; atomic decrement prevents oversell.
- **Source:** docs/PRD.md R7–R23, N3–N4; docs/SDD.md §7–§9; docs/USER-JOURNEY.md J1–J3.

## D3 — 2026-11-05 — accepted by user — Design direction & team shape
- **Decision:** **Artisan-luxury Filipino** design direction (terracotta/sand/moss/gold, Fraunces + Inter); 4-person team with tracks A backend / B 3D / C storefront UI / D admin+DevOps; docs live in `docs/` as 5 markdown files; build phased by feature milestones M0–M8, no fixed deadline.
- **Status:** visual direction superseded by D4 on 2026-11-09. Team shape, documentation layout and milestones remain current.
- **Source:** docs/DSD.md, docs/BUILD.md.

## D4 — 2026-11-09 — accepted by user — Habi identity, discovery & reference use
- **Decision:** adopt **Habi**, a contemporary Filipino craft direction. Canonical design and interview choices are in docs/DSD.md §§1–12; this replaces D3's visual direction, not the technical stack or simulated commerce/admin scope.
- **Reason/source:** user approved the direction brief after three interview rounds; wants handmade, contemporary, distinctly Filipino identity rather than generic e-commerce/editorial branding.
- **Asset boundary:** supplied images are inspiration and procedural-3D modeling references for the educational demo, not authorized publication assets or proof of product/maker ownership. DSD §9 governs generated/authorized website assets.
- **Consequences:** reconcile all five planning docs; brand naming is complete. Exact fonts/colors and original publishable assets remain implementation choices. No application implementation was requested or performed.

## D5 — 2026-11-10 — accepted by user (via Q&A) — M8 deployment configuration
- **Decision:** (1) start the built-in server with `public/index.php` as router (`php -S … -t public public/index.php`) in dev and the image; (2) configure Railway through one-time service settings documented in README, committing no `railway.json`; Render via `render.yaml`. **Part (2) superseded by D6.**
- **Reason:** PHP 8.2's server 404s dotted paths (`/uploads/<hex>.png`) without a router (php-src #12604); Railway Config as Code is deprecated, unavailable to new services and unread after 2026-12-01, while IaC would add npm plus a token.
- **Alternatives rejected:** extension-free upload URLs (data/template migration); `.railway/railway.ts` IaC.
- **Source:** issue #9; docs/SDD.md §12; README Deploy.

## D6 — 2026-11-10 — accepted by user (via Q&A) — Google Cloud e2-micro replaces Railway
- **Decision:** production runs on one Compute Engine `e2-micro` (Debian 13, `pd-standard`, static IPv4) using `deploy/gce/` (Compose: app + Caddy HTTPS for the owner's own domain, data in `/srv/habi/data`). GitHub Actions deploys `main` after CI over SSH; the key's forced command is `update.sh`, accepting only a SHA on `main`. Render stays the alternative.
- **Reason:** user chose the Always Free VM and own domain. External IPv4 is billed (~$3.65/month); the user's $10 credit covers it for about 2.7 months.
- **Alternatives rejected:** IPv6-only + Cloudflare ($0 but fragile, GitHub lacks IPv6); manual-only updates.
- **Source:** issue #9; README Deploy; docs/SDD.md §12.

## D7 — 2026-11-10 — requested by user — Cinematic discovery story; real-store copy
- **Decision:** home becomes a sequential scroll story (opening strands → sticky weave build → four material scenes → featured bag → dark finale into the catalog). Site-wide: no eyebrows or filler text beside/under headings; no educational/demo/fictional/simulated notices (footer, checkout banners, admin notice, seed copy).
- **Reason:** user found the 1:1 reference UI "slop" and wants it to read as a real e-commerce site.
- **Consequences:** supersedes the preferences.md/DSD 1:1 rule where they conflict (reference still governs tokens/components/inner screens); PRD R1/R13/N3 and DSD updated; payment remains simulated server-side, test cards stay in README only. Existing databases keep old seed copy until `php seed/seed.php --force`.
- **Source:** user request 2026-11-10; docs/DSD.md §1, §4.

## D8 — 2026-11-10 — requested by user — One bag per category; detailed 3D models
- **Decision:** seed catalog trimmed to 4 bags, one per category (Sinag Tote, Lupa Satchel, Tala Sling, Maya Clutch); the six other bags and their SVGs removed. PRD N7 changed from 10 to 4. Procedural 3D bags upgraded for detail/quality.
- **Consequences:** pagination tests insert fixture rows instead of relying on seed size. Existing databases keep old rows until `php seed/seed.php --force`.
- **Source:** user request 2026-11-10.
