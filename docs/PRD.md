# PRD — Habi: Handmade Filipino Handbags Educational E-Commerce

> **Document status:** Approved requirements baseline. Requirements below describe target behavior, not blanket shipped claims. Current implemented scope is recorded in [README](../README.md) and [BUILD](BUILD.md).

| | |
|---|---|
| Course | COMP-20163 Web Development |
| Version | 1.1 |
| Date | 2026-11-09 |
| Status | Planning approved |
| Companion docs | [SDD.md](SDD.md) · [DSD.md](DSD.md) · [BUILD.md](BUILD.md) · [USER-JOURNEY.md](USER-JOURNEY.md) |

> Traceability convention: requirements below carry IDs **R1–R28** and **N1–N8**.
> SDD, DSD, BUILD, and USER-JOURNEY cite these IDs instead of restating requirements.

---

## 1. Overview & Vision

Habi is an **educational** e-commerce demonstration: a storefront showcasing handcrafted handbags with a REAL SQLite demo inventory (stock is real in the DB; the goods are fictional). The site lets visitors explore a curated catalog, inspect each bag in an interactive 3D viewer (rotate it, then peek inside), and complete a full simulated checkout. An admin panel manages products, stock, and orders.

**Vision statement:** make every bag feel tangible online — as if the customer is holding it — and make the exploration and simulated buying experience feel as smooth as buying from any modern DTC brand.


## 2. Brand

- **Name:** `Habi`
- **Positioning:** contemporary Filipino craft. Earthy, warm, craft-forward — woven structure
  carries the identity and product imagery sits on quiet neutral grounds (full spec in [DSD.md](DSD.md)).
- **Origin story surface:** product pages use neutral illustrative material/origin labels (e.g., "illustrative sample" or "unknown origin if unverified"); these are **demonstration labels only** and do not assert actual provenance or ownership.

## 3. Target Users

| Persona | Goal | Detail |
|---|---|---|
| Urban professional buyer | Find a distinctive, quality everyday bag | Values craft story + 3D inspection before buying (USER-JOURNEY P1); *planning example, not researched demographics* |
| Gift buyer / tourist | Buy a meaningful Philippine-inspired gift | Needs clear pricing in PHP (₱), simple checkout, order confirmation; *planning example, not researched demographics* |
| Shop owner (admin) | Keep catalog, stock, and orders under control | Admin panel without technical knowledge (USER-JOURNEY P3); *planning example, not researched demographics* |

## 4. Scope

### In scope
- Storefront: home, catalog listing with search/filter/sort, product detail pages.
- Interactive 3D product viewer: rotate + "peek inside" (procedural models, R4–R6).
- Cart (persisted in session), guest checkout, shipping form, **simulated** payment,
  order confirmation with order number (H- prefix).
- REAL SQLite demo inventory: stock decrements atomically on successful (simulated) payment; goods are fictional.
- Admin panel: login, product CRUD, stock management, order list + status.
- Web deployment (Railway/Render via Docker) — see SDD §12, BUILD M8.

### Out of scope
- Real payment gateway integration, real shipping carrier integration, real email/SMS.
- Customer accounts, wishlists, reviews (may be v2).
- Multi-currency, multi-language.
- Native mobile apps.

## 5. Functional Requirements

### 5.1 Catalog & discovery
- **R1** Home page with short, skippable craft-first intro; free material/bag exploration section; optional shopping path. Hero, featured products, and expressive craft sections with quiet inspection focus.
- **R2** Catalog page: grid of active products with search, category filter, featured/newest/price sorting, optional Hide sold-out bags, and eight-item pagination. Show price and icon+text availability per item. Match the published catalog component and responsive card layout (DSD §4).
- **R3** Product detail page: gallery images, name, price (₱), description, materials / place-of-make, dimensions, stock indicator, quantity selector, Add-to-Cart, and the 3D viewer.

### 5.2 3D viewer
- **R4** Every product page embeds a 3D viewer; the bag rotates via drag (orbit controls)
  with inertia, plus auto-rotate when idle.
- **R5** "Peek inside" mode: lid/flap opens where applicable (open totes supported), camera moves inside, interior (lining, pockets) is visible; user can still orbit inside. Toggle back to exterior.
- **R6** 3D models are procedural, parameterized per product (shape, dimensions, colors, handle style, lining, pockets) via a per-product JSON config served by the backend. **Supplied images are inspiration and procedural-3D modeling references only; they are NOT publication assets and do not establish Habi ownership or provenance. Demo does not confer rights; original generated imagery/textures and procedural models remain part of the plan.**
- **R27** If WebGL is unavailable, the viewer falls back to a static product image with a note.

### 5.3 Cart
- **R7** Add-to-cart from listing or detail page; toast confirmation + cart badge count.
- **R8** Cart page/drawer: line items with image, qty stepper, remove, subtotal, shipping
  estimate, "Proceed to checkout".
- **R9** Quantity is capped at available stock; if stock drops below cart qty (e.g., another
  buyer purchased), cart is corrected with an explanatory notice.
- **R28** Cart persists across page loads (server session) for the session lifetime.

### 5.4 Checkout & simulated payment
- **R10** Guest checkout (no account required): steps Shipping → Payment → Review → Confirmation. Order numbers use prefix **H-YYYYMMDD-NNNN** (this format prevails over the shorter sample numbers shown in the approved reference's prototype screens).
- **R11** Shipping form: name, phone, email, address, region (select), with server-side validation and inline errors.
- **R12** Shipping fee computed from region (Metro Manila ₱120, Luzon ₱180, Visayas ₱220,
  Mindanao ₱250; free above ₱5,000 subtotal). Total shown before payment. These rates and the
  free threshold are binding; the approved visual reference's sample cart figures
  (₱150/₱220/₱280/₱320) are prototype content, not requirements.
- **R13** Payment step is a **mock card form**: card number validated with Luhn, expiry
  MM/YY future-dated, CVC 3–4 digits. Clearly labeled "SIMULATED — no real payment is processed."
- **R14** Fake gateway: "processing" state (spinner, 1.5–2.5 s), then success or decline. Test cards: `4242 4242 4242 4242` → success; `4000 0000 0000 0002` → card declined; `4000 0000 0000 9995` → insufficient funds. Any other Luhn-valid number → success. **Declined payments do not create orders; no order on decline.**
- **R15** On Place (after successful simulation and review), an order is created and stock is decremented **atomically** (DB transaction; rollback + friendly error on failure or insufficient stock at that moment).
- **R16** Confirmation page: order number (H- prefix), summary, shipping address, estimated delivery window; "order received" state. Failed payments show the reason and keep the cart intact.
- **R17** Products at 0 stock cannot be added to cart ("Sold out"); low stock (≤3) shows
  "Only N left".

### 5.5 Inventory & orders (admin-visible truth)
- **R18** Admin dashboard shows stock levels and low-stock alerts; stock changes are logged
  (audit: product, old→new, reason, timestamp, admin).

### 5.6 Admin panel (`/admin`, login required)
- **R19** Admin login (email + password, `password_hash`/`password_verify`, generic error,
  simple attempt throttling, session-based). Logout.
- **R20** Product CRUD: create/edit/deactivate products incl. price (centavos), category, description, materials, place-of-make, dimensions, stock, 3D config, images.
- **R21** Stock management: adjust stock with reason; audit log view.
- **R22** Orders list: filter by status/date, order detail, update status
  (paid → packed → shipped → delivered / cancelled). Cancel restores stock.
- **R23** Product images: admin uploads images (validated type/size) or products use seeded demo images.

### 5.7 Cross-cutting
- **R24** Fully responsive (mobile-first; usable at 360 px).
- **R25** Accessibility basics: WCAG AA contrast, keyboard-navigable viewer and forms,
  focus states, aria labels, `prefers-reduced-motion` respected.
- **R26** SEO basics: semantic HTML, per-page title/meta description, Open Graph tags, favicon.

## 6. Non-Functional Requirements

- **N1** Performance: LCP < 3 s on 4G; 3D viewer initializes < 2 s after product page load.
- **N2** Security: PDO prepared statements everywhere; all output escaped (XSS); CSRF tokens
  on every POST; `HttpOnly`/`SameSite` session cookies; admin routes behind auth check;
  no secrets in repo (`.env`, `.env.example` only).
- **N3** Payment simulation must never call a real gateway and must display the simulation
  disclaimer on the payment step and confirmation page.
- **N4** Privacy: store only order-necessary data; card numbers are **never stored** (only last4 + brand for display, or nothing at all — see SDD §9-10).
- **N5** Browser support: last 2 versions of Chrome, Firefox, Safari, Edge.
- **N6** Runs identically via `php -S localhost:8000 -t public public/index.php` (dev) and in the production Docker image (Railway/Render — see SDD §12).
- **N7** Data: seeded demo catalog of **10 products** across ≥4 categories; prices in PHP pesos. **Demo origin labels are not factual.**
- **N8** Code quality: PSR-12, `php -l` clean, Composer autoload (PSR-4), no committed vendor dir.

## 7. Success Metrics

| Metric | Target |
|---|---|
| End-to-end purchase flow completion (simulated) | Works for all 3 test cards + out-of-stock path; timing targets are goals, not constraints on exploration |
| Stock accuracy | 0 oversell events across concurrent checkouts to verify |
| Page performance | LCP < 3 s on throttled 4G (Lighthouse) |
| Admin task time | Add product < 2 min; update stock < 30 s |
| Accessibility | Lighthouse a11y score ≥ 90; keyboard-only checkout possible |
| Deploy | Live URL on Railway/Render, HTTPS, SQLite persisted across restarts |

## 8. Constraints

- Backend language **must be PHP** (course requirement); Composer allowed.
- Checkout/payment is **simulated only** (N3).
- Must be deployable to the web (Railway or Render via Docker) — target chosen in SDD §12.
- Team of 4; build phased by feature, no fixed external deadline (BUILD.md).

## 9. Open Items / Placeholders

- **Fonts and assets:** resolved — fonts, colors and SVG artwork come from the approved published reference, implemented in `app.css`, with sources/licenses in DSD §6 (no open item recorded there). M7 checked published content and raster assets (BUILD M7). New assets need the same DSD §6 record and review-screen check.
- **Product imagery:** published-reference SVG illustrations replace the previous poster-style seed artwork; original procedural exterior models/textures are M2 and lining/configured pockets are M3. Asset sources and licenses are recorded in DSD §6. **Supplied reference photographs remain inspiration/modeling references only, NOT publication assets or proof of Habi ownership/provenance.** Local R5 verification and outstanding DoD requirements belong in BUILD.
- **Province list:** scheduled for v2 (post-course backlog) — v1 keeps the simplified region-level shipping map (R12).
- **Deployment:** resolved — Railway preferred with a volume at `/app/data`; Render documented as the alternative (SDD §12). Dev and the image share `php -S … -t public public/index.php` (N6). Privacy references SDD §9-10. Hosted acceptance status is recorded in BUILD M8.

### Prototype vs requirements

The approved visual reference is a design specification, not a requirements source. Where its
prototype screens and this PRD disagree, the PRD prevails:

- **Shipping fees:** the reference's sample figures do not replace R12's rates or free-shipping threshold.
- **Order numbers:** the reference's shortened examples do not replace R10's required format.
- **Stock handling:** the reference replaces the sold-out button; R17 requires only that
  0-stock products cannot be added, and R9 clamping plus R15 atomic decrement are binding.
  M4 enables real purchase controls; preserve the published visual layout while explaining actual application state and the unavailable M5 checkout (DSD §4).

## 10. Glossary

- **Simulated payment / fake gateway:** a UI-only card flow that mimics authorization; no real transaction occurs (R13–R14, N3). **Demo does not confer rights; all payment flows are simulated.**
- **Stock decrement:** atomic reduction of `products.stock` when an order reaches `paid` (R15), planned for M5.
- **3D config:** per-product JSON describing the procedural bag model (R6). Seed configuration is M0 scope; exterior viewer/models/textures are M2, and M3 consumes lining/pocket configuration for reversible interior inspection.
