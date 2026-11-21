# PRD — Habi: A Heritage-Driven E-Commerce Platform Connecting Filipino Native Weavers Directly to Consumers

> **Document status:** Approved requirements baseline, version 2.0. It follows the group's submitted proposal ("Habi: A Heritage-Driven E-Commerce Platform Connecting Filipino Native Weavers Directly to Consumers", Group No. 1 — BSIT 3-1N, submitted 2026-11-14; not stored in the repository). Requirements describe target behavior, not claims about shipped code. Each requirement carries a status tag: **[v1]** is implemented (M0–M8), **[v2-changed]** is implemented in v1 form but must change, **[v2-new]** is not implemented. Current implemented scope is recorded in [README](../README.md) and [BUILD](BUILD.md).

| | |
|---|---|
| Course | COMP-20163 Web Development (BSIT 3-1N) |
| Instructor | Mr. Renz Angelo Cadaoas |
| Team | Group 1 — Chacon, Mel Carl A. (leader); Aran, Justin Jhon T.; Astibe, Juliana Eunice A.; Baes, Franz Emmanuel F.; Nacubuan, Juan Miguel O. |
| Version | 2.0 |
| Date | 2026-11-21 |
| Status | Planning approved (proposal submitted 2026-11-14) |
| Prototype | <https://habi.theavenir.dev/> |
| Companion docs | [SDD.md](SDD.md) · [DSD.md](DSD.md) · [BUILD.md](BUILD.md) · [USER-JOURNEY.md](USER-JOURNEY.md) |

> Traceability convention: requirements carry IDs **R1–R44** and **N1–N12**. IDs R1–R28 and N1–N8 keep their v1 numbers so existing code, tests and docs stay traceable. SDD, DSD, BUILD and USER-JOURNEY cite these IDs instead of restating requirements.

---

## 1. Overview & Vision

Habi (Filipino for "to weave") is an e-commerce website that sells woven bags made by native Filipino weavers, not bags merely inspired by their designs. It connects weavers directly to consumers, without middlemen. Each bag is presented the way a museum presents an artifact: the listing tells the story of the piece, its fiber, its weaver and the weaver's community.

The project joins web development with cultural preservation and economic empowerment. It responds to four problems described in the proposal: weavers earn little; weavers have limited direct access to buyers; cheap imported fast fashion competes on price; and colonial mentality makes buyers see local craft as ordinary. A handwoven bag cannot compete on price with factory goods, so Habi helps it compete on meaning.

Habi is a course prototype. It is a simulation, not a live business (§8, §9). Its storefront still reads as a real shop (decision D7): the simulation is a documented backend fact, not UI copy.

**Vision statement:** make every bag feel tangible online, as if the customer were holding it, and make the story of the person who wove it impossible to miss.

### 1.1 Statement of the problem (from the proposal)

Filipino weaving communities produce skilled, culturally significant handmade bags, yet the craft is declining. There is no dedicated online platform that sells bags made by native weavers while telling the story behind each piece. The study asks:

1. What challenges do native weavers face in selling their products and sustaining their craft?
2. How do middlemen, fast fashion and colonial mentality limit the market for traditional Filipino woven bags?
3. How can an e-commerce website give weavers a direct platform to reach consumers?
4. How can museum-style product listings that tell each bag's story increase consumers' appreciation of Filipino craft?
5. How well does the website perform in usability, functionality and design, as judged by its intended users?

### 1.2 Objectives

**General objective:** design and develop Habi, an e-commerce website that sells bags made by native Filipino weavers, connects artisans directly to consumers, and promotes appreciation for traditional weaving as a counter to fast fashion and colonial mentality.

| # | Specific objective | Covered by |
|---|---|---|
| O1 | Identify the challenges that native weavers face in marketing and selling their products | Research (proposal background); informs R29–R31 content |
| O2 | Build an online storefront that lets weavers list and sell their bags directly, without middlemen | R34–R39, R40 |
| O3 | Create museum-style product pages that present each bag's story: weaver, materials, community and weaving tradition | R3, R29, R30 |
| O4 | Include core e-commerce features (catalog, cart, checkout, order management) | R1–R28, R32, R44 |
| O5 | Encourage consumers to value locally made, handwoven goods over mass-produced imports | R1, R29–R31, R33 |
| O6 | Evaluate usability, functionality and design with intended users, and refine the site from their feedback | §7, BUILD M15 |

## 2. Brand

- **Name:** `Habi` ("to weave").
- **Positioning:** heritage-driven, contemporary Filipino craft. Earthy, warm and craft-forward. Woven structure carries the identity; product imagery sits on quiet neutral grounds (full spec in [DSD.md](DSD.md)).
- **Voice:** the bag is an artifact with a maker. Copy names the weaver, community, fiber and technique as data. No filler, eyebrows or demo notices (D7).
- **Content truthfulness:** all weaver profiles, product stories, prices and stock are **sample content** composed from secondary sources (published articles and reports). They do not represent actual weavers, products or transactions. Sample weavers must not use the names, photographs or likeness of real people (§8). This is a documentation and content rule, not UI copy (D7).

## 3. Target Users

| Persona | Goal | Detail |
|---|---|---|
| Customer — urban professional buyer | Find a distinctive, meaningful everyday bag | Values the maker's story and 3D inspection before buying (USER-JOURNEY P1); *planning example, not researched demographics* |
| Customer — gift buyer / tourist | Buy a meaningful Filipino gift | Needs clear ₱ pricing, simple guest checkout, payment choice and an order reference to track (USER-JOURNEY P2); *planning example* |
| Weaver | List own bags, keep stock right, see orders and earnings | Basic web skills; uses a phone; account created by an admin (USER-JOURNEY P4); *sample role, no real weavers were recruited* |
| Admin / staff | Keep weavers, catalog, content and orders under control | Approves listings, confirms and processes orders, verifies payments, edits content (USER-JOURNEY P3); *planning example* |

These are also the evaluation respondent groups (§7), represented by classmates, instructors or volunteers (§9).

## 4. Scope

The product focus is woven bags in four categories (tote, satchel, sling, clutch) made from native fibers such as buri, pandan and tikog. The system has four modules.

### In scope

- **Customer module:** home story; catalog with search, filter (category, material, region) and sort; product details with museum-style story; 3D product view; Meet the Weavers; Heritage Journal; session cart; guest checkout with customer and delivery information; regional shipping fee; payment option selection; order confirmation; order tracking by reference number; English/Filipino language option.
- **Weaver module:** login, profile management, own product listing and stock management, viewing orders for own products, earnings summary.
- **Admin/staff module:** login, dashboard with order and sales summaries, weaver account management and listing approval, product and category management, product availability, order processing and status updates, payment verification, customer order records, content management (Heritage Journal and weaver profiles), stock audit.
- **Order management module:** reference number generation, automatic price and fee computation, timestamping, weaver item assignment, customer and delivery storage, payment method and status tracking, transaction records.
- **Inventory:** real SQLite stock for sample goods, decremented atomically when an order is placed (R15).
- **Web deployment:** Docker on a Google Cloud Compute Engine e2-micro behind Caddy HTTPS (Render alternative); see SDD §12, BUILD M8.

### Out of scope

- Real payment gateways, e-wallet or bank integrations, or collecting real money.
- Courier integration, live shipping rates and parcel tracking.
- Customer accounts, order history, saved addresses, wishlists.
- Returns and refunds, customer reviews and ratings, live chat.
- Automated email, SMS or push notifications.
- Weaver self-registration (admins create weaver accounts, R40) and weaver payouts.
- Verifying that a bag was truly handwoven by the listed weaver (approval is a simulated admin review).
- Regional languages such as Waray; currencies other than PHP.
- Native mobile apps.
- Woven goods other than bags, and non-woven crafts.

## 5. Functional Requirements

### 5.1 Catalog & discovery (customer module)

- **R1** [v1] Home page is one cinematic, sequential craft story (strand → weave → materials → bag) that ends at the catalog; every scene links forward to real products or the collection (DSD §4, D7). v2 scenes may also link to Meet the Weavers (R30) and the Heritage Journal (R31).
- **R2** [v2-changed] Catalog page: grid of active products with names, prices, images and icon+text availability. Search; filters by **category** (tote, satchel, sling, clutch, plus admin-managed categories, R41), **material** (primary fiber) and **region** of origin; sort by featured, newest, price ascending and price descending; optional Hide sold-out bags; eight-item pagination. Filters combine and pagination keeps them. Match the published catalog component and responsive card layout (DSD §4). *v1 has search, category, sort, hide sold-out and pagination; material and region filters are new.*
- **R3** [v2-changed] Product detail page: gallery images, name, price (₱), description, materials, place of make (community and region), dimensions, stock indicator, **shipping rates by region** (R12), quantity selector, Add to Cart, the museum-style story (R29) and the 3D viewer (R4). *Shipping rates and story are new.*
- **R29** [v2-new] **Museum-style product story:** each product page presents the weaver's name (linked to the weaver profile, R30), community, region, fiber used, weaving technique and the story behind the bag, as a distinct "artifact label" section (DSD §4).
- **R30** [v2-new] **Meet the Weavers:** `/weavers` lists active weaver profiles (photo or illustration, name, community, region, fiber/technique). Each profile page shows the weaver's story, community information and their active products. Deactivated weavers and their profiles are not public.
- **R31** [v2-new] **Heritage Journal:** `/journal` lists published short articles on the history of the bayong, native fibers and Filipino weaving traditions; each article has its own page (title, cover image, body, sources). Drafts are not public. Articles cite their secondary sources.

### 5.2 3D viewer

- **R4** [v2-changed] Products with a 3D config embed a 3D viewer; the bag rotates via drag (orbit controls) with inertia, plus auto-rotate when idle. A product without a 3D config shows its gallery only, with no broken 3D control (proposal limitation: 3D may not exist for every product). All four seeded products keep a 3D config.
- **R5** [v1] "Peek inside" mode: lid/flap opens where applicable (open totes supported), camera moves inside, interior (lining, pockets) is visible; user can still orbit inside. Toggle back to exterior.
- **R6** [v1] 3D models are procedural, parameterized per product (shape, dimensions, colors, handle style, lining, pockets, optional pattern) via a per-product JSON config served by the backend. Only admins edit 3D configs. **Supplied images are inspiration and procedural-3D modeling references only; they are NOT publication assets and do not establish Habi ownership or provenance.**
- **R27** [v1] If WebGL is unavailable, the viewer falls back to a static product image with a note.

### 5.3 Cart

- **R7** [v1] Add-to-cart from listing or detail page; toast confirmation + cart badge count.
- **R8** [v1] Cart page/drawer: line items with image, qty stepper, remove, subtotal, shipping estimate, "Proceed to checkout".
- **R9** [v1] Quantity is capped at available stock; if stock drops below cart qty (e.g., another buyer purchased), the cart is corrected with an explanatory notice.
- **R17** [v1] Products at 0 stock, or marked unavailable (R37, R40), cannot be added to the cart ("Sold out"); low stock (≤ threshold, default 3) shows "Only N left".
- **R28** [v1] Cart persists across page loads (server session) for the session lifetime.

### 5.4 Checkout & payment (customer + order management modules)

- **R10** [v1] Guest checkout (no account required): steps Shipping → Payment → Review → Confirmation. Each order gets a unique reference number **H-YYYYMMDD-NNNN** (this format prevails over the shorter sample numbers in the approved reference's prototype screens).
- **R11** [v1] Customer and delivery form: name, phone, email, address, region (select), with server-side validation and inline errors.
- **R12** [v1] Shipping fee computed from region (Metro Manila ₱120, Luzon ₱180, Visayas ₱220, Mindanao ₱250; free above ₱5,000 subtotal). Subtotal, shipping fee and total are computed automatically by the server and shown before payment. These rates and the threshold are binding; the approved visual reference's sample figures (₱150/₱220/₱280/₱320) are prototype content, not requirements.
- **R13** [v2-changed] **Payment option selection.** The payment step offers three methods:
  1. **Cash on delivery (COD):** no input; payment status starts `unpaid`.
  2. **E-wallet or bank transfer with proof of payment:** shows sample transfer instructions (account name/number and the amount) and requires one proof-of-payment image upload (N11) plus the sender's reference number; payment status starts `for_verification`.
  3. **Card (simulated):** mock card form; card number validated with Luhn, expiry MM/YY future-dated, CVC 3–4 digits; processed per R14; payment status `paid` on approval.

  Per D7 the storefront shows no simulation label; the test cards and sample transfer accounts are documented in README. *v1 implements the card method only.*
- **R14** [v1] Card method fake gateway: "processing" state (spinner, 1.5–2.5 s), then success or decline. Test cards: `4242 4242 4242 4242` → success; `4000 0000 0000 0002` → card declined; `4000 0000 0000 9995` → insufficient funds. Any other Luhn-valid number → success. **Declined payments do not create orders.**
- **R15** [v2-changed] On Place (after review, and after an approved simulation for the card method), an order is created with status `pending`, its payment method and payment status, and stock is decremented **atomically** (DB transaction; rollback + friendly error on failure or insufficient stock at that moment). Placement is idempotent. Stock stays reserved until the order is rejected or cancelled (R22). *v1 creates card orders with status `paid`.*
- **R16** [v2-changed] Confirmation page: order reference number, item summary, totals, shipping address, payment method and payment status, estimated delivery window, and a prompt to keep the reference number for tracking (R32). Failed card payments show the reason and keep the cart intact.
- **R32** [v2-new] **Order status tracking:** `/track` accepts an order reference number **and the email used at checkout** (both must match; generic error otherwise; attempts throttled). It shows order status, payment status and their timestamps, items and totals, and the delivery region/city — not the full address or phone. *The email check is a privacy addition: H- numbers are sequential and guessable (N4).*

### 5.5 Order management module

- **R44** [v2-new] **Order records:** every order stores its reference number (R10), placement timestamp, customer and delivery information, server-computed subtotal/shipping/total (R12), payment method and payment status (R13), and immutable item snapshots. **Each order item is assigned to its product's weaver** (weaver snapshot at placement), so one order can contain items from several weavers. Every status or payment-status change appends a timestamped event (old → new, actor role and id, optional note). Orders and events are never deleted.

### 5.6 Admin/staff module (`/admin`, admin login required)

- **R19** [v1] Admin login (email + password, `password_hash`/`password_verify`, generic error, attempt throttling, session-based). Logout.
- **R18** [v2-changed] Admin dashboard shows order summaries (orders by status, orders to confirm, payments to verify), sales information (sales totals for today, this month and overall from non-rejected, non-cancelled orders), stock levels and low-stock alerts. Stock changes are logged (audit: product, old → new, reason, timestamp, actor — admin or weaver). *Order/sales summaries and weaver actors are new.*
- **R40** [v2-new] **Weaver account management:** admins create weaver accounts (login email, initial password set by the admin or a one-time reset, profile fields), edit them, and deactivate or reactivate them. Deactivating a weaver blocks login and hides their profile and products from the storefront without deleting orders. Admins approve, edit or deactivate weaver **listings** (R36).
- **R20** [v2-changed] Product management across all weavers: create/edit/deactivate products including weaver (required), price (centavos), category (R41), description, primary fiber/material, community and region, weaving technique, story (R29), dimensions, stock, low-stock threshold, 3D config (optional, R4) and images.
- **R41** [v2-new] **Category management:** admins create, rename and deactivate categories. The four seeded categories (Tote, Satchel, Sling, Clutch) stay. A category with products cannot be deleted, only deactivated; deactivated categories leave the catalog filter.
- **R21** [v1] Stock management: adjust stock with reason; audit log view.
- **R22** [v2-changed] **Order management and status:** orders list with filters by status, payment status, payment method and date; order detail with items grouped by weaver and the event history (R44). Status flow: `pending` → `confirmed` → `packed` → `shipped` → `delivered`, one step at a time. From `pending`, an admin can **confirm** or **reject** (reason required). An admin can **cancel** from `confirmed` or `packed`. Reject and cancel restore stock atomically with audit rows. Payment status: `unpaid` → `paid` (COD collected, at or after delivery); `for_verification` → `paid` (proof accepted) or → `rejected` (proof refused, reason required, order then rejected or cancelled). A transfer order cannot be confirmed until its payment is `paid`. Refunds are out of scope: rejecting or cancelling a paid order records the cancellation only. *v1 statuses are `paid → packed → shipped → delivered / cancelled`.*
- **R42** [v2-new] **Customer order records:** admins view customers (grouped by checkout email) with contact details and all their previous orders. No customer accounts exist.
- **R43** [v2-new] **Content management:** admins create, edit, publish and unpublish Heritage Journal articles (R31) and edit any weaver profile (R30, R35). Content is plain text or a sanitized allowlist (N2).
- **R23** [v2-changed] Images: admins and weavers (own products and own profile) upload images (validated type/size), or products use seeded sample images. Proof-of-payment uploads follow N11.

### 5.7 Weaver module (`/weaver`, weaver login required)

- **R34** [v2-new] **Weaver login:** separate sign-in for weaver accounts (same password hashing, generic error and throttling as R19). Deactivated weavers cannot sign in. Logout.
- **R35** [v2-new] **Weaver profile management:** a weaver edits their own public profile: display name, photo, community, region, fibers/techniques and story. Changes go live immediately; admins can edit or deactivate (R40, R43).
- **R36** [v2-new] **Product listing management:** a weaver adds, edits and removes (archives) their **own** bags, including photos, category, fiber/materials, dimensions, price and story fields (R29). New listings start as `pending_review` and are not public until an admin approves them (R40). Admin-deactivated listings cannot be reactivated by the weaver. Weavers cannot edit 3D configs (R6).
- **R37** [v2-new] **Stock management:** a weaver updates stock quantities of their own products (with reason, audited per R18) and marks a product available or sold out.
- **R38** [v2-new] **Order viewing:** a weaver sees orders that contain their items: reference number, date, their own line items and quantities, line totals, order and payment status, and the delivery region/city. They do not see other weavers' items or customers' full address, phone or email. Weavers do not change order status.
- **R39** [v2-new] **Earnings summary:** per weaver, totals of their line items: **earned** (items in `delivered` orders with payment `paid`), **pending** (items in `pending`, `confirmed`, `packed` or `shipped` orders, and `delivered` orders whose payment is not yet `paid`) and units sold, with an itemized list and a period filter. Shipping fees are excluded. Habi takes **no commission** (no middleman cut); the summary states the weaver's share as 100%. No payouts occur (simulated).

### 5.8 Cross-cutting

- **R24** [v1] Fully responsive (mobile-first; usable at 360 px), including the weaver and admin modules.
- **R25** [v1] Accessibility basics: WCAG AA contrast, keyboard-navigable viewer and forms, focus states, aria labels, `prefers-reduced-motion` respected.
- **R26** [v2-changed] SEO basics: semantic HTML, per-page title/meta description, Open Graph tags, favicon; `lang` attribute matches the active language (R33). Weaver and journal pages are indexable; session, tracking, weaver and admin pages are `noindex`.
- **R33** [v2-new] **Language option:** customers switch the storefront between English and Filipino from the header. The choice persists for the session (and a cookie) and applies to all UI strings, validation messages and the checkout. Content fields (product description and story, weaver story, journal articles) have English as required and Filipino as optional; a missing Filipino field falls back to English. Weaver and admin modules may stay English-only.

## 6. Non-Functional Requirements

- **N1** [v1] Performance: LCP < 3 s on 4G; 3D viewer initializes < 2 s after product page load.
- **N2** [v1] Security: PDO prepared statements everywhere; all output escaped (XSS); CSRF tokens on every POST; `HttpOnly`/`SameSite` session cookies; admin routes behind auth check; no secrets in repo (`.env`, `.env.example` only). Security is basic, appropriate to a school project, and not tested against real-world threats.
- **N3** [v2-changed] Payment simulation never calls a real gateway, e-wallet or bank, and never collects money. Transfer instructions use sample accounts. Per D7 the UI shows no simulation disclaimer; this stays a documented backend fact.
- **N4** [v2-changed] Privacy: store only order-necessary data; card numbers are **never stored** (only last4 + brand); CVC/expiry discarded. Proof-of-payment images are visible only to admins. Weavers see only the order data in R38. Tracking (R32) requires the order's email.
- **N5** [v1] Browser support: last 2 versions of Chrome, Firefox, Safari, Edge. Requires internet access and a modern browser.
- **N6** [v1] Runs identically via `php -S localhost:8000 -t public public/index.php` (dev) and in the production Docker image (Google Cloud VM / Render — see SDD §12).
- **N7** [v2-changed] Data: seeded sample catalog of **4 products**, one per category (Tote, Satchel, Sling, Clutch), each **woven from a native fiber** (buri, pandan, tikog or similar) and assigned to a sample weaver; at least **4 sample weaver profiles** from distinct communities; at least **3 Heritage Journal articles** (bayong history, native fibers, weaving traditions) with sources; prices in PHP pesos. Sample content follows §2's truthfulness rule. *v1 seed has 4 products with non-fiber materials (e.g., leather) and no weavers or articles.*
- **N8** [v1] Code quality: PSR-12, `php -l` clean, Composer autoload (PSR-4), no committed vendor dir.
- **N9** [v2-new] Internationalization: all customer-facing UI strings come from English and Filipino translation catalogs; no hard-coded customer-facing strings in templates or JavaScript; missing keys fall back to English and are reported by a test.
- **N10** [v2-new] Role separation: admin and weaver identities are separate; every weaver route enforces object-level ownership (a weaver can read or change only their own profile, products, stock and order items); attempts on another weaver's resources return 404. Covered by tests.
- **N11** [v2-new] Proof-of-payment uploads: JPEG/PNG/WebP detected from bytes, ≤ 2 MB, random file name, stored outside the document root, served only to signed-in admins, never public URLs.
- **N12** [v2-new] Evaluation readiness: the deployed prototype supports a usability, functionality and design evaluation by each respondent group (customer, weaver, admin) with seeded sample accounts and data, and findings are recorded with the refinements made (BUILD M15).

## 7. Success Metrics

| Metric | Target |
|---|---|
| End-to-end purchase (simulated) | Works for COD, transfer with proof, all 3 test cards, and the out-of-stock path |
| Order lifecycle | Confirm → packed → shipped → delivered, reject and cancel all work with stock restored and events recorded |
| Stock accuracy | 0 oversell events across concurrent checkouts |
| Role isolation | 0 cross-weaver data access in tests (N10) |
| Page performance | LCP < 3 s on throttled 4G (Lighthouse) |
| Task time | Admin adds product < 2 min; weaver updates stock < 30 s |
| Accessibility | Lighthouse a11y score ≥ 90; keyboard-only checkout possible |
| User evaluation (O6) | Usability, functionality and design rated by respondents from each user group; results and refinements recorded (instrument in §10) |
| Deploy | Live HTTPS URL (<https://habi.theavenir.dev/>), SQLite persisted across restarts and redeploys |

## 8. Constraints

- Backend language **must be PHP** (course requirement); Composer allowed.
- All transactions are **simulated only** (N3): orders, payments and shipping are processed only within the system.
- Order statuses are updated manually by admins (R22); shipping fees are computed by broad region only (R12).
- Sample content only: researchers are not weavers and did not contact, recruit or partner with weaving communities. Do not use real people's names, photos or likeness for sample weavers.
- Must be deployable to the web (Docker on Google Cloud Compute Engine, Render alternative); developed and tested as a prototype on limited hosting, not at commercial scale.
- Team of 5 (Group 1); build phased by feature (BUILD.md).

## 9. Limitations (from the proposal)

- Habi is a prototype and simulation, not a live business. Findings cannot be generalized to the estimated 5,000 Filipino weavers or to any community or tradition.
- Content (weavers, products, prices, stock, stories, journal) comes from secondary sources and is sample content.
- No real payments, payment gateway, e-wallet or bank integration; no courier integration, live rates or parcel tracking.
- Guest checkout only: tracking depends on the order reference number (plus checkout email, R32); no order history, saved addresses, wishlists or notifications beyond what the website shows.
- The 3D view depends on models the team can produce and may not exist for every product (R4).
- Languages: English and Filipino only.
- Web application only; requires internet access and a modern browser.
- Login security is basic (N2).
- Evaluation uses a limited number of respondents (classmates, instructors, volunteers) who may not be actual weavers or typical shoppers. The study does not measure actual sales, weaver income or buying behavior, does not test effects on colonial mentality or fast fashion, and cannot verify that a bag was truly handwoven by the listed weaver.

## 10. Open Items / Placeholders

- **Evaluation instrument (O6, N12):** questionnaire, rating scale, respondent count per group and procedure are to be chosen by the group before BUILD M15.
- **Sample content sourcing (N7):** weaver profiles, stories and journal articles must be written from the proposal's references (and similar secondary sources) and cite them; pending in BUILD M9/M10.
- **Filipino translations (R33, N9):** who writes and reviews the Filipino copy is to be assigned.
- **Sample transfer accounts (R13):** fictional e-wallet/bank account names and numbers to be defined in seed configuration and documented in README; they must not be real accounts.
- **Existing production data:** the deployed database keeps v1 seed rows and `paid` orders until migrated (SDD §15.9) or explicitly reset with `php seed/seed.php --force` (destructive).
- **Fonts and assets:** resolved — fonts, colors and SVG artwork come from the approved published reference, with sources/licenses in DSD §6. New assets (weaver portraits/illustrations, journal covers) need the same DSD §6 record and review-screen check, and must not depict real people.
- **Product imagery:** published-reference SVG illustrations are the seed images; procedural exterior/interior models are implemented (M2/M3). **Supplied reference photographs remain inspiration/modeling references only, NOT publication assets or proof of ownership/provenance.**
- **Province list:** v2 keeps the region-level shipping map (R12); a province list stays backlog.
- **Deployment:** resolved — Google Cloud Compute Engine e2-micro with Caddy HTTPS (SDD §12, decision D6). Hosted acceptance status is recorded in BUILD M8.

### Prototype vs requirements

The approved visual reference is a design specification, not a requirements source. Where its prototype screens and this PRD disagree, the PRD prevails:

- **Shipping fees:** the reference's sample figures do not replace R12's rates or free-shipping threshold.
- **Order numbers:** the reference's shortened examples do not replace R10's format.
- **Stock handling:** R17 requires only that 0-stock or unavailable products cannot be added; R9 clamping and R15 atomic decrement are binding.
- **Screens the reference lacks** (weaver profiles, journal, tracking, payment method choice, weaver module, new admin screens) are composed from existing published components (DSD §4).

## 11. Glossary

- **Weaver:** a sample artisan account and public profile; owns products and order items.
- **Listing approval:** admin review that moves a weaver's new product from `pending_review` to active (R36, R40). Simulated; does not verify authenticity.
- **Order status:** fulfilment state `pending`, `confirmed`, `packed`, `shipped`, `delivered`, `rejected`, `cancelled` (R22).
- **Payment status:** `unpaid`, `for_verification`, `paid`, `rejected` (R13, R22).
- **Proof of payment:** customer-uploaded image of a transfer receipt, reviewed by an admin (R13, N11).
- **Reference number:** the order number `H-YYYYMMDD-NNNN` (R10).
- **Simulated payment / fake gateway:** UI flow that mimics payment; no real transaction occurs (R13–R14, N3).
- **Stock decrement:** atomic reduction of `products.stock` when an order is placed (R15); restored on reject/cancel (R22).
- **Earnings:** sum of a weaver's delivered-and-paid line totals with no commission (R39).
- **3D config:** per-product JSON describing the procedural bag model (R6).
