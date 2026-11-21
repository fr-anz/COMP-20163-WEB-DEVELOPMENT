# USER-JOURNEY — Habi

> **Status:** Journeys describe PRD 2.0 target behavior. M0–M8 implement discovery, catalog, reversible exterior/interior inspection, the session cart, card-only simulated guest checkout with atomic orders and the admin panel. Steps marked **(v2)** are planned (BUILD M9–M15) and not implemented. Local verification and outstanding review/merge requirements are recorded in BUILD. Real inventory, routes and truthful unavailable states remain binding; no fake cart/order data. All weavers, products and stories are sample content (PRD §2).

| | |
|---|---|
| Version | 2.0 · 2026-11-21 |
| References | Requirements [PRD.md](PRD.md) R#/N# · Screens/components [DSD.md](DSD.md) · Data flows [SDD.md](SDD.md) · Build order [BUILD.md](BUILD.md) |

---

## 1. Personas

- **P1 — Maya, 28, urban professional (primary buyer).** Wants a distinctive everyday bag with meaning. Skeptical of quality and of "local" as a selling point → the 3D peek-inside and the weaver's story are her trust moments. Shops on phone during commute; reads in English. *Planning example, not researched demographics.*
- **P2 — Lolo Boy, 55, gift buyer.** Buying for family abroad. Prefers Filipino, wants clear ₱ pricing, cash on delivery or a bank transfer, simple steps, and a reference number to check on the order later. Uses a tablet, larger text. *Planning example.*
- **P3 — Ate Rose, admin/staff.** Non-technical. Each morning she confirms new orders, verifies transfer proofs, checks stock, approves weavers' new listings and moves orders along. Occasionally publishes a journal article. *Planning example.*
- **P4 — Nanay Lita, sample weaver (v2).** Weaves tikog bags with her community; uses a basic phone browser. Her account was created by an admin. Wants to post a new bag, keep stock correct, see which of her bags sold and how much she earned — without a middleman's cut. *Sample role; no real weavers were recruited (PRD §8).*

## 2. Journey J1 — First purchase (happy path)

| # | Step | Screen / touchpoint | What the user sees/does | Req |
|---|---|---|---|---|
| 1 | Discover | Home / Google | Cinematic craft story ending at the catalog; may switch to Filipino in the header **(v2)** | R1, R33 |
| 2 | Browse | Listing | Grid of bags with stock labels (In stock / Only N left / Sold out); search, category, material and region filters **(material/region v2)**, sort, pagination | R2 |
| 3 | Inspect | Product page | Photos, price, materials, place of make, dimensions, shipping rates by region **(v2)**; switches to **3D**, drags to rotate | R3, R4 |
| 4 | Trust | Product page | Clicks **Peek inside** — lining + pockets; reads the artifact story: weaver, community, fiber, technique, story **(v2)**; follows the weaver link to their profile **(v2)** | R5, R6, R29, R30 |
| 5 | Decide | Product page | Picks qty 1 (stepper caps at stock), **Add to bag** → toast + cart badge = 1 | R7, R9, R17 |
| 6 | Cart | Drawer / `/cart` | Line item, subtotal, shipping estimate, **Checkout** | R8 |
| 7 | Shipping | Checkout step 1 | Name/phone/email/address/region; inline validation; fee updates by region + total | R10–R12 |
| 8 | Payment | Checkout step 2 | Chooses **Cash on delivery**, **E-wallet or bank transfer** (uploads receipt + sender reference) or **Card** (mock form; `4242 4242 4242 4242`, processing 1.5–2.5 s) **(COD/transfer v2)** | R13, R14, N3 |
| 9 | Review | Step 3 → place | Order summary with payment method, **Place order**; idempotent (double-click safe) | R10, R15 |
| 10 | Confirmation | `/order/H-…` | Reference number (H-YYYYMMDD-NNNN), summary, address, payment method/status, ETA; status **Pending** with a prompt to keep the reference **(v2)**; stock already decremented | R15, R16, R44 |
| 11 | Track **(v2)** | `/track` | Days later enters reference + email; sees Confirmed → Shipped timeline and payment status | R32 |

**Feelings arc:** curious → impressed (3D) → moved (the maker's story) → confident.

## 3. Journey J2 — Edge cases (all must be graceful)

| Scenario | Flow | Req |
|---|---|---|
| Sold out or unavailable item | Listing shows "Sold out"; detail page Add to bag disabled with reason | R17 |
| Stock drops below cart qty | Cart clamps qty with notice: "Only 2 left of X — quantity adjusted" | R9 |
| Card declined (`4000…0002`) / insufficient funds (`4000…9995`) | Payment step shows the reason; cart + shipping intact; no order | R14, R16 |
| Invalid card (Luhn fail) | Inline field error, stays on payment step | R13 |
| Transfer without proof / bad file **(v2)** | Inline error on the file field (type/size); stays on payment step; no order | R13, N11 |
| Insufficient stock at place-time (race) | Friendly error: "Someone just bought the last one"; cart updated | R15, R17 |
| Double-submit place order | Idempotency key → same order, no double stock decrement | R15 |
| Tracking with wrong email or number **(v2)** | Generic "We couldn't find that order" (no hint which field); repeated attempts throttled | R32, N4 |
| Transfer proof refused **(v2)** | Tracking shows payment Rejected and order Rejected/Cancelled; stock restored | R22, R32 |
| Product without a 3D model **(v2)** | Gallery only; no 3D tab shown | R4 |
| Filipino text missing for a story **(v2)** | English text shown in its place | R33 |
| WebGL unavailable | 3D tab shows photo + "3D unavailable in this browser" | R27 |
| Mobile 360 px | Drawer full-width, viewer in overlay, stepper "Step 2 of 4", targets ≥ 44 px | R24, R25 |
| Session expired mid-checkout | Back to cart with an explanation; session lifetime only | R28 |

## 4. Journey J3 — Admin daily flow

| # | Step | Screen | Action | Req |
|---|---|---|---|---|
| 1 | Login | `/admin/login` | Email + password; 5 fails → 60 s lockout; generic error | R19 |
| 2 | Morning check | Dashboard | Orders to confirm, payments to verify, sales today/month **(v2)**; low-stock alerts; recent orders | R18 |
| 3 | Verify payment **(v2)** | Order detail | Opens a transfer order, views proof → **Accept payment** (or **Refuse…** with reason) | R22, N11 |
| 4 | Confirm orders **(v2)** | Orders → filter Pending | **Confirm** or **Reject…** (reason; stock restored) | R22, R44 |
| 5 | Fulfil | Order detail | Confirmed → Packed → Shipped → Delivered, one step each; COD marked Paid at delivery **(v2)**; cancel before shipping restores stock | R22 |
| 6 | Approve listings **(v2)** | Products → Pending review | Reviews a weaver's new bag, edits if needed, **Approve** (or deactivate) | R40, R20 |
| 7 | Manage weavers **(v2)** | Weavers | Creates a weaver account (email, initial password, profile); deactivates one who left | R40 |
| 8 | Restock / new product | Inventory / Products | Adjust stock with reason (audited); add product with weaver, category, fiber, region, technique, story, 3D config | R20, R21, R23, R6 |
| 9 | Catalog & content **(v2)** | Categories / Journal | Adds a category; publishes a journal article with sources | R41, R43, R31 |
| 10 | Customer lookup **(v2)** | Customers | Finds a customer by email and their previous orders | R42 |
| 11 | Audit | Stock audit | Who (admin or weaver) changed what stock, when, why | R18 |

## 5. Journey J4 — Weaver flow (v2)

| # | Step | Screen | Action | Req |
|---|---|---|---|---|
| 1 | Login | `/weaver/login` | Email + password from the admin; generic errors, throttled | R34 |
| 2 | Overview | Weaver dashboard | Own low-stock bags, recent orders for own items, earnings snapshot | R37–R39 |
| 3 | Profile | Profile | Updates story, community and photo; live on Meet the Weavers | R35, R30 |
| 4 | New bag | My bags → Add | Photos, category, fiber, dimensions, price, story → saved as **Pending review** (not public) | R36 |
| 5 | Stock | Stock | Sets quantity with reason, or marks a bag sold out | R37, R18 |
| 6 | Orders | Orders | Sees orders containing her items: reference, date, her items, status, delivery region/city only | R38, N4, N10 |
| 7 | Earnings | Earnings | Earned (delivered + paid), pending, units sold; "Your share 100%" — no middleman cut | R39 |

Edge cases: a deactivated weaver cannot sign in; opening another weaver's product or order URL shows Not found (N10); an admin-deactivated listing cannot be reactivated by the weaver (R36).

## 6. Flow Diagrams

```
J1 storefront:  Home ─▶ Listing ─▶ Product ─▶ [3D rotate ⇄ peek inside] ─▶ [story ─▶ weaver profile] ─▶ Add to bag
                ─▶ Cart ─▶ Shipping ─▶ Payment(COD | transfer+proof | card sim) ─▶ Review ─▶ Place
                ─▶ Confirmation (H-YYYYMMDD-NNNN, Pending) ─▶ /track (reference + email)
(exploration can end anywhere without purchase; Weavers and Journal are reachable from the header)

J3 admin:  /admin/login ─▶ Dashboard ─┬─▶ Orders (confirm/reject, verify payment, status, cancel→restock)
                                       ├─▶ Products (CRUD, approve listings) ─▶ Inventory ─▶ Stock audit
                                       ├─▶ Weavers · Categories · Customers · Journal
J4 weaver: /weaver/login ─▶ Dashboard ─┬─▶ My bags (add → pending review) ─▶ Stock
                                       ├─▶ Orders (own items) ─▶ Earnings
                                       └─▶ Profile

Order:   pending ─▶ confirmed ─▶ packed ─▶ shipped ─▶ delivered      (pending ─▶ rejected; confirmed|packed ─▶ cancelled)
Payment: cod: unpaid ─▶ paid · transfer: for_verification ─▶ paid | rejected · card: paid
```

## 7. Success Signals (mapped to PRD §7)

- J1 completes on mobile with each payment method (R10–R16); card declines are expected non-order outcomes.
- Zero oversells in the concurrency test (R15); reject/cancel always restores stock (R22).
- Admin J3 add product and weaver J4 stock update each meet PRD §7 time targets (R20, R37).
- No weaver can reach another weaver's data (N10).
- Every edge case in J2 lands on a designed screen (no raw errors), verified in BUILD smoke checklists.
- Respondents from each group (customer, weaver, admin) complete their journey during the evaluation (O6, N12).
