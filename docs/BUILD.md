# BUILD — Habi Build Plan

| | |
|---|---|
| Version | 1.1 · 2026-11-09 |
| Basis | [PRD.md](PRD.md) R#/N# · [SDD.md](SDD.md) architecture · [DSD.md](DSD.md) components · [USER-JOURNEY.md](USER-JOURNEY.md) flows |
| Team | **4 people**, feature-phased milestones (no fixed deadline) |
| Stack | PHP 8.2+, Composer, Slim 4, Twig 3, PDO SQLite, vendored Three.js, Docker → Railway/Render |

---

## 0. Workstreams (4 tracks)

| Track | Owner focus | Owns |
|---|---|---|
| **A — Backend & Data** | PHP/Slim/PDO, models, migrations, seed, cart/checkout/order services, inventory transactions, payment simulator, auth, middleware | `src/` (all), `migrations/`, `seed/`, `tests/` |
| **B — 3D Viewer** | Three.js procedural bag factory, orbit + peek-inside, per-product configs, fallback | `public/assets/js/viewer3d.js`, `bagFactory.js`; proposes `three_config` seed values (R4–R6, R27) — A integrates |
| **C — Storefront UI** | DSD implementation, all storefront Twig views, cart drawer, checkout UX, responsive + a11y | `public/assets/css/app.css` (shared admin tokens), `views/storefront/`, `public/assets/js/cart.js`, `checkout.js` (R1–R3, R7–R17, R24–R26) |
| **D — Admin & DevOps** | Admin panel UI, Docker, Railway/Render deploy, CI, README | `views/admin/`, `Dockerfile`, `railway.json`/`render.yaml`, `.github/workflows/` (R18–R23, N2, N6) |

Integration points: A+B meet at `GET /api/product/{slug}/3d`; A+C meet at cart/checkout
endpoints; C+D meet at admin templates; all meet at DSD tokens in `app.css`.
Sync: one shared `main` branch; feature branches integrate to main; small PRs, daily 15-min standup;
milestone = all track tasks done + acceptance criteria green.

## 1. Milestones

### M0 — Foundation & local dev (all tracks, ~2 days)
- A: `composer.json` (slim/slim, **nyholm/psr7**, twig/twig, phpunit), PSR-4 autoload, `public/index.php`
  front controller, `Support/db()` + `env()`, `migrations/001_schema.sql` (SDD §5), boot-migrate-if-missing.
- A: `seed/seed.php` → 10 products across ≥4 categories w/ prices in centavos, images placeholders, `three_config` JSON (N7), 1 admin from env.
- C: DSD tokens → `app.css` (colors, type, spacing, radius, shadows, motion), fonts, base layout,
  header/footer, icon SVG set (DSD §2–§6).
- D: `.env.example`, `.gitignore` (vendor, data/*.sqlite, .env), README quickstart:
  `composer install`, copy/configure `.env`, then `php -S localhost:8000 -t public` → the Habi shell renders.
- **Done when:** fresh clone → 3 commands → site boots, DB seeded, Lighthouse a11y ≥ 90 on shell. Cites N6, N7, N8. Shell only; does not promise complete intro.

M0 local runtime verified 2026-11-09: fresh SQLite initialization, 10 products/four categories,
env-configured administrator, preserved inventory across restart, and 360 px/wide keyboard shell.
Lighthouse accessibility: **100**. Evidence: local run
`/home/franz/.omp/evidence/habi-m0-20261109-56854d2e/verify/report.md`.
PHPUnit/PSR-12 final repair checks are tracked in the run's `verify-final/` report.
Human PR review/merge remains pending; Docker/hosted N6 checks remain M8.

### M1 — Catalog backend + listing (A + C)
- A: `ProductService` (list w/ q/category/sort/page, getBySlug), `StorefrontController` routes,
  JSON stock endpoint.
- C: craft home (R1), listing page w/ search/filter/sort/pagination (R2), product card component, empty state.
- **Done when:** R1, R2 work end-to-end; 10 seeded products browsable/filterable; PRD acceptance green. **M1 acceptance:** short skippable intro + free exploration + optional commerce visible (DSD §7).

M1 local implementation (issue #2, branch `a/m1-catalog-discovery`):
- `ProductService`/`StorefrontController` implement active-only listing, literal search,
  category, deterministic price/newest sorting, six-per-page pagination, slug lookup and stock JSON.
- `/product/{slug}` offers read-only seed-data inspection for discovery navigation;
  full R3 controls/viewer and cart behavior remain M2/M4.
- Essential PHP checks, 2026-11-09: `vendor/bin/phpunit tests/Services/ProductServiceTest.php`
  → **5 tests / 32 assertions**; `vendor/bin/phpunit tests/Bootstrap`
  → **7 tests / 239 assertions**; `composer cs && composer lint` → pass.
- Actual stock API smoke: Sinag → 200 JSON (stock 12, threshold 3, `no-store`);
  missing slug → 404 JSON. Runtime uses an isolated temporary SQLite database, not local inventory.
- Browser R1/R2 acceptance, 2026-11-09, Chromium at **1280×900 / 360×800**:
  - Tab/Enter skip-to-main focuses main; Skip introduction lands at exploration before
    any shopping. Enter/Space toggles material studies; touch opens the colorful study.
  - All 10 bags browsed across two pages. Price asc/desc and newest verified across
    both pages; `q=canvas&category=Tote&sort=price_desc` finds Alon and retains controls.
    `q=demo&sort=price_asc` survives Next; `q=no-such-bag` shows correction/reset.
  - Isolated fixture stock 0 remains visible as Sold out on listing/detail; existing
    low-stock Gabi shows Only 3 left. No-active-product fixture shows an empty catalog
    with a working return to material exploration; inactive stock lookup returns 404.
    Fixture restored afterward. Missing detail gives a public 404; live stock tracks DB changes.
  - No horizontal overflow on home, catalog or read-only detail at 360 px.
    Relevant navigation/disclosure/form/product-name targets ≥44 px; visible keyboard focus.
    Local fonts and seeded images load. No motion or JavaScript prerequisite introduced.
  - Actual contrast: secondary text/control boundary **7.84:1**, action/low stock **5.68:1**,
    focus on page **8.20:1**, footer notice **9.54:1**.
- Local screenshot evidence: wide expanded exploration
  `/tmp/omp-sshots-15c76039b694af21.webp`; narrow opening
  `/tmp/omp-sshots-15c76039da4ac206.webp`. Consumer checks used the isolated dev server
  `php -S 127.0.0.1:8012 -t public` with temporary DB/admin environment, never real inventory.
- `npx --no-install contextspine check` → **0 failures, 31 warnings** for the existing
  missing IDEA/RESEARCH/QAD pipeline documents and QAD requirement coverage. No new pipeline scope added.
- Human cross-track PR review and merge remain pending; no commit/push/deploy performed.


### M2 — Product detail + 3D viewer MVP (A + B + C)
- A: product detail route (R3), `GET /api/product/{slug}/3d` (R6).
- B: `BagViewer` class, `bagFactory` for 4 shapes, OrbitControls w/ damping + idle auto-rotate (R4),
  vendored three, WebGL fallback image (R27), loading state (DSD §8). Exterior viewer only.
- C: detail page layout, gallery, qty stepper + Add-to-cart button (visual, disabled until M4), accordions, stock badge (R17 partial).
- **Done when:** R3, R4, R6, R27 pass on desktop + mobile (viewer overlay); LCP < 3 s (N1). **Keyboard:** viewer buttons focusable. **Reduced-motion:** respects `prefers-reduced-motion` (DSD §10).

### M3 — Peek inside (B, with C polish)
- B: interior group (lining, pockets) per shape, lid-open tween, camera fly-in, clipping plane
  cutaway, "Peek inside" toggle button (R5, DSD §8).
- C: toggle button styling, hint chips, reduced-motion handling.
- **Done when:** R5 passes; toggle reversible; no z-fighting; 60 fps on mid laptop.

### M4 — Cart (A + C)
- A: session cart service w/ stock clamping (R9, R28), POST add/update, cart JSON for badge.
- C: cart drawer + `/cart` page (R8), toasts on add (R7), badge count, qty steppers, remove, subtotal.
- **Done when:** R7–R9, R28 pass; stock-cap correction notice works; cart survives reload.

### M5 — Checkout, simulated payment, orders, inventory (A lead, C UI, D middleware)
- A: checkout step state machine (SDD §7), shipping validation (R11), region shipping fees (R12),
  `PaymentSimulator` (R13–R14, Luhn + test cards), `OrderService::placeOrder` atomic w/ idempotency +
  rollback (R15, SDD §8), confirmation (R16), out-of-stock at place-time handling (R17).
- D: CSRF middleware on all POSTs, session hardening, admin auth skeleton (R19) so M6 is easy.
- C: stepper, shipping form, mock card form + processing spinner + result screens, review step,
  confirmation page w/ simulation banner (N3), order page.
- **Done when:** approved card → paid order created, declined card → no order; zero oversell; R10–R17 green.

### M6 — Admin panel (D + A + C)
- A: admin login (R19: password_hash, throttle, generic errors), product CRUD service (R20),
  stock adjust + audit (R18, R21), orders list/status/cancel-restores-stock (R22), image upload (R23).
- D/C: admin layout + pages (dashboard w/ low-stock alerts R18, products table/form, orders table/detail, audit log).
- **Done when:** R18–R23 pass; non-admin gets redirected from `/admin/*`; CSRF enforced.

### M7 — Polish: responsive, a11y, SEO, content (all)
- C: 360 px pass (R24), keyboard-only checkout test (R25), focus rings, aria-live toasts,
  reduced-motion, per-page meta/OG/favicon (R26), final seed content (names, descriptions, materials/origin).
- A: `stock` API edge cases, order number format, error pages (404/500) in DSD style.
- **Done when:** Lighthouse (perf ≥ 80, a11y ≥ 90) on home/listing/product/checkout; R24–R26 green. **Skip:** all pages skip-to-content. **Keyboard:** full checkout without mouse (R25). **Reduced-motion:** all animations respect preference.

### M8 — Deploy & CI (D lead)
- D: `Dockerfile` (php:8.2-cli, composer install --no-dev, `php -S 0.0.0.0:$PORT -t public`),
  `.dockerignore`, `railway.json` + volume at `/app/data` (SQLite persists), Render `render.yaml` alt,
  GitHub Actions CI (composer validate, php -l, PHPUnit, docker build), auto-deploy on main.
- A: env-driven DSN (`DB_DSN`), boot migration on empty volume, admin seeded from env secrets.
- All: README final (run, deploy, test cards, architecture diagram), PRD §9 items resolved or scheduled.
- **Done when:** live HTTPS URL on Railway; restart container → data persists (N6); CI green on main.

## 2. Definition of Done (per milestone)

1. All cited R#s manually verified against PRD acceptance criteria.
2. Targeted behavior boundary tests added for new service logic; `php -l` clean; PSR-12. No permanent tests for every trivial change.
3. No secrets in repo; `.env.example` updated if env vars added.
4. UI matches DSD tokens (spot-check colors/radius/motion); responsive at 360 px.
5. PR reviewed by at least one other track owner; merged to `main`.

## 3. Local Dev Commands

M0 setup contract (see [README](../README.md) for requirements and password-hash configuration):

```bash
composer install                 # pinned dependencies
cp .env.example .env             # configure ADMIN_SEED_EMAIL/PASSWORD_HASH before first boot
php -S localhost:8000 -t public   # run (auto-migrates + seeds on first request)
php seed/seed.php --force        # destructive reset; never run against data you need
vendor/bin/phpunit               # isolated first-boot/preservation tests
```
## 4. Git Workflow

- `main` protected; feature branches `track-milestone-short` (e.g. `a/m5-checkout`); PRs with
  milestone checklist; squash-merge. Commit attribution per repo convention.
- Milestone tag: `git tag M5` when M5 done.

## 5. Risk Register

| Risk | Mitigation |
|---|---|
| SQLite write contention under concurrent checkouts | `BEGIN IMMEDIATE` + guarded UPDATE + concurrency test in M5 (SDD §8); MySQL migration path documented (SDD §14) |
| WebGL unsupported on some grader machine | R27 fallback image; document in README |
| Railway/Render free tier sleeps | Note cold-start in README; paid tier optional |
| Asset/font selection not finalized | Select licensed fonts, original generated imagery/textures and procedural model configs before their implementation milestone (DSD §§9,12); reference images are not publication assets |
| Modeling constraints for procedural bags | Ensure procedural weave/textile materials match DSD §§8–9; validate performance on mid-tier devices; procedural configs and generated textures, no generated model download |
| Three.js vendored file goes stale | Pin version in README; upgrade = replace 2 files in `public/assets/vendor/three/` |
| Scope creep (accounts, reviews) | PRD §4 out-of-scope; park in backlog |
