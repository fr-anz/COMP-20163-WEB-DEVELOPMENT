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
- **Done when:** R1/R2 work end-to-end, ten seeded products remain browsable/filterable, and the current published UI contract passes (DSD §4).

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
  vendored three, WebGL fallback image (R27), loading state (DSD §4). Exterior viewer only.
- C: exact published detail/gallery layout, disabled purchase until M4, open information sections and stock chip (R17 display only).
- **Done when:** R3 inspection portion, R4, R6 and R27 pass on desktop + mobile (viewer dialog); LCP <3 s and viewer initialization <2 s after page load (N1). Controls work without drag and respect reduced motion. Full R3/R7 purchase acceptance is M4; Peek inside is M3.

M2 local implementation (issue #3, branch `abc/m2-product-viewer`, 2026-11-09):
- `npx --no-install contextspine check` → **0 failures / 31 existing pipeline warnings**
  (missing IDEA/RESEARCH/QAD and QAD requirement coverage); no unrelated pipeline scope added.
- [x] Detail + active-only config API; ordered image gallery, dimensions and illustrative material/origin labels.
- [x] Pinned local Three.js 0.160.1 + matching OrbitControls/MIT license; original tote/satchel/sling/clutch exteriors and generated material textures.
- [x] Pointer/touch orbit, keyboard rotation/tilt/reset, idle rotation with pause/resume and live reduced-motion behavior.
- [x] Photos/3D switching, stock display and accordions. Quantity/Add to bag/Sold out controls are visibly/native-disabled; no cart mutation, Peek inside or full R3/R7 claim.
- [x] Loading, ten-second failure deadline, fetch failure/retry, and original-image WebGL fallback.
- [x] Desktop inline inspection and 360 px native fullscreen dialog, Close/Esc, explicit Tab wrapping and focus return.

Commands and observed checks:
- `vendor/bin/phpunit tests/Services/ProductServiceTest.php` → **7 tests / 39 assertions**.
  New boundary coverage: sold-out config stays inspectable, inactive/missing configs disappear,
  and gallery primary/sort ordering does not mix products.
- `composer cs && composer lint` → pass.
- `node --check public/assets/js/product.js && node --check public/assets/js/viewer3d.js && node --check public/assets/js/bagFactory.js` → pass.
- `vendor/bin/phpcs --standard=PSR12 public/assets/vendor/three/three.module.php && php -l public/assets/vendor/three/three.module.php` → pass.
- Actual HTTP: all ten slugs return their own shape/material/dimensions config;
  200 JSON + `no-store`, sold-out still 200, missing/inactive JSON 404 and inactive detail 404.
- Isolated server: `env DB_DSN=sqlite:/tmp/habi-m2-RGCkSD/app.sqlite ADMIN_SEED_EMAIL=viewer@example.test ADMIN_SEED_PASSWORD_HASH='<isolated fixture hash>' php -S 127.0.0.1:8013 -t public`.
  Real `.env` and local inventory were not changed.

Consumer scenarios (Chromium 150, **1280×900 and 360×800**):
- All ten product pages: one canvas, correct per-product exterior, inline desktop/modal mobile,
  no horizontal overflow; all six viewer controls ≥44 px tall. Representative screenshots:
  tote desktop `/tmp/omp-sshots-15c76809771b5fd5.webp`, mobile
  `/tmp/omp-sshots-15c76871cf5b5fd6.webp`; mobile satchel/sling/clutch
  `/tmp/omp-sshots-15c76965d21b5fda.webp`, `/tmp/omp-sshots-15c76967449b5fdb.webp`,
  `/tmp/omp-sshots-15c76969219b5fdc.webp`.
- Actual mouse drag and CDP touch gesture changed the rendered canvas. Enter activates rotation
  without drag; Reset restored the initial image. Under reduced motion, manual rotation/reset
  still work and automatic rotation is disabled with explanatory text. Pause kept the canvas
  unchanged past the idle interval; Resume changed it again.
- Modal Close/Esc returns focus to 3D view; Tab/Shift+Tab wrap at first/last enabled control.
  Resizing between inline/modal keeps a single renderer. Back navigation reconstructs GPU
  state after bfcache restoration (the initially observed empty canvas was repaired).
- Held config request shows loading; a 503 shows persistent failure + Try again; retry renders
  successfully on both layouts. A held request reaches failure instead of loading indefinitely.
  WebGL contexts disabled via browser fixture: original illustration + exact
  “3D unavailable in this browser” on desktop and mobile; no canvas.
  Fallback captures: `/tmp/omp-sshots-15c768ecac80ff87.webp`,
  `/tmp/omp-sshots-15c768eccf00ff88.webp`.
- No-JS 360 px: image/details readable, enhancement controls hidden, explicit 3D prerequisite
  note, purchase controls disabled and no overflow.
- Isolated additional-image/stock-0 fixture: named second thumbnail changes image/pressed state;
  Sold out button remains disabled. Native accordion Enter/Space reveals unverified origin and
  38×34×14 cm dimensions; visible keyboard focus. Gabi displays Only 3 left. Fixtures restored.
- Measured foreground/background: control text **7.54:1**, status text/control boundary
  **7.84:1**, focus/page **8.20:1**. Disabled purchase controls all have muted 0.55 opacity.
  No JavaScript errors were observed on the twenty ordinary product/layout visits.

N1 performance (cold cache, CDP applied 150 ms latency, **1.6 Mbps down / 750 Kbps up**,
4× CPU slowdown; first synchronous render marked `habi-viewer-ready`):

| Viewport | LCP | Page load | Viewer ready from navigation | Ready after load |
|---|---|---|---|---|
| 1280×900 | 0.696 s | 1.337 s | 2.590 s | **1.253 s** |
| 360×800 | 1.000 s | 1.645 s | 2.894 s | **1.249 s** |

The original uncompressed Three.js transfer took 4.083 s and missed readiness at
3.853 s after load. `three.module.php?v=0.160.1` now uses PHP zlib negotiation:
transferred module bytes fell from 670,981 to 167,187. Both N1 targets pass locally.
Separate targeted Lighthouse **13.6.0** mobile LCP: **0.740 s**, no runtime error:

```sh
npx --no-install lighthouse http://127.0.0.1:8013/product/sinag-tote --port=33145 \
  --only-audits=largest-contentful-paint --throttling-method=devtools \
  --throttling.requestLatencyMs=150 --throttling.downloadThroughputKbps=1600 \
  --throttling.uploadThroughputKbps=750 --throttling.cpuSlowdownMultiplier=4 \
  --screenEmulation.width=360 --screenEmulation.height=800 \
  --screenEmulation.deviceScaleFactor=1 --output=json \
  --output-path=/tmp/habi-m2-RGCkSD/lighthouse.json --quiet
```

Raw output: `/tmp/habi-m2-RGCkSD/lighthouse.json`. The port points to this run's managed
Chromium; use the active CDP port when repeating. This is an LCP-only check, not an
unrun whole-site performance/accessibility certification.

Verification follow-up (user-requested, 2026-11-09):
- Found and repaired a modal focus escape: keyboard activation of Try again removed
  the focused status button and left focus on `body`. Status replacement now moves
  focus before detaching the button. Rechecked: mobile retry → Close (inside dialog);
  desktop retry → 3D view; both recover one working canvas.
- Found and repaired Reset view retaining drag inertia. It now clears OrbitControls'
  pending damping before restoring the camera. After an actual drag followed immediately
  by keyboard Reset, canvas captures match the initial view at both 1280 and 360 px.
  Manual rotation/reset also still work under reduced motion.
- Fresh assertions passed for modal Tab/Shift+Tab containment/Esc focus return,
  config 200/no-store and missing 404; ordinary consumer checks reported no JS errors.
- Re-ran the targeted PHP service tests (**7 tests / 39 assertions**), `composer cs`,
  `composer lint`, wrapper PSR-12, and `node --check` on both repaired modules: pass.
- Repeated the cold 4G/4× CPU measurements after these repairs; the table above contains
  this latest run. Earlier Lighthouse output remains the separate LCP-only artifact.
  Updated mobile capture: `/tmp/omp-sshots-15c76cb706ab471d.webp`.


Shared DoD status: local requirements/UI/PHP checks, relevant docs and asset licenses
are covered; no new environment variables or secrets were added.
**Still pending:** another track owner's PR review and merge to `main` (BUILD §2.5).
No commit, push, merge or deployment was performed; the issue is not fully Done until
that external requirement is met.


### Design reconciliation — approved reference (2026-11-09)

> **Historical, superseded visual interpretation.** The checks below describe that earlier
> revision. The user subsequently required a 1:1 published-reference cutover; they do not
> establish acceptance of the current visual direction.

The user approved the [published Habi UI and design system](https://fit-igloo-66636772.figma.site/)
on 2026-11-09. Started from `main` after `git pull --ff-only`, then created
`abc/design-reconciliation`. The prior local context checkpoint is preserved in the
named stash `preserve pre-reconciliation context checkpoint`.

- Reconciled DSD/PRD/SDD/USER-JOURNEY/README to the approved reference and actual PHP/Twig
  APIs. PRD §9 resolves the prototype's conflicting sample fees, order numbers and stock
  presentation without changing business requirements.
- Updated canonical `app.css`: approved colors, quiet commerce typography, 1280 px
  content area, 4/3/2 catalog columns, readable disabled/status states and real focus outlines.
  Updated shell/wordmark/search, split craft hero/process, original woven artwork,
  material disclosures, cards, product breadcrumbs and the viewer's cream studio surface.
- Kept existing inventory, `.env`, URLs, GET filtering/pagination, local fonts/licenses,
  procedural models and viewer contracts. No M3–M6 behavior was implemented.

Observed checks on `php -S 127.0.0.1:8015 -t public`, Chromium, 2026-11-09:
- Desktop **1440×1000**, tablet **768×900**, narrow **360×800**: catalog has 4/3/2
  columns. Home, catalog and product checks showed no horizontal overflow; narrow catalog
  interactive targets measured ≥44 px tall. Local fonts/images load; CSS role references resolve.
- Keyboard skip-to-main, Skip introduction, native material disclosures and visible
  **3 px blue focus / 2 px offset** work. Header search retains `q`; category/sort,
  pagination, no-match reset and stock labels work. No-JS search/disclosures and product
  illustration/details remain usable; purchase controls stay disabled.
- Actual Twig card rendering preserves **₱1,200** and **₱1,200.55** exactly.
- Sinag exterior: one desktop canvas, six 44 px controls, rotate/reset/pause; narrow
  dialog, Esc/focus return and Tab/Shift+Tab wrap. Reduced motion disables auto-rotation,
  not manual controls. Injected config 503 → retry restores one canvas and focus inside
  the dialog. Disabled WebGL → original image and “3D unavailable in this browser”.
  Gabi's low-stock label and Sinag's W/H/D labels remain visible in their relevant states.
- Measured actual color pairs: primary/page **12.96:1**, secondary/page **5.95:1**,
  secondary/inset **5.05:1**, action label **5.73:1**; strong boundary/surface **4.15:1**
  and boundary/inset **3.29:1**; focus/page **5.14:1**, focus/inset **4.36:1**;
  stock/low-stock/sold-out status pairs **5.78/6.18/5.91:1**.
- `vendor/bin/phpunit tests/Services/ProductServiceTest.php` → **7 tests / 39 assertions**;
  `composer cs`, `composer lint`, `node --check public/assets/js/viewer3d.js` → pass.
  Ordinary final browser visits reported no JS errors. No new permanent tests or dependencies.
- `npx --no-install contextspine check` → **0 failures / 31 existing warnings**
  (missing IDEA/RESEARCH/QAD pipeline files and QAD requirement coverage).

Historical M0–M2 performance/Lighthouse evidence above is not re-certified by this visual
reconciliation. No commit, push, merge or deployment performed; human PR review remains
the next integration step.

### Exact published-reference cutover (2026-11-10)

User correction: match the published UI and design system **1:1**; replace the previous
visual interpretation. DSD 2.0 makes the source-rendered markup, stylesheet, exact font
variants, SVG artwork and responsive states authoritative.

Implemented on `abc/design-reconciliation`:

- Ported the published header/mobile drawer, mosaic hero, process diagrams, four material
  tabs/swatches, asymmetric editorial cards, full-width dark CTA and dark selvedge footer.
- Replaced old tokens/components with the published semantic roles and native utility
  styles. Four local variable-font subsets and the published SVG family replace the
  earlier font/art direction; obsolete assets were removed. No frontend build added.
- Catalog follows the reference filter/grid/pagination layout: Featured default, eight
  rows per page and working Hide sold-out filtering. Native GET submission also works
  without JavaScript.
- Product follows the reference split, typography, mode segment, open information
  sections, purchase panel and viewer controls. Real procedural Three.js inspection
  remains; resize now preserves orbit/zoom while fitting the new viewport shape.
- PRD/SDD/journey/README and the manual screen-review guide now point to this authority,
  not earlier-direction exceptions. Inventory, environment and business contracts remain
  intact; sample commerce state is not fabricated.

Observed verification after the cutover:

- Native **1440, 768 and 360 px** reference/application comparison: header, hero, hero
  heading and introduction bounding rectangles match exactly at each width, with no
  horizontal overflow. Inspected the actual mobile editorial artwork after lazy loading.
  These measurements do not claim whole-application pixel identity: product data and
  the real renderer replace prototype sample data and its conceptual model.
- Material selection/keyboard navigation shows one panel; mobile menu navigation closes
  the dialog and Tab/Shift+Tab wrap correctly. Shared navigation also works on products.
- Catalog showed **8 + 2** products over two pages. Hide sold-out reaches the server and
  survives native no-JS submission; service fixtures prove actual exclusion and totals.
- Real 3D loaded, rotated, paused and reset. Mobile dialog is viewport-sized, has no
  internal overflow, keeps the complete model in frame across desktop/mobile resizing,
  closes with Escape and returns focus. Reduced motion disables automatic rotation.
- Injected **503** config failure → visible retry → real viewer ready; focus returns
  outside the hidden recovery panel. Forced no-WebGL → static original illustration,
  legible recovery card using published panel styles → Images closes the dialog.
  No-JS product image remains visible; unavailable mode/menu controls stay hidden.
- `vendor/bin/phpunit tests/Services/ProductServiceTest.php` → **10 tests / 60 assertions**.
  `composer cs`, `composer lint` and `node --check` for storefront/product/viewer scripts
  passed. Two new long test lines were wrapped after PHPCS reported warnings.
- Current semantic contrast: primary/secondary text on page **12.96 / 5.95**, primary
  action **5.73**, status text/background pairs **5.78–6.18**, strong control border
  on surface **4.15**, focus ring on page **5.14**. This is targeted contrast evidence,
  not an accessibility certification.
- `npx --no-install contextspine check` → **0 failures / 31 existing warnings** for
  missing IDEA/RESEARCH/QAD pipeline files and QAD requirement coverage.

Earlier reconciliation/performance evidence is historical, not recertified here.
Cart/checkout/admin remain their existing later milestones. No commit, push,
merge or deployment performed.

### M3 — Peek inside (B, with C polish)
- B: interior group (lining, pockets) per shape, lid-open tween, camera fly-in, clipping plane
  cutaway, "Peek inside" toggle button (R5, DSD §4 and published viewer component).
- C: toggle button styling, hint chips, reduced-motion handling.
- **Done when:** R5 passes; toggle reversible; no z-fighting; 60 fps on mid laptop.

M3 local implementation (issue #4, branch `abc/m3-peek-inside`, 2026-11-10):
- `bagFactory.js` builds hollow shells plus per-shape lining and authored pocket builders
  (`interior_zip`, `slip_pocket`, `back_slip`, `bottle_slip`, `pen_slip`, `divider_pocket`,
  `interior_slip`, `card_slots`, `key_leash`); exterior-only keys get no fake interior.
  Satchel/clutch flaps hinge at the rear rim and fold back π; tote/sling have `flap = null`.
  Lining/pockets are inset from shell caps; only shell/lining/obstructing trim enter
  `cutawayMaterials`, so pockets, flap and handles are never sliced.
- `viewer3d.js` adds `setInside(inside, {animate})`, immediate `isInside` + `viewchange`,
  a horizontal 68%-height cutaway, inside orbit/tilt/zoom limits, 700 ms `--duration-viewer`
  `--ease-weave` camera/target/flap transitions, exact destination restore, and current-mode
  `reset()`. Reduced motion and keyboard activation snap immediately; enabling reduced motion
  mid-transition completes it immediately. Mobile hint space is reserved so the dialog stage
  does not resize during the transition.
- `product.js`/`product.twig` add the published wrapping Peek inside / Back outside button,
  eye/arrow glyph swap, `aria-pressed`, stage label, live status, truthful interior chip,
  persistent hint, and disabled "Automatic rotation paused inside" state; retry/bfcache restores
  a real interior view. `app.css` adds the published `.btn--sm` and reserved dialog note box.

Exact commands and observed checks (isolated server, never local inventory):
- Server: `env DB_DSN=sqlite:/tmp/habi-m3-uRddX6/app.sqlite ADMIN_SEED_EMAIL=viewer@example.test ADMIN_SEED_PASSWORD_HASH='<isolated fixture hash>' php -S 127.0.0.1:8016 -t public`.
- `git diff --check && node --check public/assets/js/product.js && node --check public/assets/js/viewer3d.js && node --check public/assets/js/bagFactory.js && composer cs && composer lint && vendor/bin/phpunit tests/Services/ProductServiceTest.php && npx --no-install contextspine check`
  → JS syntax clean; PSR-12/lint clean; PHPUnit **10 tests / 60 assertions OK**;
  context check **0 failures / 31 existing warnings**.
- Final-code desktop Chromium scenario over all ten seeded products at 1440×1000, with idle
  auto-rotation paused before the baseline: Peek inside set `inside=true`, one clipping plane
  on every cutaway material, no page overflow, interior chip visible; rotate changed the camera
  inside; Back outside returned `inside=false`, all `clippingPlanes=null`, flap `0`, and the
  saved camera/target exactly at transition completion (`cameraError=0`, `targetError=0`;
  idle auto-rotation intentionally resumes afterward). Artifact:
  `/tmp/habi-m3-uRddX6/desktop-strict-scenarios.json`.
- Strict mobile 360×800 dialog scenario for tote/satchel/sling/clutch: interior chip visible,
  status "Interior view", pause disabled with truthful label, no dialog overflow, ArrowRight
  orbited inside, Reset stayed inside, Back outside restored the saved camera/target exactly at
  transition completion (`cameraError=0`, `targetError=0`), flap closed where present, stage
  height stayed 653.609375 px outside/inside/restored, Esc returned focus to 3D. Artifact:
- Reduced motion on Lupa: keyboard Enter produced immediate `inside=true`, no transition,
  damping off, flap −π, chip/status updated; Enter back produced immediate exterior with flap `0`
  and no clipping. Returning to no-preference animated again. 12 rapid API reversals finished
  exterior with `cameraError=0`, `targetError=0`, clipping cleared and flap `0`. Artifact:
  `/tmp/habi-m3-uRddX6/motion-fps.json`.
- 60 fps target, measured not assumed. Environment: Linux x86_64 host, AMD Ryzen 7 4800HS
  (8 cores/16 threads), headed Chrome 150.0.7871.24 using ANGLE on **AMD Radeon Graphics
  (Renoir, Mesa 26.2.6)** rather than SwiftShader, viewport 1440×1000 DPR 1, 180 Hz display
  baseline. rAF over 5 s each on Sinag: exterior **180.18 fps** (901 frames), inside cutaway
  **180.18 fps** (901 frames). Environment JSON: `/tmp/habi-m3-uRddX6/browser-profile.json`;
  timings: `/tmp/habi-m3-uRddX6/motion-fps.json`.

Shared DoD status: local R5/UI/PHP checks and docs are covered; no secrets or inventory changes.
**Still pending:** another track owner's PR review and merge to `main` (BUILD §2.5).
No commit, push, merge or deployment was performed.

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
| Asset/font drift | Use the approved published artwork and exact local variable fonts; record sources/licenses in DSD §6. Supplied photographs remain unpublished inspiration. |
| Modeling constraints for procedural bags | Follow the published viewer presentation (DSD §4), retain procedural configs/textures and verify actual rendering/performance; no downloaded model substitution. |
| Three.js vendored file goes stale | Pin version in README; upgrade = replace 2 files in `public/assets/vendor/three/` |
| Scope creep (accounts, reviews) | PRD §4 out-of-scope; park in backlog |
