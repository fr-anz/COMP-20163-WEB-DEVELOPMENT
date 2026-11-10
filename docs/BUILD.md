# BUILD — Habi Build Plan

| | |
|---|---|
| Version | 1.1 · 2026-11-09 |
| Basis | [PRD.md](PRD.md) R#/N# · [SDD.md](SDD.md) architecture · [DSD.md](DSD.md) components · [USER-JOURNEY.md](USER-JOURNEY.md) flows |
| Team | **4 people**, feature-phased milestones (no fixed deadline) |
| Stack | PHP 8.2+, Composer, Slim 4, Twig 3, PDO SQLite, vendored Three.js, Docker → Google Cloud e2-micro (Render alternative) |

---

## 0. Workstreams (4 tracks)

| Track | Owner focus | Owns |
|---|---|---|
| **A — Backend & Data** | PHP/Slim/PDO, models, migrations, seed, cart/checkout/order services, inventory transactions, payment simulator, auth, middleware | `src/` (all), `migrations/`, `seed/`, `tests/` |
| **B — 3D Viewer** | Three.js procedural bag factory, orbit + peek-inside, per-product configs, fallback | `public/assets/js/viewer3d.js`, `bagFactory.js`; proposes `three_config` seed values (R4–R6, R27) — A integrates |
| **C — Storefront UI** | DSD implementation, all storefront Twig views, cart drawer, checkout UX, responsive + a11y | `public/assets/css/app.css` (shared admin tokens), `views/storefront/`, `public/assets/js/cart.js`, `checkout.js` (R1–R3, R7–R17, R24–R26) |
| **D — Admin & DevOps** | Admin panel UI, Docker, Google Cloud/Render deploy, CI, README | `views/admin/`, `Dockerfile`, `.dockerignore`, `deploy/gce/`, `render.yaml`, `.github/workflows/` (R18–R23, N2, N6) |

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

> **Earlier acceptance evidence invalidated by review 2026-11-10.** State/transform
> assertions and rAF frequency did not establish rendered behavior. The defects
> were subsequently repaired and checked below; shared PR review/merge remains pending.

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

Historical local DoD assessment (superseded by the review below): local R5/UI/PHP checks and docs were reported covered; no secrets or inventory changes.
**Still pending:** another track owner's PR review and merge to `main` (BUILD §2.5).
No commit, push, merge or deployment was performed.

#### Issue #4 implementation review — 2026-11-10

Historical pre-fix findings; the repair verification below records the current result.

Review only; application code unchanged. Isolated inventory copy:
`cp data/app.sqlite /tmp/habi-issue4-review-bN4s3z/app.sqlite`, then
`env DB_DSN=sqlite:/tmp/habi-issue4-review-bN4s3z/app.sqlite php -S 127.0.0.1:8024 -t public`.

Consumer scenarios used Chromium at 1440×1000 for Sinag/Lupa/Tala/Maya (all four
shapes), plus Gabi at 360×800. Open each product, activate 3D, focus Peek inside,
press Enter, then compare `viewer.renderer.info.render.frame` across two rAF callbacks.
Browser-only diagnostic wrappers captured the actual viewer instance without altering
its behavior. Separate manual-draw probes were not treated as application success.

- **P1 — No ongoing rendering:** `viewer3d.js` `_onFrame()` advances transitions
  and controls but never calls `renderer.render(scene, camera)`. All five visits
  remained at render frame **1**, with **0** draws across the sampled callbacks.
  Sinag displayed a blank stage while the interior label changed; one diagnostic
  manual draw made the model visible (frame **2**, 14 draw calls, 3,224 triangles).
  Orbit, opening, reduced-motion switching and Back outside cannot visibly update.
- **P1 — Keyboard orbit does not accumulate:** `_finishTransition()` reapplies
  `_transitionDestination` even when no transition exists; `rotate()` calls it
  before every step. On Gabi inside, three consecutive stage ArrowRight presses
  produced the identical camera position
  `[-11.453372548712995, 74.33783009080015, 40.28710090199202]`.
  Only the first step changes the pose; subsequent steps restart from the old destination.
- **Performance acceptance unproven:** the earlier 180.18 figure measures rAF
  callbacks, not rendered frames. The current loop submits no ongoing scene draws,
  so that figure cannot certify the 60 fps target. The earlier `/tmp/habi-m3-uRddX6/`
  evidence directory was unavailable during this review.

| Issue #4 checklist / acceptance | Pre-fix review assessment |
|---|---|
| Per-shape lining, pockets and applicable dividers | Builders/config dispatch exist; all four sampled shapes have interior geometry. Consumer acceptance blocked by rendering. |
| Peek inside, applicable flaps, open totes | State changes; tote/sling have no flap, satchel/clutch reach −π. Visible behavior fails. |
| Back outside with complete exterior restoration | Camera/state change without a new draw; visible restoration fails. |
| Persistent controls/hints and immediate reduced-motion equivalents | Controls/hints present; reduced-motion state snaps without a transition, but the rendered equivalent fails. |
| R5 on all four shapes, visible detail and inside orbit | Not met: no ongoing draws; repeated keyboard orbit also fails. |
| Repeated reversible transitions without clipping/z-fighting | Not established on the rendered consumer surface; transform equality is insufficient. |
| Keyboard/mobile/reduced motion | Gabi narrow dialog opens and Esc returns focus to 3D; repeated keyboard orbit and visible reduced-motion changes fail. |
| Documented mid-range-laptop 60 fps measurement | Not established for rendered frames; remeasure after repair. |
| Shared DoD and exact evidence | Not complete: behavioral blockers and PR review/merge remain; no fresh PHP checks claimed by this review. |

Raw observations: `/tmp/habi-issue4-review-bN4s3z/observations.json`.
Blank consumer capture: `/tmp/omp-sshots-15c813d36d5469ed.webp`; diagnostic-only
manual-draw comparison: `/tmp/omp-sshots-15c813f00e1469ee.webp`.
The requested repairs and fresh rendered-performance checks follow.

#### Issue #4 rendering/orbit repairs — 2026-11-10

`viewer3d.js` now draws the scene at the end of `_onFrame()`, after transition
and control updates. `_finishTransition()` returns when there is no active
transition, so later keyboard steps, reset and resize cannot replay a stale
destination. No geometry, styling, PHP, environment or dependencies changed.

Server: `env DB_DSN=sqlite:/tmp/habi-issue4-review-bN4s3z/app.sqlite php -S 127.0.0.1:8025 -t public`.
`node --check public/assets/js/viewer3d.js` passed. Actual browser regression
scenarios used the same isolated database, never local inventory:

- Sinag/Lupa/Tala/Maya at **1440×1000 and 360×800**, DPR 1: pause idle rotation,
  rotate the exterior, Peek inside by pointer, press ArrowRight three times,
  Reset, Back outside, then repeat two complete cycles. All eight cases drew
  changing interior pixels, accumulated each keyboard step, kept Reset inside
  and returned **identical exterior canvas PNGs**. Maximum camera restore error
  was **3.56e−15**, target error **0**. Clipping cleared and modeled flaps closed.
  No page/dialog overflow; mobile Esc returned focus to 3D in every case.
- Canvas samples came immediately after the application's normal draw, excluding
  DOM control/focus overlays; no diagnostic forced renders were used.
- All four shapes at 360 px: reduced-motion pointer toggles snapped without
  camera/flap transitions or damping and drew the interior/exact restored exterior.
  Enabling reduced motion during an active transition completed it immediately.
- Hardware browser, all four shapes: **12 rapid pointer reversals each** restored
  identical exterior pixels; actual mouse drags changed the rendered inside orbit.
  Inspected interior captures show lining/configured details; no z-fighting was
  seen in the sampled views. Neither browser reported JavaScript errors.

Fresh performance measurement counted changes in `renderer.info.render.frame`
over **5 seconds per mode/shape**, with `gl.finish()` at interval boundaries.
These are actual scene-render counts, not rAF callback counts; viewport
**1440×1000, DPR 1**, visible headed Chrome **150.0.7871.24**, Linux x86_64,
**Ryzen 7 4800HS (8 cores/16 threads)** with integrated **AMD Radeon/Renoir,
Mesa 26.2.6**. CDP `SystemInfo.getInfo` confirmed hardware WebGL/compositing and
ANGLE/OpenGL, not SwiftShader. Dedicated browser launch used
`--use-gl=angle --use-angle=gl --ignore-gpu-blocklist` and an isolated profile.

| Product / shape | Exterior rendered fps | Inside rendered fps |
|---|---:|---:|
| Sinag / tote | 178.59 | 179.58 |
| Lupa / satchel | 179.79 | 179.59 |
| Tala / sling | 179.59 | 179.79 |
| Maya / clutch | 179.59 | 179.59 |

All sampled modes exceed the 60 fps target on this documented laptop profile.
This does not claim equivalent performance on every device.
Raw scenarios, frame counts/timings and CDP GPU profile:
`/tmp/habi-issue4-review-bN4s3z/fix-verification.json`.
Actual desktop interior captures: tote `/tmp/omp-sshots-15c8186ed53447f3.webp`,
satchel `/tmp/omp-sshots-15c81879d87447f4.webp`, sling
`/tmp/omp-sshots-15c81884dd7447f5.webp`, clutch
`/tmp/omp-sshots-15c8188fe73447f6.webp`; mobile clutch
`/tmp/omp-sshots-15c8184ce2d60aeb.webp`.

The two reproduced defects are fixed and their rendered regression scenarios pass.
The earlier rAF-only performance claim remains superseded. PHP checks were not
rerun for this JavaScript-only repair. **Shared DoD still requires another track
owner's PR review and merge to `main`; issue #4 is not fully Done.**


### M4 — Cart (A + C)
- A: session cart service w/ stock clamping (R9, R28), POST add/update, cart JSON for badge.
- C: cart drawer + `/cart` page (R8), toasts on add (R7), badge count, qty steppers, remove, subtotal.
- **Done when:** R7–R9, R28 pass; stock-cap correction notice works; cart survives reload.

M4 local implementation (issue #5, branch `ac/m4-session-cart`, 2026-11-10):

- Native PHP session cart, CSRF-protected POST add/update/remove, integer-centavo
  totals and live-stock reconciliation. Reads/navigation also clamp stock and remove
  inactive/deleted/sold-out lines; explanatory notices persist across subsequent reads,
  including an empty cart. Adding never reserves or decrements inventory.
- Native forms redirect with feedback; enhanced requests return the authoritative
  cart/count/totals and drawer/page fragments. Session cookies are lifetime-zero,
  HttpOnly and SameSite=Lax; responses are `no-store`. No new dependencies/env settings.
- Checkout execution remains M5. M4 supplies the named CTA with an explicit unavailable
  explanation, not a fake checkout or a link to a missing route.

Backend commands and observed outcomes:

```sh
vendor/bin/phpunit tests/Services/CartServiceTest.php
composer cs
composer lint
bun /tmp/habi-m4-qFfLy2/http-smoke.mjs
```

- Targeted PHPUnit: **17 tests / 120 assertions**; PSR-12 and PHP lint pass
  (PHP 8.5.12; project minimum remains 8.2).
- Actual HTTP smoke against `php -d session.save_path=/tmp/habi-m4-qFfLy2 -S
  127.0.0.1:8018 -t public`, isolated SQLite/admin process environment:
  cumulative add caps at stock 12; stock 12→2 clamps on GET and the notice survives
  another GET; mixed-line subtotal is exactly 475000 centavos.
- Negative, fractional, malformed, overlong and empty quantities → JSON 400 without
  applying the requested change; wrong CSRF → 403. Update-zero/removal works; zero
  stock and inactive products cannot be added. Native POST → 303 `/cart`.
- Removed the isolated server session file while retaining its stale cookie/token:
  next POST → 403 with an empty cart and fresh token; subsequent GET stays empty.
  Cookie has no Expires/Max-Age; HttpOnly/SameSite=Lax and `no-store` observed.
- Product stock is unchanged by cart mutations. HTTP evidence:
  `/tmp/habi-m4-qFfLy2/http-evidence.json`. The throwaway smoke source is removed
  after verification; the recorded scenarios describe the repeatable inputs.

**DoD status:** local consumer evidence follows. Another track owner's human PR
review and merge to `main` remain required. The checkout CTA is present but disabled
until M5; a functional checkout handoff is **not** certified. No commit, push, merge
or deployment is claimed. Issue #5 is not fully Done.

Storefront scenarios (Chromium 150, native **1440×1000, 768×1000 and 360 px**):

- Actual listing Add → toast/count 1 → product navigation retains server count;
  detail quantity 2 Add → count 3 and subtotal ₱4,950. Drawer estimate is ₱5,070
  with Metro Manila's ₱120 minimum. Drawer and full cart agree after navigation/reload.
- Quantity changes and Remove use real POSTs. Corrected two defects found during
  consumer checks: disabling the focused button lost focus before rerender, and
  a 2→1 decrement disabled its submitter before the browser submitted. Focus is
  captured before locking; enhanced steps submit directly. Last-line removal
  focuses the cart heading; a minimum-bound step focuses its quantity field.
- Duplicate native `quantity` fields made no-JS decrement ineffective. Removed that
  competing submission path: step buttons are JS-only; native users edit the labeled
  quantity and submit Update. Actual no-JS add/update/remove, region GET, reload and
  expiry recovery pass at 360 px.
- Stock 2→1 clamps on navigation; the explanation survives reload and the increment
  is disabled at the cap. Stock 1→0 removes the last line; its explanation remains
  visible after reload. Listing disables Sold out and detail has no add form.
- Drawer Tab/Shift+Tab wrap, Esc, visible header focus return, menu-to-bag handoff and
  full-page alternative pass. At 360 px the drawer is full width; real CDP touch
  gestures operate product plus/Add, header bag and cart minus.
- Mindanao selection yields matching page/drawer totals (₱3,550 for a ₱3,300
  subtotal). Exactly ₱5,000 is not free; ₱5,000.01 is free and retains the centavo
  on both surfaces. Changing quantity keeps the selected region and recomputes.
- A lost-response fixture lets the real POST commit, then rejects its response:
  only one POST occurs, a safe GET restores the authoritative count and a persistent
  uncertainty notice explains that nothing was retried.
- Expired the actual server session while leaving the product form open. Found and
  repaired an omitted JSON `error` argument that had hidden the expiry explanation.
  Now the stale POST shows the session-expired notice/count 0 and a fresh token;
  an explicit retry adds once. The native equivalent redirects to an empty cart
  with actionable informational feedback. Neither promises a saved cart.
- Keyboard-only listing Add → product link → quantity plus → Add → header drawer →
  Esc passes. Reduced motion produces `animation-name: none` on the drawer.
- Published Cart comparison (showcase frame removed, native-width storefront
  container retained): heading and grid **x/y/width match exactly** at all three
  widths. Two-line page has no horizontal overflow. Real product data, shipping
  requirements, session guidance and unavailable M5 checkout differ intentionally;
  this is not a whole-page pixel-identity claim.
- Actual contrast: primary/page **12.96:1**, secondary/surface **6.38:1**,
  input boundary/input **4.25:1**. No browser JavaScript errors observed.

Final affected-repair commands:

```sh
vendor/bin/phpcs --standard=PSR12 src/Controllers/CartController.php
php -l src/Controllers/CartController.php
node --check public/assets/js/cart.js
node --check public/assets/js/product.js
npx --no-install contextspine check
```

PHP/JS checks pass; ContextSpine reports **0 failures / 31 existing warnings** for
missing IDEA/RESEARCH/QAD documents and requirement coverage, not cart defects.
Full `composer cs`/`composer lint` and the 17-test service run above passed before
the one-line error-payload repair; its affected controller checks pass afterward.
No unrun whole-suite/performance/browser-family certification is claimed.

Raw UI scenarios/geometry: `/tmp/habi-m4-qFfLy2/ui-evidence.json`.
Final cart captures: desktop `/tmp/omp-sshots-15c820c9447b3137.webp`,
tablet `/tmp/omp-sshots-15c820c9757b3138.webp`, narrow
`/tmp/omp-sshots-15c820c995fb3139.webp`; touch drawer
`/tmp/omp-sshots-15c82036453b3136.webp`.


### M5 — Checkout, simulated payment, orders, inventory (A lead, C UI, D integration)
- A: checkout step state machine (SDD §7), shipping validation (R11), region shipping fees (R12),
  `PaymentSimulator` (R13–R14, Luhn + test cards), `OrderService::placeOrder` atomic w/ idempotency +
  rollback (R15, SDD §8), confirmation (R16), out-of-stock at place-time handling (R17).
- A: CSRF middleware on all POSTs, session hardening and admin-auth preparation (R19); D supports integration without editing A-owned `src/`.
- C: stepper, shipping form, mock card form + processing spinner + result screens, review step,
  confirmation page w/ simulation banner (N3), order page.
- **Done when:** approved card → paid order created, declined card → no order; zero oversell; R10–R17 green.

M5 local implementation ([issue #6](https://github.com/fr-anz/COMP-20163-WEB-DEVELOPMENT/issues/6),
branch `ac/m5-checkout-orders`, 2026-11-10). Two implementation agents owned the
service/test and storefront/CSS/JS slices; the coordinator integrated controllers,
middleware, session transitions and consumer verification.

Implementation checklist:

- [x] Server-enforced Shipping → Payment simulation → Review → Place → Confirmation;
  approval is bound to reviewed quantities/prices. Shipping/bag changes revoke it.
- [x] Shipping validation and the four R12 rates; strictly-above-₱5,000 free threshold.
- [x] Luhn, future MM/YY and 3–4 digit CVC validation; three test-card outcomes and
  real 1.5–2.5 s simulated processing. No gateway/network client or card persistence.
- [x] Review/Place require approval. The Place form gets its key before rendering;
  reload/transient retry reuse it; committed retries return the same order.
- [x] SQLite `BEGIN IMMEDIATE`, prepared/guarded stock decrements, old/new audit,
  paid order/item snapshots and explicit whole-transaction rollback.
- [x] Every POST passes global CSRF; strict cookie-only sessions, HttpOnly/SameSite,
  Secure on HTTPS/production, admin-path guard, accessible native checkout forms and
  session-owned order access. Full administrator sign-in/throttling remains M6.
- [x] Payment/confirmation simulation notices, `H-YYYYMMDD-NNNN` numbering, and the
  same clearly simulated delivery estimate on Review and Confirmation.

Acceptance evidence — actual HTTP on an isolated **four-worker PHP server**:

- [x] Approved card: no order before Place; afterward exactly one paid order/item/audit,
  Sinag stock **12→11**. Double submission returns `/order/H-20261110-0001` again.
  Decline and insufficient-funds cards leave order count zero and stock 12 before
  approval; bag count 1 and saved shipping remain intact.
- [x] Invalid shipping/card submissions show inline errors on their own step.
  After a browser-history defect was found, validation and declines use PRG:
  **POST 303 → same-step GET 422**. Fresh final decline/funds scenarios took
  **2322/2112 ms**, retained shipping/bag and changed neither orders nor stock.
- [x] Independent approved sessions submitted Place concurrently for one Lupa:
  exactly one successful order, stock **1→0**, loser redirected to the corrected bag
  with an explanation. An aborting SQLite `order_items` trigger forced failure
  after writes: stock/orders/items/audit all unchanged; removing it and retrying
  the **same key** created one order.
- [x] Review reload preserves the key. Stock-at-place failure explains correction;
  changed reviewed price creates no order and returns to the bag. A later new bag
  is not consumed by a committed old-key retry (controller boundary coverage).
- [x] R10–R17 and relevant N2–N4 exercised through checkout. Every tested POST
  (cart/checkout/admin/unknown paths) rejects wrong CSRF with JSON **403/no-store**.
  A different session gets order **404**. Expiring the actual session makes the old
  order 404, stale Place 403 and checkout return to the empty bag. Shipping, Review
  and Confirmation escape injected markup. Final SQLite rows and **12 session files**
  contain no submitted full PAN, expiry or CVC; server output contains none either.
  Error-field names/messages, not entered card values, may be flashed for PRG.

Exact commands and observed code checks:

```sh
vendor/bin/phpunit --filter '(ShippingServiceTest|PaymentSimulatorTest|OrderServiceTest)' tests/Services
vendor/bin/phpunit tests/Services/CartServiceTest.php
vendor/bin/phpunit tests/Services/CheckoutFlowTest.php
vendor/bin/phpunit --filter testInvalidCardRetainsShippingAndCartWithoutPersistingCardData tests/Services/CheckoutFlowTest.php
composer cs
vendor/bin/phpcs --standard=PSR12 src/Services/OrderService.php
composer lint
vendor/bin/phpcs --standard=PSR12 src/Controllers/CheckoutController.php tests/Services/CheckoutFlowTest.php
php -l src/Controllers/CheckoutController.php
php -l tests/Services/CheckoutFlowTest.php
node --check public/assets/js/checkout.js
bun /tmp/habi-m5-KStxaz/http-smoke.mjs
bun /tmp/habi-m5-KStxaz/security-smoke.mjs
bun /tmp/habi-m5-KStxaz/prg-smoke.mjs
```

Service tests: **27 tests / 154 assertions**; cart: **17 / 120**. Initial checkout
transition/ownership run: **3 / 20**, pass. After PRG, those three still passed;
the new privacy test initially mistook safe error-field names for card data.
Its corrected entered-value assertions pass in isolation: **1 / 9**. No failed
assertion is waived. PHP lint passes. PHPCS initially reported one overlong
OrderService docblock; it was wrapped and the affected check passes, as do final
controller/test PSR-12 checks. JS syntax passes. No unrun whole-suite claim.

Actual Chromium consumer checks:

- Native **1440/768/360 px**: Shipping and Payment heading rectangles match the
  published checkout at each width; Payment's simulation-banner rectangle also
  matches exactly. All four real screens have no horizontal overflow; input height
  is **44.39 px**. Sidebar becomes a keyboard/no-JS-operable summary disclosure.
  Data differences: real products/totals, optional address line 2, read-only shipping
  name on the card, no persistent-device checkbox, binding test-card/CVC copy and
  no M6 Admin action. This is not a whole-page pixel-identity claim.
- **360 px keyboard-only:** shipping → invalid card/expiry/CVC → first-error focus →
  approved payment → Review → Place → `/order/H-20261110-0005`. Step headings receive
  focus. Native no-JS add/shipping/payment/Place completes order **0006**, with Luzon
  shipping ₱180 and total ₱1,830. Order summary expands natively.
- Repaired processing status that remained hidden behind its CSS class; actual
  in-flight UI now shows `aria-busy=true`, visible status and spinner. Reduced motion
  disables both spinner and weave animations while the status remains visible.
- Repaired native Back after an earlier POST-rendered error (`ERR_CACHE_MISS` with
  no-store) using safe PRG feedback. Invalid → approved → Back now returns the payment
  GET with all card inputs empty and Review order enabled.
- Real sold-out Lupa has a disabled action; Gabi still shows “Only 3 left”.
  Input focus uses the blue border and 3 px shadow. Actual contrast: input text
  **14.23:1**, boundary **4.25:1**, simulation notice **5.94:1**, primary action
  **5.73:1**. No JS errors observed during the normal checkout visits.

Evidence: `/tmp/habi-m5-KStxaz/{http,security,prg,ui,contrast,admin-auth,log-privacy}-evidence.json`
and `server-log.txt`. Representative captures: Shipping
`/tmp/omp-sshots-15c8327a3a8eb793.webp`, narrow Payment
`/tmp/omp-sshots-15c833ba894eb796.webp`, narrow Confirmation
`/tmp/omp-sshots-15c833d62fceb797.webp`. Temporary smoke sources/server launcher were
removed after verification; real `.env` and `data/app.sqlite` were never changed.
No new dependency, migration or environment variable was needed.
`npx --no-install contextspine check` → **0 failures / 31 existing warnings** for
missing IDEA/RESEARCH/QAD documents and QAD requirement coverage; no files changed.

**Shared DoD blocker:** another track owner's human PR review and merge to `main`
(§2.5) remain external and **not complete**. Local implementation/acceptance,
documentation and exercised checks above are complete; issue #6 is **not fully Done**
until that review/merge occurs. No commit, push, merge or deployment was performed.


### M6 — Admin panel (D + A + C)
- A: admin login (R19: password_hash, throttle, generic errors), product CRUD service (R20),
  stock adjust + audit (R18, R21), orders list/status/cancel-restores-stock (R22), image upload (R23).
- D/C: admin layout + pages (dashboard w/ low-stock alerts R18, products table/form, orders table/detail, audit log).
- **Done when:** R18–R23 pass; non-admin gets redirected from `/admin/*`; CSRF enforced.

M6 local implementation ([issue #7](https://github.com/fr-anz/COMP-20163-WEB-DEVELOPMENT/issues/7),
branch `acd/m6-admin-management`, 2026-11-10). One test-writing agent owned the new service
tests; the coordinator wrote services, controllers, templates, CSS/JS and ran consumer checks.
An independent code review agent's six findings were all fixed and re-verified (below).

Implementation checklist:

- [x] Email/password sign-in and logout: `password_verify`, one generic error, 5 failures per
  email+IP → 60 s lockout (`login_attempts`, upgrade migration `002`), session ID and CSRF
  token rotated on sign-in/out.
- [x] Product create/edit/archive/restore: centavo prices without floats, demonstration
  material/place-of-make labels, dimensions, threshold, procedural 3D config limited to
  shape/handle combinations the viewer builds, content-validated JPEG/PNG/WebP ≤2 MB upload
  or a seeded demo illustration. Stock is set only on creation (audited `initial_stock`).
- [x] Dashboard metrics, low-stock alerts and recent orders; two-step stock adjustment with an
  allowlisted reason (+ note) applied only against the reviewed stock; audit log view.
- [x] Order list (status/date filters), detail, one-step fulfilment, cancel (paid/packed only)
  restoring every item's stock with audit rows in one transaction; shopper confirmation
  shows the current status.
- [x] Admin shell/tables/forms/dialogs from the published Admin example (DSD §4 Admin, §5).

Acceptance evidence — actual HTTP on an isolated **four-worker PHP server**
(`PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8016 -t public`, temporary `DB_DSN` and admin
hash; real `.env`/`data/app.sqlite` untouched). Three real simulated checkouts created
`H-20261110-0001…0003` before the admin script ran (`bash /tmp/habi-m6-run/admin-smoke.sh`):
**64 / 64 checks pass**.

- [x] R19/N2: 10 admin GET paths redirect 303 to `/admin/login` signed out; a signed-out POST
  with a valid anonymous CSRF token also redirects. Review found percent-encoded paths
  (`/%61dmin/orders`, `/admin%2Forders`) bypassed the gate (200); the middleware now
  normalizes exactly like Slim's router → 303, with regression tests.
- [x] Lockout: failures 1–4 → 422 generic "Invalid email or password."; 5th → 429 "Wait 60
  seconds"; correct password during lock refused; unknown email same message; after expiry
  the correct password signs in. Eight **parallel** wrong attempts lock the key and a correct
  password is then refused. The pre-login session ID no longer works after sign-in and the old
  CSRF token is rejected (403).
- [x] CSRF: wrong or missing token → 403 on all 8 admin POST routes; nothing changed.
- [x] R20/R23: create with real PNG → `₱2,150.50` stored as `215050`, `initial_stock` audit,
  upload served `200 image/png`, shown on the storefront product page, 3D JSON from the form.
  Text file named `.png` and a 2.2 MB file are rejected with nothing created/stored;
  `/uploads/..%2f..%2f.env` → 404. Edit changes price but ignores a submitted stock. Archive
  → storefront 404; restore → 200. A tote with `top_handle` is rejected. Markup in names is
  escaped.
- [x] R21/R18: result below zero and a stale `expected_stock` are not applied; a valid −2 writes
  `15>13 | Damaged in storage — water | admin 1`. Audit page lists checkout (`by system`),
  adjustments, initial stock and cancellations with UTC times.
- [x] R22: paid→shipped rejected; paid→packed applied; cancel restores stock and writes
  `order_cancelled: H-20261110-0001` by admin 1; a repeat cancel does not restore twice;
  a shipped order cannot be cancelled; status/date filters work. A shopper's confirmation
  changes from "Paid (simulated)" to "Cancelled — nothing was charged".
- [ ] **Task timing (PRD §7) — goal not human-measured.** A scripted Chromium run with simulated
  human pacing (250 ms per keystroke, 1.5 s before each field/action) took **28.6 s** to add
  a product with an image and **8.4 s** to adjust stock (both < 2 min / < 30 s). This is a
  pacing model, not a measurement with a real administrator.

Actual Chromium consumer checks (1440 / 768 / 360 px): login, dashboard, products, add/edit/
archive dialogs, inventory, adjust/confirm dialogs, orders, order detail + cancel dialog and
audit render with the published anatomy (sidebar ≥896 px, scrolling nav row below, tables
scroll inside their frame). Initial review found 360 px page overflow (absolutely positioned
`sr-only` text escaping scrollers, unconstrained grid columns), a missing `@4xl:flex`
utility hiding the sidebar lockup and dialog macros not rendering inside `{% embed %}`; all
fixed. Final: **no horizontal overflow** on all 12 screens at all three widths. Dialogs focus
the first field (or Close), Tab/Shift+Tab wrap, Esc returns to the list. JavaScript disabled:
sign-in, Apply-button filters, adjust → confirm and order cancel all complete. Contrast:
destructive action **7.30:1**, active nav **13.9:1**, success notice **5.78:1**, warning chip
**6.18:1**, info chip **5.94:1**, secondary text **6.38:1**. Reference captures were taken
from the published Admin example; differences are the real-data adaptations listed in DSD §4.

Commands and code checks:

```sh
vendor/bin/phpunit                      # 112 tests / 916 assertions, OK
composer cs                             # PSR-12 clean
composer lint                           # php -l clean
node --check public/assets/js/admin.js  # OK
npx --no-install contextspine check     # 0 failures / 31 existing warnings
```

New tests: AuthService (6), StockService (6), AdminOrderService (7), AdminProductService (7),
ImageUploadService (5, checked-in image fixtures), UpgradeMigration (1), AdminAuthMiddleware
(encoded paths). Evidence: `/tmp/habi-m6-run/{smoke-result.txt,server-log.txt}`.

**Shared DoD status:** merged to `main` via PR #17 (2026-11-10); GitHub records **no PR review**,
so the §2.5 review by another track owner is still **not complete** (the automated review agent
does not replace it). The human timing measurement above is also open. Re-checked on `main`
2026-11-10: PHPUnit 112/916 OK, `composer cs` and `composer lint` clean.

### M7 — Polish: responsive, a11y, SEO, content (all)
- C: 360 px pass (R24), keyboard-only checkout test (R25), focus rings, aria-live toasts,
  reduced-motion, per-page meta/OG/favicon (R26), final seed content (names, descriptions, materials/origin).
- A: `stock` API edge cases, order number format, error pages (404/500) in DSD style.
- **Done when:** Lighthouse (perf ≥ 80, a11y ≥ 90) on home/listing/product/checkout; R24–R26 green. **Skip:** all pages skip-to-content. **Keyboard:** full checkout without mouse (R25). **Reduced-motion:** all animations respect preference.

M7 local implementation ([issue #8](https://github.com/fr-anz/COMP-20163-WEB-DEVELOPMENT/issues/8),
branch `abcd/m7-polish`, 2026-11-10). Two writer agents owned the error page/test and the
SEO head; the coordinator ran the audits and repaired the defects they found.

Implementation checklist:

- [x] Inspected home, listing (results + empty), product (in stock, sold out, long name), bag,
  checkout, 404 and admin dashboard/products/inventory/orders/audit at 360/768/1440 px (touch
  emulation) with a 56-character product name: **no horizontal overflow** on any of 39
  screen/width combinations; confirmation checked at 360 px in the journey below. Loading/
  failure/fallback states were last exercised in M2/M3 and are unchanged.
- [x] Keyboard-only 360 px touch device journey with reduced motion (no mouse or tap events):
  first Tab is the skip link and Enter focuses `#main-content`; Skip introduction; arrow-key
  material tabs; mobile menu Esc → focus back on Open menu; card → product; 3D opens in 0.81 s,
  Esc returns focus to the 3D toggle; Add to bag announces "Added Sinag Tote to your bag." and
  the badge reads 1; bag link opens the drawer with focus inside, Esc returns focus to it; an
  empty shipping submission marks 7 fields `aria-invalid`, each labelled and linked to its error
  text, focusing the first; a declined card stays on Payment with an announced error; the approved
  card reaches Review → Place → `/order/H-20261110-0002`, which shows the simulation notice.
  Every focused stop had a visible indicator; no console or page errors (422 validation
  responses excluded).
- [x] Contrast measured on rendered text/background pairs (all pages above, including admin):
  lowest **5.05:1** (secondary text on fiber), then 5.34 (rust on cream), 5.73 (skip link),
  5.78 (In stock chip), 5.94 (Paid chip), 5.99 (footer heading), 6.18 (low-stock chip); body
  text 13.9:1. Non-text: input border 4.25:1 on its field and 3.87:1 on the page, focus ring
  5.14:1, secondary button border 12.96:1. Text over illustrations was not measured.
  Reduced motion: no running animations on home/listing/product, motion tokens `.01ms`,
  `scroll-behavior: auto`; viewer auto-rotation off with its control disabled ("Automatic rotation
  off (reduced motion)"). Without reduced motion, 7–11 animations run as designed.
- [x] Touch targets: compact reference controls were 27–42 px (`.btn--sm` 36, Images/3D 40,
  breadcrumbs 37, steppers 41–42, footer/admin links 27–30, admin nav 40). New coarse-pointer
  rules raise them to 44 px; fine-pointer sizes stay as published (re-measured unchanged at 1440).
  Remaining under 44 px on touch: catalog card titles (their stretched link covers the whole card).
- [x] R26: one title and description per page from `title`/`description` blocks; canonical,
  Open Graph (`og:type` product on detail pages) and Twitter card with absolute URLs (`APP_URL`
  or request host); new original `og-habi.png` (1200×630, from repo artwork) and `/favicon.ico`
  (the missing file caused Lighthouse console errors). Bag/checkout/order are `noindex`.
  Titles with `&`, quotes and markup are escaped once (the first draft double-escaped `og:title`).
  Logo link name now comes from its visible wordmark (fixes `label-content-name-mismatch`).
- [x] Error pages: standalone Habi 404/405/500 pages with skip link, lockup, two recovery
  actions and the demo notice. Found and fixed: with a failing database the page leaked a PHP
  `mkdir()` warning with a server path and returned **HTTP 200**; `display_errors` is now off in
  the front controller (warnings still reach the server log) → 500, no leak. 405 via `PUT /`.
- [x] Content: seed names/descriptions remain fictional with "Illustrative materials" and
  "origin unverified" labels; scan found no maker, region, handmade, certification or
  sustainability claim. Public raster images are only the generated OG image and favicon; no
  reference photograph is published. Demo, simulated-payment and "nothing charged" notices
  are present on footer, checkout, review, confirmation and admin.
- [x] Stock API (`GET /api/product/{slug}/stock`, all `no-store` JSON): active 200; sold out 200
  with `stock: 0` (page shows Sold out); inactive, unknown, uppercase, NUL-encoded, SQL-like and
  600-character slugs 404 JSON; POST is rejected by CSRF (403). Order numbers: real checkouts
  produced `H-20261110-0001/0002`; another session's confirmation URL is 404.
  `OrderServiceTest` already covers the sequence and `9999` overflow.
- [x] Performance: listing LCP missed N1 (3.2 s). Dynamic responses were uncompressed; zlib
  now negotiates gzip, and the first four listing images load eagerly with high priority.
  Heading order on the listing was fixed with a visually hidden "Bags" heading.

Lighthouse **13.6.0**, mobile defaults (simulated slow 4G, 4× CPU), isolated
`PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8040 -t public` with temporary `DB_DSN`/admin hash
(`bash /tmp/habi-m7/lh.sh gz`; checkout uses a session cookie with one bag item):

| Page | Performance | Accessibility | SEO | Best practices | LCP | TBT |
|---|---|---|---|---|---|---|
| Home | 100 | 100 | 100 | 100 | 1.1 s | 0 ms |
| Listing | 100 | 100 | 100 | 100 | 1.1 s | 0 ms |
| Product | 82 | 100 | 100 | 100 | 1.1 s | 740 ms |
| Checkout | 96 | 100 | 66 (intentional `noindex`) | 100 | 2.8 s | 0 ms |

Before the fixes: listing performance 94 with LCP 3.2 s, accessibility 99, SEO 54–91. Product
TBT is the Three.js parse/initialization under 4× CPU; the score stays above 80.

N1 separately (cold cache, CDP 150 ms latency, 1.6 Mbps down / 750 Kbps up, 4× CPU,
`node /tmp/habi-m7/n1.mjs`):

| Page / viewport | LCP | Load | Viewer ready |
|---|---|---|---|
| Home 360 | 1.812 s | 2.092 s | — |
| Listing 360 | 1.836 s | 2.153 s | — |
| Product 1280 | 2.208 s | 2.819 s | **0.975 s after load** |
| Product 360 | 2.200 s | 2.830 s | 0.857 s after the 3D toggle |
| Checkout 360 | 1.604 s | 1.978 s | — |

Commands and code checks:

```sh
vendor/bin/phpunit                      # 118 tests / 953 assertions, OK
composer cs                             # PSR-12 clean
composer lint                           # php -l clean
node --check public/assets/js/*.js      # OK
npx --no-install contextspine check     # 0 failures / 31 existing warnings
```

New test: `ErrorHandlerTest` (404/405/500 pages, no exception detail with display enabled).
Throwaway audit scripts and Lighthouse reports: `/tmp/habi-m7/`.

**Shared DoD blocker:** review by another track owner and merge (§2.5) are external and not
complete. No real-device (physical phone/screen reader) pass was run; touch was emulated.
An automated review agent found one bug, fixed before commit: the `X-Forwarded-Proto` check
read the wrong `$_SERVER` key, so proxied HTTPS produced `http://` canonical/OG URLs; it now
reads `HTTP_X_FORWARDED_PROTO` (and treats `APP_ENV=production` as HTTPS, like the session).

### M8 — Deploy & CI (D lead)
- D: `Dockerfile` (php:8.2-cli, composer install --no-dev, `php -S 0.0.0.0:$PORT -t public public/index.php`),
  `.dockerignore`, Google Cloud e2-micro stack `deploy/gce/` (Compose + Caddy HTTPS, data on the VM
  disk at `/app/data`), Render `render.yaml` alt, GitHub Actions CI (composer validate, php -l, PSR-12,
  PHPUnit, docker build) and SSH deploy of `main` after CI. Railway was replaced by Google Cloud (D6).
- A: env-driven DSN (`DB_DSN`), boot migration on empty volume, admin seeded from env secrets.
- All: README final (run, deploy, test cards, architecture diagram), PRD §9 items resolved or scheduled.
- **Done when:** live HTTPS URL on the Google Cloud VM; restart/redeploy → data persists (N6); CI green on main.

M8 implementation ([issue #9](https://github.com/fr-anz/COMP-20163-WEB-DEVELOPMENT/issues/9),
branch `ad/m8-deploy`, 2026-11-10). Decisions: `.context/decisions.md` D5 (router), D6 (Google Cloud).

Implementation checklist:

- [x] `Dockerfile` (php:8.2-cli, production `php.ini`, `composer install --no-dev`, optimized
  autoload, `PHP_CLI_SERVER_WORKERS=4`, `CMD php -S 0.0.0.0:${PORT} -t public public/index.php`)
  and an allowlist `.dockerignore`. The image contains no `.env`, `data/*.sqlite`, uploads, `.git` or dev
  dependencies, and has `pdo_sqlite`, `mbstring`, `zlib` and `fileinfo` on PHP 8.2.35.
- [x] Google Cloud e2-micro: `deploy/gce/compose.yaml` (app + Caddy, `/srv/habi/data` → `/app/data`,
  raw env files, rotated logs), `caddy/Caddyfile`, idempotent `setup.sh`, and `update.sh` (also the SSH
  forced command; deploy lock, refuses commits without the deploy files, reloads Caddy).
  The README runbook covers `gcloud`, DNS, deploy key, setup and GitHub variables.
  Railway was first configured, then replaced by the owner's choice (D6). Render alternative:
  `render.yaml` (paid plan + disk) validates against Render's JSON schema.
- [x] `DB_DSN` comes from the environment (image default `sqlite:/app/data/app.sqlite`); seed
  credentials only from `ADMIN_SEED_*`. An empty volume initializes once; nothing reseeds it.
- [x] `.github/workflows/ci.yml`: composer validate, `php -l`, PSR-12, PHPUnit, Docker build +
  empty-volume boot/routing/restart smoke; `deploy` job (push to `main`, after both jobs, only when
  `GCE_HOST` is set) runs `update.sh <sha>` over SSH with a pinned host key, then checks HTTPS `/`.
- [x] README run/deploy/test-card/architecture (Mermaid) sections; PRD §9: fonts/assets and
  deployment resolved, province list scheduled for v2.

**Defect found and fixed (N6, R23).** Under PHP 8.2's built-in server, the image and course version,
`/uploads/<hex>.png` returned the server's own 404 without running the app. Any missing dotted
path is treated as a static file (php-src #12604). Local PHP 8.5 routed it, which is why M6
observed 200. `public/index.php` is now also the router script: real files under `public/` are
passed back to the server; all other paths reach Slim. Uploaded images now serve `200 image/png`
after a redeploy. Traversal (`/../.env`, `/%2e%2e/.env`, `/../composer.json` with `--path-as-is`)
gets the Habi 404, and `/index.php` and missing assets also get the Habi 404. Review found that a
`%00` path made `realpath()` throw an uncaught `ValueError`. NUL paths now skip the passthrough:
`/%00` and `/assets/%00.css` get the Habi 404, with no fatal error in the log. Also removed two
stale "Checkout arrives in a later milestone" sentences from the product page (checkout is live
since M5).

Container verification (local Docker 2026-11-10, image `habi:m8`, named volume, isolated admin hash;
throwaway scripts in `/tmp/habi-m8/`):

- [x] Health: `GET /` → 200 from the container; Secure, HttpOnly, SameSite=Lax session cookie;
  `no-store` and security headers present.
- [x] Simulated payments over real HTTP: `4242…4242` → Review → Place → `H-20261110-0001`, Sinag
  12 → 11; `…0002` → 422 "declined (simulated)"; `…9995` → 422 "Insufficient funds (simulated)".
  Neither decline created an order or changed stock.
- [x] Admin: sign-in, two-step +5 adjustment (Alon 15 → 20, audit row with reason/note), image
  upload served from `/uploads/…png`, and the order list shows the browser-placed order.
- [x] Stock race: Gabi set to 1 via admin; 8 sessions reached Review, then 8 parallel Place
  requests → exactly **1** order (`H-20261110-0002`), 7 redirected to the bag; stock 0, never negative.
- [x] Persistence (N6): snapshot of all product stock, orders, audit count and admins was identical
  after `docker restart`, after container removal + new container with a **different**
  `ADMIN_SEED_*` (no reseed; original admin still signs in), and after rebuild + recreate (uploaded
  file still served).
- [x] Fresh empty volume without seed variables → generic 500, no tables created, nothing leaked;
  stderr names the missing variables. Same volume with variables → 200, 10 products / 4 categories.
- [x] Dev/production parity: 16 routes (pages, APIs, 404/303, static, gzip'd Three.js adapter,
  dotted upload path, `/index.php`) return identical status + content type from
  `php -S … -t public public/index.php` (PHP 8.5, temp DB) and the container (PHP 8.2).
- [x] Browser (managed Chromium, container): 360 px reduced motion, first Tab = skip link, no
  overflow on home/product/order; Add to bag → shipping → 4242 → Review → Place → order page with
  simulation notice; Lupa 3D viewer ready 256 ms (360, dialog) / 278 ms (1280, inline) after load
  including the toggle; Esc returns focus to 3D; no page errors. Admin at 1440 signs in.
- [x] Lighthouse 13.6.0 accessibility (mobile defaults) on the container: home, listing, product,
  admin sign-in all **100**. Checkout Lighthouse was not run on the container (it was run in M7).

Code checks (final working tree): `composer validate --strict`, `composer cs`, `composer lint` clean;
`vendor/bin/phpunit` **118 tests / 953 assertions** OK on PHP 8.5 and in `php:8.2-cli`; actionlint
(with shellcheck) clean; CI Docker step executed locally → pass; `contextspine check` 0 failures /
31 existing warnings. No new permanent test: the change is start-command/routing behavior, covered
by the CI container smoke (static file + dotted application path).

Google Cloud VM rehearsal (2026-11-10). This ran on a privileged `debian:13` container standing in for
the VM: Docker 29.9.3 / Compose v5.7.0 inside it, the branch cloned from GitHub, and `HABI_DOMAIN=localhost`,
so Caddy used its internal CA rather than Let's Encrypt:

- [x] `setup.sh` created `/srv/habi/{app,data}`, both env files (mode 600, owner `deploy`), and the forced-command
  key. It deployed `c7e8ec5` → "healthy". Re-running it with different seed values kept both env files,
  did not duplicate the key, and left the data unchanged.
- [x] HTTPS via Caddy: `https://localhost/` 200 over HTTP/2. `http://` gives 308 to `https://`. Canonical is
  `https://localhost/`, and `app.css` is served with gzip. The 60-character bcrypt hash reached the
  app intact (`format: raw`); without `raw`, Compose truncated `$2y$10$…` to `$2y$10`.
- [x] Admin sign-in (303 → `/admin`) and a +3 stock adjustment (Alon 15 → 18). The stock and audit row
  were unchanged after `update.sh`, after `compose down` + `up`, and after re-running setup.
- [x] SSH forced command: no argument → deploys the branch head; `ls; id` and `deadbeef` → "expected a
  40-character commit SHA"; an unknown 40-hex SHA → "is not on <branch>"; the real SHA deploys. A
  PTY request is refused (`restrict`).
- [x] Review fixes (re-run on the same VM):
  - `ssh … c7e8ec5…` (a branch commit without `caddy/Caddyfile`) → "predates …; refusing", and the
    checkout stayed at `876e90c`.
  - Two concurrent `update.sh` runs serialized on `flock`: B finished after A.
  - Caddy reads `/etc/caddy` as a directory mount, and `caddy reload` runs on each deploy; HTTPS 200,
    stock still 18.
  - The README backup command ran twice and wrote two timestamped files.
- [x] The rehearsal gaps are now covered by the live VM (below): Docker starts at boot, Let's
  Encrypt issued the certificate, the Google firewall/IP work, and the GitHub `deploy` job succeeded.
  Swap on the real VM was not inspected (`swapon --show` not run). actionlint and shellcheck
  pass for the workflow and both scripts.

Live deployment (2026-11-10). Project `ark-test-486521`; VM `habi` is an `e2-micro` in `us-west1-b`,
Debian 13, 30 GB `pd-standard`, static IP `136.118.96.127`. DNS: Cloudflare A record, DNS only.
The owner ran the README runbook in Cloud Shell; `setup.sh` from `main` printed
`update.sh: healthy at 8a1349a`, and `df -h /` showed 30G (19% used).

- [x] <https://habi.theavenir.dev/>: 200 over HTTP/2 (0.73 s). `http://` → 308 to `https://`.
  Certificate from Let's Encrypt (`CN=habi.theavenir.dev`), valid until 2027-02-08.
  `/products`, `/product/sinag-tote`, the stock API, `/admin/login` and `/favicon.ico` → 200. A
  missing dotted upload path → the Habi 404, so the PHP 8.2 router fix is live. Canonical is
  `https://habi.theavenir.dev/`; the session cookie is Secure, HttpOnly, SameSite=Lax; `no-store`
  and `X-Frame-Options: DENY` are set.
- [x] The SSH host key from an independent `ssh-keyscan` matched the line `setup.sh` printed, and was
  stored as `GCE_KNOWN_HOSTS`. `GCE_HOST` and the `GCE_SSH_KEY` secret are set.
- [x] Auto-deploy: re-running main CI
  ([run 42813150250](https://github.com/fr-anz/COMP-20163-WEB-DEVELOPMENT/actions/runs/42813150250))
  → PHP checks, Docker image and **Deploy to Google Cloud** all passed. The VM log showed
  `update.sh: healthy at 8a1349a`, and the public HTTPS check returned 200.
- [x] Persistence across reboot: the owner signed in to `/admin` and adjusted Sinag Tote 12 → 13,
  then ran `sudo reboot`. The site answered 200 again without manual action (Docker and the
  containers restarted), and `/api/product/sinag-tote/stock` still reported **13**.

Acceptance status:

- [x] Live HTTPS URL on the Google Cloud VM with `/` 200: <https://habi.theavenir.dev/> (above).
- [x] Persistence across reboot and redeploy on the VM: stock 13 survived `sudo reboot`. The CI
  redeploy kept the data, and redeploy persistence was also covered in the rehearsal and local Docker.
- [x] CI green on `main`: [run 42813150250](https://github.com/fr-anz/COMP-20163-WEB-DEVELOPMENT/actions/runs/42813150250)
  (also green on PR #19, [run 42804495037](https://github.com/fr-anz/COMP-20163-WEB-DEVELOPMENT/actions/runs/42804495037)).
- [ ] Cross-track PR review (§2.5): **not met**. The owner merged PR #19 with 0 reviews recorded.
- [ ] Real-device/screen-reader pass: not run (emulated touch only, as in M7).

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
php -S localhost:8000 -t public public/index.php   # run (auto-migrates + seeds on first request)
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
| e2-micro limits (1 GB RAM, 1 GB/month free egress, billed IPv4) | 1 GB swap; Caddy compression; budget alert; README cost note. Render alternative if the VM is outgrown |
| Asset/font drift | Use the approved published artwork and exact local variable fonts; record sources/licenses in DSD §6. Supplied photographs remain unpublished inspiration. |
| Modeling constraints for procedural bags | Follow the published viewer presentation (DSD §4), retain procedural configs/textures and verify actual rendering/performance; no downloaded model substitution. |
| Three.js vendored file goes stale | Pin version in README; upgrade = replace 2 files in `public/assets/vendor/three/` |
| Scope creep (accounts, reviews) | PRD §4 out-of-scope; park in backlog |
