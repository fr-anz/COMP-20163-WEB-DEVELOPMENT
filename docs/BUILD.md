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
  `composer install && php -S localhost:8000 -t public` → home page renders "hello".
- **Done when:** fresh clone → 3 commands → site boots, DB seeded, Lighthouse a11y ≥ 90 on shell. Cites N6, N7, N8. Shell only; does not promise complete intro.

### M1 — Catalog backend + listing (A + C)
- A: `ProductService` (list w/ q/category/sort/page, getBySlug), `StorefrontController` routes,
  JSON stock endpoint.
- C: craft home (R1), listing page w/ search/filter/sort/pagination (R2), product card component, empty state.
- **Done when:** R1, R2 work end-to-end; 10 seeded products browsable/filterable; PRD acceptance green. **M1 acceptance:** short skippable intro + free exploration + optional commerce visible (DSD §7).

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

CLI/API planned; no app currently.

```bash
composer install                 # deps
cp .env.example .env             # fill SESSION_SECRET etc.
php -S localhost:8000 -t public   # run (auto-migrates + seeds on first boot)
php seed/seed.php                # re-seed (wipes data) — **Destructive: do not execute in production**
vendor/bin/phpunit               # tests
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
