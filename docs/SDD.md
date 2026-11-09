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
(Railway/Render). Checkout payment is simulated by design (N3).
**Direction:** craft-first short skippable intro → free material/bag exploration → optional shopping (DSD §7).

## 2. Technology Stack

| Layer | Choice | Why |
|---|---|---|
| Language | **PHP 8.2+** | Course requirement; mature, hostable anywhere |
| Package manager | **Composer** (PSR-4 autoload, PSR-12) | Allowed; needed for Slim/Twig |
| Framework | **Slim 4** (micro-framework) + nyholm/psr7 | Tiny, explicit routing; no framework lock-in |
| Templating | **Twig 3** | Auto-escaping kills most XSS (N2); clean separation |
| Database | **PDO + SQLite** (dev & prod default), MySQL-compatible schema for easy migration | Zero-setup dev (`php -S`), persists on a Railway volume; DSN switch via env (§12) |
| 3D | **Three.js** (vendored ES module in `public/assets/vendor/three/`), vanilla JS modules | Procedural woven-fiber/textile-inspired bags (R4–R6, DSD §§8–9), no external model files, works offline |
| Frontend | Server-rendered Twig pages + small vanilla JS module per page; no SPA build step | Fast LCP (N1), simple deploy, course-friendly |
| Sessions | Native PHP sessions, `HttpOnly`, `SameSite=Lax`, regenerated on admin login (N2) | Cart persistence (R28), admin auth (R19) |
| Container | **Docker** (`php:8.2-cli` + Composer + `php -S 0.0.0.0:$PORT -t public`) | Identical runtime locally and on Railway/Render (N6) |
| Tests | PHPUnit for services; manual smoke checklist for flows | See §13 |
| CI | GitHub Actions: `composer validate`, `php -l` on src, PHPUnit | See BUILD M8 |

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
SQLite file  data/app.sqlite   (on Railway: mounted volume; WAL mode)
   ▲
   └── migrations/ + seed/  (schema + 10 products, R7/N7)

Static: public/assets/{css,js,img,vendor/three}
Admin: same app, routes under /admin behind AdminAuthMiddleware (R19)
```

- **Stateless-ish:** all mutable state in SQLite + PHP session. Cart lives in session only (R28);
  no saved cart after session expiry. No in-memory carts.
- **API-ish JSON endpoints** under `/api/…` serve the 3D config (R6); `/cart` and cart mutations return
  badge counts (server-rendered) so JS modules stay decoupled from HTML.

## 4. Directory Structure

```
├── public/                 # docroot (php -S -t public)
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
├── seed/                   # seed.php → 10 products w/ images + 3D configs (N7)
├── tests/                  # PHPUnit: services (OrderService stock atomicity, PaymentSimulator Luhn)
├── docs/                   # PRD, SDD, DSD, BUILD, USER-JOURNEY
├── Dockerfile  railway.json  render.yaml  .env.example  composer.json  README.md
```

M1 implements home, catalog and stock JSON through `Controllers/StorefrontController`
and `Services/ProductService`, registered in `public/index.php`. M2 extends detail with
ordered gallery images and adds the active-only procedural config endpoint.
`src/Bootstrap/` handles initialization/catalog seeding, `src/Support/` environment/PDO
helpers, and `src/Console/` the explicit reset command. Commerce/admin remain later milestones.

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

Indexes: `products(slug)`, `products(category,is_active)`, `orders(order_number)`, `orders(status,created_at)`, `order_items(order_id)`.

**Order number:** `H-YYYYMMDD-NNNN` — date + daily sequence from `orders` count; unique constraint + retry loop. Not unguessable.

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
M1 listing uses six products per page, combines literal name/description/material search
with category, and preserves filters in pagination. Supported sort values are `newest`,
`price_asc`, `price_desc`; unknown sort defaults to newest. Creation date and ID provide
deterministic newest order. Out-of-range pages clamp to available pages.

M2 config responses are the decoded per-product `three_config` object: `shape`,
centimeter `dimensions`, `colors`, `handle_style`, `material`, `lining`, and `pockets`.
The endpoint uses `Cache-Control: no-store`; missing/inactive products return the same
JSON 404 as stock. Sold-out products remain inspectable. Detail gallery images are
ordered primary-first, then `sort_order` and ID; catalog queries do not load galleries
or model configs. Purchase behavior remains M4.

All POST routes pass `CsrfMiddleware`; all templates use Twig auto-escape (N2).

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
- Checkout progress in `$_SESSION['checkout']` = `{shipping:…, payment_result: approved|declined|…}`;
  steps enforced server-side (can't reach review without approved payment — R10).
- Idempotency key generated before Place form shown; Place creates order + atomic stock decrement (R15, R16).
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
- Admin cancel (R22) runs the reverse in a transaction and writes `stock_audit` reason `order_cancelled`.
- SQLite tuning: `PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;`.

## 9. Simulated Payment (R13, R14, N3, N4)

`PaymentSimulator` (planned, not unit-tested):

1. Validate: Luhn(card) + expiry future + CVC format. Invalid → `invalid` (stays on form).
2. Test-card table: `4242…4242` → `approved`; `4000 0000 0000 0002` → `declined`;
   `4000 0000 0000 9995` → `insufficient_funds`; any other Luhn-valid → `approved`.
3. Simulated latency 1.5–2.5 s (server-side `usleep`), spinner in UI.
4. **Card number is never persisted** — only non-sensitive result (approved/declined/insufficient_funds,
   `payment_last4` + brand) stored in checkout session (N4). The payment page and confirmation page
   show the banner: "SIMULATED PAYMENT — no real transaction occurs" (N3).
5. **Enforcement:** Review step requires approved payment result in session; Place requires approved
   payment and generates idempotency key before showing the form.
## 10. Security Design (N2, N4)

- SQL: PDO prepared statements exclusively; no string interpolation into SQL.
- XSS: Twig auto-escape on; `|raw` forbidden in product/admin content (admin-entered HTML is
  plain text or sanitized allowlist if rich text is added later).
- CSRF: per-session token, hidden field on every POST, `hash_equals` check, regenerate on login.
- Sessions: `session.cookie_httponly=1`, `SameSite=Lax`, `session_regenerate_id(true)` on admin
  login; session cookie name custom.
- Passwords: `password_hash(PASSWORD_DEFAULT)` / `password_verify`.
- Admin throttle: after 5 failed attempts, 60 s lockout per email+IP (in-memory/SQLite table),
  generic "Invalid credentials" error, no user enumeration.
- Uploads (R23): allowlist `jpg/png/webp`, ≤2 MB, random filename, stored outside executable
  paths, served with correct MIME.
- Secrets: `.env` (gitignored) → `APP_ENV`, `DB_DSN`, `SESSION_SECRET`, `ADMIN_SEED_*`. `.env.example` committed.
- Headers: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, basic CSP
  (`default-src 'self'`; inline styles allowed for Twig; no external scripts except fonts).

## 11. 3D Subsystem Design (R4, R5, R6, R27)

- **Viewer:** `public/assets/js/viewer3d.js` — a class `BagViewer(container, config)`.
  - Scene: warm studio lighting (key + fill + rim), soft shadow ground plane, neutral backdrop
    matching DSD surface color.
  - **Procedural bag builder** (`bagFactory.js`): builds meshes from `three_config` JSON —
    body (extruded/lathe geometry by `shape`: tote/satchel/sling/clutch), lid/flap where applicable,
    handle(s), zipper/pull and feet. The **interior group** (lining, pockets, divider) remains M3.
    Materials: MeshStandardMaterial with roughness/metalness per `material` (canvas/leather/rattan);
    all materials support generated weave/textile patterns via canvas texture, not only rattan.
  - **Rotate (R4):** OrbitControls with damping + keyboard orbit; auto-rotate 1.5°/s when idle >3 s,
    pause/resume on user interaction; respects `prefers-reduced-motion` (DSD §8).
  - **Peek inside (R5; M3, not implemented):** "Peek inside" button → tween lid open (rotation.x) + camera fly to
    front-above-interior, interior group visible, front wall set to `clip` via
    `renderer.localClippingEnabled` + clipping plane (cuts the front face so you see in).
    Toggle back reverses the tween.
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

- **Dockerfile:** `FROM php:8.2-cli` → install Composer → `composer install --no-dev` →
  copy app → `EXPOSE 8080` → `CMD php -S 0.0.0.0:$PORT -t public` (PORT env, default 8080).
  `.dockerignore`: `.git`, `data/*.sqlite`, `vendor` (rebuilt), `node_modules`.
- **Railway:** `railway.json` or Dockerfile autodetect + a **volume mounted at `/app/data`**
  so `app.sqlite` survives restarts/redeploys. Env vars: `APP_ENV=production`, `DB_DSN=sqlite:/app/data/app.sqlite`, `SESSION_SECRET` (Railway secret).
- **Render:** `render.yaml` (web service, Docker runtime, persistent disk at `/app/data`) —
  either platform works; Railway preferred, Render documented as alternative.
- **Boot:** `public/index.php` checks for the complete initial schema. On an empty database,
  `Bootstrap/Initializer` acquires a SQLite write transaction, rechecks, migrates and seeds.
  Missing admin credentials roll the initialization back; later boots preserve inventory/admin.
  Credentials come from `ADMIN_SEED_EMAIL` / `ADMIN_SEED_PASSWORD_HASH` (and optional name).
- **HTTPS:** provided by platform. Health check: `GET /` → 200.
- **CI (GitHub Actions):** on PR/push to main — `composer validate`, `php -l` over `src/ public/`,
  run PHPUnit, build Docker image (smoke). Deploy on merge to main (Railway/Render auto).

## 13. Testing Strategy

- **Unit (PHPUnit, planned):** `PaymentSimulator` (Luhn, test cards), `OrderService::placeOrder`
  (happy path, insufficient stock rollback, idempotency), `CartService` clamping (R9),
  money formatting.
- **Integration/smoke (manual checklist in BUILD M7/M8):** full journey per USER-JOURNEY J1 with
  each test card; out-of-stock path; admin CRUD; mobile 360 px; keyboard-only checkout (R25).
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
> provenance. Original SVG illustrations are the seed image format. Interiors (M3),
> commerce, admin and deployment remain later milestones; full R3/R7 is not claimed.
> PRD R1–R28, N1–N8 remain the target traceability baseline.
