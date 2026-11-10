# SDD — Habi System Design Document

| | |
|---|---|
| Version | 1.1 |
| Date | 2026-11-09 |
| Implements | [PRD.md](PRD.md) requirements **R1–R28, N1–N8** (cited inline) |
| Design language | [DSD.md](DSD.md) · Build order: [BUILD.md](BUILD.md) · Flows: [USER-JOURNEY.md](USER-JOURNEY.md) |

---

## 1. Goals & Constraints

Design a PHP e-commerce system that (a) satisfies every R#/N# in the PRD, (b) runs locally
with zero install beyond PHP+Composer, and (c) deploys to the web unchanged via Docker
(Google Cloud Compute Engine e2-micro; Render alternative). Checkout payment is simulated by design (N3).
**UI authority:** the published Habi UI/design system is followed 1:1, replacing earlier visual interpretations (DSD §§1–4). Craft discovery remains skippable; shopping is optional.

## 2. Technology Stack

| Layer | Choice | Why |
|---|---|---|
| Language | **PHP 8.2+** | Course requirement; mature, hostable anywhere |
| Package manager | **Composer** (PSR-4 autoload, PSR-12) | Allowed; needed for Slim/Twig |
| Framework | **Slim 4** (micro-framework) + nyholm/psr7 | Tiny, explicit routing; no framework lock-in |
| Templating | **Twig 3** | Auto-escaping kills most XSS (N2); clean separation |
| Database | **PDO + SQLite** (dev & prod default), MySQL-compatible schema for easy migration | Zero-setup dev (`php -S`), persists on the VM disk (`/srv/habi/data`); DSN switch via env (§12) |
| 3D | **Three.js** (vendored ES module in `public/assets/vendor/three/`), vanilla JS modules | Procedural woven-fiber/textile-inspired bags (R4–R6, DSD §§4,6), no external model files, works offline |
| Frontend | Server-rendered Twig pages + small vanilla JS module per page; no SPA build step | Fast LCP (N1), simple deploy, course-friendly |
| Sessions | Native PHP sessions, `HttpOnly`, `SameSite=Lax`, regenerated on admin login (N2) | Cart persistence (R28), admin auth (R19) |
| Container | **Docker** (`php:8.2-cli` + Composer + `php -S 0.0.0.0:$PORT -t public public/index.php`) | Identical runtime locally and on the Google Cloud VM / Render (N6) |
| Tests | PHPUnit for services; manual smoke checklist for flows | See §13 |
| CI | GitHub Actions: `composer validate`, `php -l`, PSR-12, PHPUnit, Docker build/boot smoke | See §12 |

No frontend bundler: JS is native ES modules with an import map; Three.js is vendored so
the site works without CDN access.

## 3. Architecture

```
Browser (Twig pages + JS modules + vendored Three.js)
   │  HTTP (session cookie, CSRF token in forms)
   ▼
┌──────────────────────────────────────────────┐
│  public/index.php  (front controller)        │
│  Slim 4 app → routes → Middleware (Csrf,     │
│  AdminAuth) → Controllers → Services → PDO  │
└──────────────────────────────────────────────┘
   │
   ▼
SQLite file  data/app.sqlite   (production: /srv/habi/data on the VM, mounted at /app/data; WAL mode)
   ▲
   └── migrations/ + seed/  (schema + 4 products, R7/N7)

Static: public/assets/{css,js,img,vendor/three}
Admin: same app, routes under /admin behind AdminAuthMiddleware (R19)
```

- **Stateless-ish:** all mutable state in SQLite + PHP session. Cart lives in session only (R28);
  no saved cart after session expiry. No in-memory carts.
- **API-ish JSON endpoints** under `/api/…` serve the 3D config (R6); `/cart` and cart mutations return
  badge counts (server-rendered) so JS modules stay decoupled from HTML.

## 4. Directory Structure

```
├── public/                 # docroot + router (php -S -t public public/index.php)
│   ├── index.php           # front controller
│   └── assets/
│       ├── css/  (DSD tokens → app.css)
│       ├── js/   (modules: viewer3d.js, cart.js, checkout.js, admin.js …)
│       ├── img/  (seeded product images, favicon)
│       └── vendor/three/ (three.module.js, OrbitControls.js)
├── src/
│   ├── Controllers/        # StorefrontController, CartController, CheckoutController,
│   │                       #   AdminController, AdminProductController, AdminOrderController, ApiController
│   ├── Services/           # ProductService, CartService, OrderService, PaymentSimulator, StockService, AuthService
│   ├── Models/             # Product, Order, OrderItem, Admin (thin active-record-ish wrappers over PDO)
│   ├── Middleware/         # CsrfMiddleware, AdminAuthMiddleware, ErrorHandler
│   ├── Support/            # db() PDO factory, env(), helpers (escape, csrf token, money fmt ₱)
│   └── routes.php          # all Slim routes
├── views/                  # Twig templates (layouts/, storefront/, admin/, emails n/a)
├── data/                   # app.sqlite (gitignored), uploads/ (product images, R23)
├── migrations/             # 001_schema.sql … (run idempotently on boot if missing)
├── seed/                   # seed.php → 4 products (one per category) w/ images + 3D configs (N7)
├── tests/                  # PHPUnit: services (OrderService stock atomicity, PaymentSimulator Luhn)
├── docs/                   # PRD, SDD, DSD, BUILD, USER-JOURNEY
├── Dockerfile  .dockerignore  render.yaml  .github/workflows/ci.yml  .env.example  composer.json  README.md
```

M1 implements home, catalog and stock JSON through `Controllers/StorefrontController`
and `Services/ProductService`, registered in `public/index.php`. M2 extends detail with
ordered gallery images and adds the active-only procedural config endpoint.
`src/Bootstrap/` handles initialization/catalog seeding, `src/Support/` environment/PDO
helpers, and `src/Console/` the explicit reset command. M4 adds native session cart support, `CartService` and `CartController`; M5 adds `CheckoutController`, `ShippingService`, `PaymentSimulator`, atomic `OrderService` and global CSRF/admin-path middleware. M6 adds `AdminController` (sign-in, dashboard, audit), `AdminProductController` (products, inventory, stock), `AdminOrderController`, `UploadController`, the shared `AdminResponder`, and `AuthService`, `AdminProductService`, `StockService`, `ImageUploadService` plus admin methods on `OrderService`. No `Models/` layer was introduced: services return plain arrays, as in M1–M5. Admin views live in `views/admin/`; enhancement is `admin.js`.

Canonical CSS is `public/assets/css/app.css`, using the exact published semantic roles
and consumed component/utility rules (DSD §2). Actual modules are `storefront.js`
(menu/materials/catalog enhancement), `cart.js`, `product.js`, `viewer3d.js` and `bagFactory.js`,
with Twig views under `views/`. No React runtime, Tailwind build or CDN is required.

## 5. Data Model

Money is stored as **integer centavos** (₱1 = 100) to avoid float errors (N2-ish correctness).

```
admins        1───*  stock_audit
products      1───*  product_images
products      1───*  stock_audit
orders        1───*  order_items
orders        *───*  order_statuses (enum values: pending_payment/paid/packed/shipped/delivered/cancelled/failed)
```

| Table | Key columns |
|---|---|
| `admins` | id, email UNIQUE, password_hash, name, created_at |
| `products` | id, sku UNIQUE, slug UNIQUE, name, category, description, materials, place_of_make, price_centavos, stock INT, low_stock_threshold INT DEFAULT 3, dimensions, `three_config` JSON (R6), is_active, created_at, updated_at |
| `product_images` | id, product_id FK, path, sort_order, is_primary |
| `orders` | id, order_number UNIQUE (e.g. `H-20261105-0042`), status (`pending_payment`/`paid`/`packed`/`shipped`/`delivered`/`cancelled`/`failed`), customer name/email/phone, shipping address fields, shipping_region, shipping_fee_centavos, subtotal_centavos, total_centavos, payment_last4 NULL, payment_brand NULL, payment_result NULL, idempotency_key UNIQUE, created_at, updated_at |
| `order_items` | id, order_id FK, product_id FK, name_snapshot, price_centavos, qty, line_total_centavos |
| `stock_audit` | id, product_id FK, admin_id NULL, old_stock, new_stock, reason, created_at (R18) |
| `login_attempts` (M6, `002_login_attempts.sql`) | throttle_key PK (SHA-256 of lower-cased email + client IP), failures, locked_until (Unix seconds) |

Migrations: `001_schema.sql` runs once on an empty database together with the seed. Later
files are idempotent upgrades (`CREATE … IF NOT EXISTS`); `Initializer` applies them inside
the same `BEGIN IMMEDIATE` guard when a database already holding products lacks a required
table, preserving all inventory, orders and the administrator.

Indexes: `products(slug)`, `products(category,is_active)`, `orders(order_number)`, `orders(status,created_at)`, `order_items(order_id)`.

**Order number:** `H-YYYYMMDD-NNNN` — date + daily sequence allocated inside the serialized SQLite write transaction, protected by a unique constraint. Not unguessable; confirmation additionally requires session ownership.

## 6. Endpoints

| Method | Path | Purpose | Auth |
|---|---|---|---|
| GET | `/` | Home (R1) | – |
| GET | `/products?q=&category=&sort=&page=` | Listing + search/filter/sort (R2) | – |
| GET | `/product/{slug}` | Detail page (R3, R4–R6 viewer mount) | – |
| GET | `/api/product/{slug}/3d` | JSON 3D config for viewer (R6) | – |
| GET | `/api/product/{slug}/stock` | Live stock for qty cap (R9) | – |
| GET | `/cart` | Cart page (R8) | session |
| POST | `/cart/add` | Add item (R7) | session + CSRF |
| POST | `/cart/update` | Qty change / remove (R8, R9) | session + CSRF |
| POST | `/cart/remove` | Remove a line (R8); update with quantity zero also removes | session + CSRF |
| GET | `/checkout` | Checkout shell, step 1 shipping (R10) | session, cart non-empty |
| POST | `/checkout/shipping` | Validate + store shipping (R11, R12) | session + CSRF |
| GET | `/checkout/payment` | Mock card form (R13) | session, shipping done |
| POST | `/api/payment/simulate` | Fake gateway: Luhn + test cards + delay → result (R14) | session + CSRF |
| GET | `/checkout/review` | Review + confirm (R10) | session, payment validated |
| POST | `/checkout/place` | Create order + atomic stock decrement (R15, R16) | session + CSRF + idempotency key |
| GET | `/order/{orderNumber}` | Confirmation page (R16) | checkout session owner |
| GET/POST | `/admin/login`, POST `/admin/logout` | Admin auth (R19) | – |
| GET | `/admin` | Dashboard: low stock, recent orders (R18) | admin |
| GET/POST | `/admin/products…` | Product CRUD + image upload (R20, R23) | admin |
| POST | `/admin/products/{id}/stock` | Stock adjust + audit (R21, R18) | admin |
| GET | `/admin/orders…` | List/detail/status update, cancel restores stock (R22) | admin |

Implemented stock response: `{slug, stock, low_stock_threshold}` with `Cache-Control:
no-store`; missing/inactive products return JSON 404 `{error: "Product not found"}`.
Listing uses eight products per page and combines literal name/description/material search,
category and optional `hide_sold_out=1`; pagination preserves these values. Sort values are
`featured`, `newest`, `price_asc`, `price_desc`; featured uses stable catalog ID order and is
the default. Newest uses creation date and ID. Out-of-range pages clamp to available pages.

M2 config responses are the decoded per-product `three_config` object: `shape`,
centimeter `dimensions`, `colors`, `handle_style`, `material`, `lining`, and `pockets`,
plus optional `pattern` (`checker` | `stripes` | `pixel` surface descriptor matching the
product illustration) and optional `colors.handle`/`colors.handle_secondary`/`colors.binding`. Missing or
invalid `pattern` falls back to the `material` texture; admin edits keep these extra keys.
`three_config.dimensions` equals the listed product measurements (seeded and admin-saved
together). Tala is 30 × 22 × 8 cm (wide bucket) and Maya 26 × 16 × 3 cm (slim envelope),
matching their illustrations.
Peek preserves the physical shell and rim anchors; its elevated camera looks through
the opening. Tote handles fold outward about their fixed rim axis; side-anchored
satchel/sling handles stay put. Maya's connected flap rotates about the back-top hinge.
Pattern shells duplicate their back-centre UV seam with whole-circumference repeats;
vertical stripe dye stays solid beneath fine, low-contrast woven threads.
The endpoint uses `Cache-Control: no-store`; missing/inactive products return the same
JSON 404 as stock. Sold-out products remain inspectable. Detail gallery images are
ordered primary-first, then `sort_order` and ID; catalog queries do not load galleries
or model configs. M4 enables stock-validated purchase controls.

M5 moves POST CSRF enforcement into `CsrfMiddleware`, including future/unregistered POST paths; cart failures retain the authoritative fragment/recovery contract. `AdminAuthMiddleware` redirects `/admin` and descendants except `/admin/login` to sign-in (303). Templates use Twig auto-escape (N2).

M6 admin routes (all GET pages are `no-store`; every POST needs CSRF and, except sign-in, an administrator):

| Method | Path | Purpose |
|---|---|---|
| GET/POST | `/admin/login`; POST `/admin/logout` | Sign-in (PRG; 422 invalid, 429 locked) / sign-out |
| GET | `/admin`, `/admin/audit?product=&page=` | Dashboard; stock audit (50 per page, newest first) |
| GET | `/admin/products?q=&stock=`, `/new`, `/{id}/edit`, `/{id}/archive` | List plus server-rendered dialogs |
| POST | `/admin/products`, `/admin/products/{id}`, `/{id}/archive`, `/{id}/restore` | Create (multipart), update, archive, restore |
| GET | `/admin/inventory?q=&stock=`, `/admin/inventory/{id}/adjust`, `/{id}/review?change=&reason=&note=` | Adjust form and non-mutating preview |
| POST | `/admin/products/{id}/stock` | `expected_stock`, `change`, `reason`, `note`; applied only if stock still equals `expected_stock` |
| GET | `/admin/orders?status=&from=&to=&page=`, `/admin/orders/{number}`, `/{number}/cancel` | List (25 per page), detail, cancel confirmation |
| POST | `/admin/orders/{number}/status`, `/admin/orders/{number}/cancel` | One forward step (`status`); cancel |
| GET | `/uploads/{32-hex}.{jpg,png,webp}` | Public uploaded image with its MIME type |

Validation failures use POST/Redirect/GET with flashed values and field errors (never
passwords) shown once with 422. Dialog URLs carry the list's `q`/`stock` filters so closing
or saving returns to the same list.

## 7. Cart & Checkout Flow (state machine)

```
[Browse] ──add──▶ [cart] ──checkout──▶ [shipping] ──valid──▶ [payment]
[payment] ──simulate──▶ [result]
[result] ──approved──▶ [review] ──Place order──▶ [place] ──commit──▶ [confirmation]
[result] ──declined/insufficient──▶ [payment] (no order, stock unchanged)
[shipping] ──invalid──▶ [shipping] (inline errors)
[place] ──stock failure──▶ [cart] (rollback, notice, stock correction)
```

- Cart lives in `$_SESSION['cart']` = `{product_id: qty}`; every cart mutation re-validates
  against live stock and clamps (R9, R28).
- M4 reconciles live prices/stock on cart reads and server-rendered navigation as well as
  mutations. Deleted/inactive/sold-out lines are removed. Correction notices persist in
  the session rather than disappearing after a toast or a drawer fetch.
- `GET /cart` and mutations accept `Accept: application/json`, returning `cart`,
  `drawer_html`, `page_html`, `message` and `csrf_token`; `cart.count` sums quantities,
  `cart.subtotal_centavos` and line totals are integer centavos. Native POSTs use 303
  redirects to the full cart. Session-bearing pages/fragments use `Cache-Control: no-store`.
- Cart POST fields are `csrf_token`, `product_id`, `quantity` (except remove).
  Updates set absolute quantities; adds accumulate up to current stock. Quantity zero
  removes only on update; invalid requests do not apply the requested change.
- Session cookies have lifetime zero, HttpOnly and SameSite=Lax; no localStorage or
  persistent cart database exists. The cart explains that expiry loses its contents.
- Checkout is available from both the bag page and drawer. Expired/empty sessions return to the bag with an actionable explanation.
- `$_SESSION['checkout']` holds validated `shipping`, an approved non-sensitive `payment` result, reviewed `lines` and their `fingerprint`, and the preissued `idempotency_key`. Quantity mutations revoke approval/key while retaining shipping. Shipping edits require a new simulation; price changes cannot silently alter the reviewed order.
- Review requires shipping and approval for the exact current snapshot. Place uses the reviewed quantities without first clamping: live stock/active/price changes fail and roll back, then the bag is reconciled with an explanatory notice.
- The key is generated before the Place form renders and survives transient retry. Committed keys/numbers remain in session `completed_orders`; repeats return that order before inspecting any new bag. This map also grants order-page access; predictable numbers alone never grant access.
- Invalid shipping and invalid/declined payment use POST/Redirect/GET: only safe shipping redisplay values or field-error/message metadata are flashed, never PAN/expiry/CVC. The redirected GET displays inline errors (422). This prevents Chromium's no-store POST Back-history cache miss; subsequent Back returns a cleared card form.
## 8. Inventory Concurrency (R15, R17, R18)

Pseudocode for `OrderService::placeOrder()` (algorithm, not executable PHP):

```
Require approved simulation result and valid shipping in the checkout session.
Use the submitted idempotency key issued before rendering the Place order form.
Begin a write transaction: SQLite BEGIN IMMEDIATE; MySQL beginTransaction().
Try:
    Find an order with this idempotency key using a prepared query.
    If already placed, finish the transaction and return that same order.
    For each item:
        Fetch current stock using a prepared SELECT (FOR UPDATE only on MySQL).
        Reject insufficient stock.
        Execute prepared UPDATE products SET stock = stock - qty
            WHERE id = product_id AND stock >= qty.
        Require exactly one affected row.
        Insert the stock_audit entry using a prepared statement.
    Insert the paid order with the same idempotency key and unique order number.
    Insert order_items snapshots.
    Commit the transaction and return the order.
On failure:
    Roll back; no order or stock decrement from this attempt survives.
    Return the appropriate error; retries retain the same idempotency key.
```

- Guarded `UPDATE … WHERE stock >= ?` + affected-rows check = no oversell even under
  concurrent requests.
- Admin cancel (R22) runs the reverse in one `BEGIN IMMEDIATE` transaction, only from `paid`
  or `packed`: each item's stock is restored with a guarded update and a `stock_audit` row
  (reason `order_cancelled: <order number>`, acting admin), then the status changes with a
  guarded `WHERE status = ?`. Any failure rolls everything back; a repeat cancel is rejected.
- Admin adjustments (R21) are previewed, then applied in `BEGIN IMMEDIATE` only when stock
  still equals the reviewed `expected_stock`; otherwise the administrator re-reviews the new
  numbers. Results below zero are rejected. Reasons: Restock received, Damaged in storage,
  Count correction, Returned item (+ optional note). New products audit `initial_stock`.
  Fulfilment moves `paid → packed → shipped → delivered` one step at a time.
- SQLite tuning: `PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;`.

## 9. Simulated Payment (R13, R14, N3, N4)

`PaymentSimulator` (M5):

1. Validate: Luhn(card) + expiry future + CVC format. Invalid → `invalid` (stays on form).
2. Test-card table: `4242…4242` → `approved`; `4000 0000 0000 0002` → `declined`;
   `4000 0000 0000 9995` → `insufficient_funds`; any other Luhn-valid → `approved`.
3. Simulated latency 1.5–2.5 s (server-side `usleep`), spinner in UI.
4. **Card number is never persisted** — only non-sensitive result (approved/declined/insufficient_funds,
   `payment_last4` + brand) stored in checkout session (N4); expiry and CVC are discarded too. The payment page and confirmation page
   show the banner: "SIMULATED PAYMENT — no real transaction occurs" (N3). Native forms work without JavaScript; `checkout.js` adds a processing status/spinner without persisting input.
5. **Enforcement:** Review step requires approved payment result in session; Place requires approved
   payment and generates idempotency key before showing the form.
## 10. Security Design (N2, N4)

- SQL: PDO prepared statements exclusively; no string interpolation into SQL.
- XSS: Twig auto-escape on; `|raw` forbidden in product/admin content (admin-entered HTML is
  plain text or sanitized allowlist if rich text is added later).
- CSRF: per-session token, hidden field on every POST, `hash_equals` check, rotated on admin sign-in and sign-out.
- Sessions: strict mode, cookie-only IDs, no URL propagation, custom cookie name, lifetime zero, `HttpOnly`, `SameSite=Lax`. Secure on HTTPS or `APP_ENV=production` (including TLS-terminating deployments). Admin sign-in and sign-out call `session_regenerate_id(true)`.
- Passwords: `password_hash(PASSWORD_DEFAULT)` / `password_verify`; unknown emails are verified
  against a fixed dummy hash so timing and the message match; hashes are rehashed when needed.
- Admin throttle (`login_attempts`, SQLite): the 5th consecutive failure per email+IP
  (`REMOTE_ADDR`; proxy headers are not trusted) locks that key for 60 s; during the lock even a
  correct password is not checked. Generic "Invalid email or password." for every failure;
  the lockout message names only the wait. Success clears the key. Behind one shared proxy IP
  the key is effectively per email.
- Uploads (R23): type detected from bytes (`getimagesizefromstring`), allowlist JPEG/PNG/WebP,
  ≤2 MB and ≤6000 px, random 32-hex name with the detected extension, stored in `data/uploads/`
  outside the document root, served only by `/uploads/{name}` with the mapped MIME and `nosniff`.
- Secrets: `.env` (gitignored) → `APP_ENV`, `DB_DSN`, `ADMIN_SEED_*`, optional `APP_URL` (absolute public base URL for canonical/Open Graph links; derived from the request scheme/host when blank). `.env.example` committed. Native PHP sessions need no application secret. Hosted deployments set the same names as platform variables; the image never contains `.env`.
- Headers: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, basic CSP
  (`default-src 'self'`; inline styles allowed for Twig; no external scripts except fonts).

## 11. 3D Subsystem Design (R4, R5, R6, R27)

- **Viewer:** `public/assets/js/viewer3d.js` — a class `BagViewer(container, config)`.
  - Scene: warm studio lighting (key + fill + rim), soft shadow ground plane, neutral backdrop
    matching DSD surface color.
  - **Procedural bag builder** (`bagFactory.js`): builds meshes from `three_config` JSON —
    body (extruded/lathe geometry by `shape`: tote/satchel/sling/clutch), lid/flap where applicable,
    handle(s), zipper/pull and feet. Each shape has an **interior group** with generated lining
    and configured pockets; a divider is built only for `divider_pocket`, and empty pocket lists stay empty.
    Materials: MeshStandardMaterial with roughness/metalness per `material` (canvas/leather/rattan);
    all materials support generated weave/textile patterns via canvas texture, not only rattan.
  - **Rotate (R4):** OrbitControls with damping + keyboard orbit; auto-rotate 1.5°/s when idle >3 s,
    pause/resume on user interaction; respects `prefers-reduced-motion` (DSD §4).
  - **Peek inside (R5; M3):** `setInside(inside, {animate})` changes `isInside` and emits
    `viewchange` immediately. A horizontal local clipping plane removes the upper shell/lining
    at 68% of body height, revealing the cavity from every orbit direction. Pocket, flap and
    handle materials are separate and unclipped. Broad lining panels are inset from shell caps.
    Satchel/clutch flaps hinge at the rear rim and fold back by π; totes/slings have no flap.
    Camera/target/flap transitions retarget from the current pose using DSD's 700 ms
    `--duration-viewer` and `--ease-weave`. Keyboard and reduced-motion toggles snap immediately;
    enabling reduced motion during a transition completes it immediately.
    Back outside restores the saved exterior camera/target, closes the flap exactly and
    clears clipping. Reset restores the current mode's framing; inside manual orbit/tilt/zoom
    remain available, but idle rotation is suspended. Resizing preserves orbit/zoom and
    scales the saved exterior framing; hidden views suspend the animation loop.
  - **Fallback (R27):** renderer creation failure swaps in an original static image + note.
  - Config served by `GET /api/product/{slug}/3d` (R6); viewer degrades gracefully on fetch error.
- Performance (N1): single renderer per page, `antialias` on, pixelRatio capped at 2,
  `powerPreference: 'high-performance'`, dispose on pagehide and reconstruct on bfcache restore;
  repeated handles/feet share geometry within each model. The vendored r160 module is served
  through `three.module.php?v=0.160.1`, which negotiates zlib compression, uses immutable
  caching and varies by Accept-Encoding. Increase the query version when upgrading.
  Config/module loading has a ten-second failure deadline; config retry refetches,
  while a module-load failure retries by reloading the document (clearing the module map).
  One renderer is reparented between inline and native mobile-dialog slots.

## 12. Deployment Design (N6; BUILD M8)

- **Dockerfile:** `FROM php:8.2-cli` (production `php.ini`) → Composer → `composer install --no-dev`
  → copy runtime sources → `EXPOSE 8080` → `CMD php -S 0.0.0.0:$PORT -t public public/index.php`
  (`PORT` default 8080, `PHP_CLI_SERVER_WORKERS=4` so the 1.5–2.5 s payment simulation does not
  block other visitors). The image defaults `APP_ENV=production` and
  `DB_DSN=sqlite:/app/data/app.sqlite`. `.dockerignore` is an allowlist (composer files, `public/`,
  `src/`, `views/`, `migrations/`, `seed/`): `.env`, local data/uploads, `vendor/` and `.git` stay out.
- **Router script:** `public/index.php` is passed as the built-in server's router. Without one, the
  PHP 8.2 server treats any dotted path as a static file and answers 404 before the application runs
  (php-src #12604), which broke `/uploads/{name}.png`. Real files under `public/` are handed back to
  the server; every other path, including dotted ones, goes through Slim. Dev uses the same command (N6).
- **Google Cloud (production):** one Always Free `e2-micro` (us-west1/us-central1/us-east1), Debian 13,
  30 GB `pd-standard` boot disk, static external IPv4 (billed ≈ $3.65/month; not in Always Free).
  `deploy/gce/compose.yaml` runs the image with `/srv/habi/data` bind-mounted at `/app/data`, plus
  Caddy for HTTP→HTTPS, automatic Let's Encrypt certificates for the configured domain, and zstd/gzip
  of static files. Settings live in `/srv/habi/habi.env` (seed variables, `APP_URL`) and `caddy.env`
  (`HABI_DOMAIN`), mode 600; Compose reads them with `format: raw` so bcrypt `$` are kept. `setup.sh`
  (idempotent; never overwrites settings or data) installs Docker, 1 GB swap, the `deploy` user and
  the first deploy. `update.sh` checks out a commit, rebuilds, restarts and waits for `/` to be healthy.
  Railway was dropped (D6; its `railway.json` Config as Code is deprecated). Steps: README "Deploy".
- **Render (alternative):** `render.yaml` (Docker web service, paid plan, persistent disk at `/app/data`,
  health check `/`, `autoDeployTrigger: checksPass`, seed variables prompted, never committed).
- **Boot:** `public/index.php` checks for the complete initial schema. On an empty database,
  `Bootstrap/Initializer` acquires a SQLite write transaction, rechecks, migrates and seeds.
  Missing admin credentials roll the initialization back; later boots preserve inventory/admin.
  Credentials come from `ADMIN_SEED_EMAIL` / `ADMIN_SEED_PASSWORD_HASH` (and optional name).
- **HTTPS:** Caddy on the VM (Render: platform proxy) terminates TLS and forwards `X-Forwarded-Proto`;
  `APP_ENV=production` keeps session cookies Secure. Health check: `GET /` → 200. Behind the proxy
  every request's `REMOTE_ADDR` is the proxy, so the admin throttle key is effectively per email.
- **CI (GitHub Actions, `.github/workflows/ci.yml`):** on pull requests and pushes to `main` —
  `composer validate --strict`, `composer lint` (`php -l`), `composer cs` (PSR-12), PHPUnit, Docker
  build, then boot on an empty volume, check static/dotted routing and a restart preserving data.
  Deploy: on pushes to `main` that pass both jobs, the `deploy` job SSHes to the VM as `deploy` with a
  key whose `authorized_keys` forced command is `update.sh`; it accepts only a 40-hex SHA already on
  `main` (or nothing → `origin/main`). Host key pinned via `GCE_KNOWN_HOSTS`; then `https://<host>/`
  must return 2xx. The job is skipped until the `GCE_HOST` repository variable is set.

## 13. Testing Strategy

- **Unit (PHPUnit):** `PaymentSimulator` validation/outcomes, `OrderService::placeOrder`
  rollback/idempotency/snapshots, shipping fee/validation boundaries, checkout prerequisite
  and session-isolation transitions, and `CartService` clamping (R9).
- **Consumer smoke (BUILD M5):** actual checkout with each test card, stock/price conflict,
  injected database failure/retry, session-owned confirmation, mobile and keyboard/no-JS.
  Admin service boundaries (M6): throttle/lockout expiry, stale/negative adjustments and audit
  rows, fulfilment/cancel transitions and atomic cancel rollback, peso parsing, uploads by
  content, pre-M6 database upgrade. Admin HTTP/browser acceptance is recorded in BUILD M6.
  Hosted/browser-family certification remains M7/M8; container acceptance is recorded in BUILD M8.
- **Concurrency:** script that fires N parallel `place` requests for the last item → exactly
  one succeeds (§8).

## 14. SQLite → MySQL Migration Path

All SQL goes through `Support/db()` PDO with DSN from `DB_DSN`. Schema written in portable SQL;
adapt transaction dialect (BEGIN IMMEDIATE vs SELECT … FOR UPDATE) and verify services during migration.
SQLite remains the current target; no migration implementation requested.

---

> **Implementation boundary:** M0 supplies the PHP/Twig shell, environment/PDO bootstrap,
> initial SQLite schema and fictional seed catalog. M1 adds R1/R2 craft discovery and
> catalog, active product lookup and stock JSON. M2 adds ordered gallery inspection,
> per-product config JSON and four procedural exterior shapes with accessible controls,
> reduced motion and static fallback. Runtime evidence belongs in BUILD. Supplied images
> remain inspiration/modeling references only, never publication assets or proof of
> provenance. Original SVG illustrations are the seed image format. M3 adds reversible
> interior cutaways and configured pockets. M4 adds the session cart and real R3/R7
> purchase actions; M5 adds simulated guest checkout and atomic paid orders; M6 adds the
> administrator panel (sign-in/throttle, product CRUD/uploads, audited stock, order
> fulfilment/cancel). M8 adds the Docker image, router-script start command, the Google Cloud VM
> stack (Caddy, SSH deploy), Render blueprint and CI; hosted acceptance is tracked in BUILD M8.
> Review/merge status is tracked separately in BUILD.
> PRD R1–R28, N1–N8 remain the target traceability baseline.
