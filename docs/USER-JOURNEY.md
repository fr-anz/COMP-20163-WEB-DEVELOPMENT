# USER-JOURNEY — Habi

> **Status:** Journeys describe target behavior. M0–M4 implement discovery, catalog, reversible exterior/interior inspection and the session cart; checkout/admin remain M5–M6. Local verification and outstanding review/merge requirements are recorded in BUILD. The published UI/design system remains authoritative 1:1. Real inventory, routes and truthful unavailable states remain binding; no fake cart/order data.

| | |
|---|---|
| Version | 1.1 · 2026-11-09 |
| References | Requirements [PRD.md](PRD.md) R#/N# · Screens/components [DSD.md](DSD.md) · Data flows [SDD.md](SDD.md) · Build order [BUILD.md](BUILD.md) |

---

## 1. Personas

- **P1 — Maya, 28, urban professional (primary buyer).** Wants a distinctive everyday bag she can trust online. Skeptical of quality without seeing it → the 3D peek-inside is her trust moment. Shops on phone during commute. *Planning example, not researched demographics.*
- **P2 — Lolo Boy, 55, gift buyer.** Buying for family abroad. Wants clear ₱ pricing, simple steps, and proof the order went through. Uses a tablet, larger text. *Planning example, not researched demographics.*
- **P3 — Ate Rose, shop owner (admin).** Non-technical. Checks stock every morning, adds new products when artisans finish pieces, marks orders shipped. Needs the admin panel to be obvious and fast. *Planning example, not researched demographics.*

## 2. Journey J1 — First purchase (happy path)

| # | Step | Screen / touchpoint | What the user sees/does | Req |
|---|---|---|---|---|
| 1 | Discover | Home / Google | Short, skippable craft-first intro; free material/bag exploration section; optional shopping path. Hero, featured products, expressive craft sections | R1 |
| 2 | Browse | Listing | Grid of 10 bags with stock labels (In stock / Only N left / Sold out — the approved system ships no other badges); search, category filter, sort, pagination | R2 |
| 3 | Inspect | Product page | Photos; switches to **3D tab**, drags to rotate the bag | R3, R4 |
| 4 | Trust | Product page | Clicks **"Peek inside"** — lid/flap opens where applicable (open totes supported), camera goes in, sees lining + pockets; reads neutral label (e.g., "illustrative sample") | R5, R6 |
| 5 | Decide | Product page | Picks qty 1 (stepper caps at stock), **Add to bag** → toast + cart badge = 1 | R7, R9, R17 |
| 6 | Cart | Drawer / `/cart` | Line item, subtotal, shipping estimate, **Checkout** | R8 |
| 7 | Shipping | Checkout step 1 | Fills name/phone/email/address/region; inline validation; sees shipping fee update by region + total | R10–R12 |
| 8 | Payment | Checkout step 2 | Mock card form + semantic notice "SIMULATED PAYMENT — no real transaction occurs"; enters `4242 4242 4242 4242` | R13, N3 |
| 9 | Process | Spinner | "Processing…" 1.5–2.5 s | R14 |
| 10 | Review/confirm | Step 3 → place | Order summary, **Place order**; idempotent (double-click safe) | R10, R15 |
| 11 | Confirmation | `/order/H-…` | Green check, order number (H-YYYYMMDD-NNNN), full summary, address, ETA, simulation notice; stock already decremented | R15, R16 |

**Feelings arc:** curious → impressed (3D) → confident.

## 3. Journey J2 — Edge cases (all must be graceful)

| Scenario | Flow | Req |
|---|---|---|
| Sold out item | Listing shows "Sold out" badge; detail page Add-to-cart disabled | R17 |
| Stock drops below cart qty | Cart recalculates, clamps qty, toast: "Only 2 left of X — quantity adjusted" | R9 |
| Card declined (`4000…0002`) | Payment step shows "Card declined — try another card"; cart + shipping intact; no order created | R14, R16 |
| Insufficient funds (`4000…9995`) | Same pattern, message "Insufficient funds (simulated)" | R14 |
| Invalid card (Luhn fail) | Inline field error, stays on payment step | R13 |
| Insufficient stock at place-time (race) | Friendly error page: "Someone just bought the last one"; cart updated, back to cart | R15, R17 |
| Double-submit place order | Idempotency key → same order, no double stock decrement | R15 |
| WebGL unavailable | 3D tab shows photo + "3D unavailable in this browser" | R27 |
| Mobile 360 px | Drawer full-width, viewer in overlay, stepper "Step 2 of 4", all targets ≥44 px | R24, R25 |
| Session expired mid-checkout | Redirect to cart with toast "Session expired — please add items again"; **R28: session lifetime only, no saved cart after expiry** | R28 |

## 4. Journey J3 — Admin daily flow

| # | Step | Screen | Action | Req |
|---|---|---|---|---|
| 1 | Login | `/admin/login` | Email + password; 5 fails → 60 s lockout; generic error | R19 |
| 2 | Morning check | Dashboard | Low-stock alerts (≤3), today's orders count, recent orders | R18 |
| 3 | Restock | Products → row → Stock | +20 with reason (e.g., "demo restock") → audit row written | R21, R18 |
| 4 | New product | Products → New | Name, price ₱, category, description, materials, place-of-make, dimensions, stock, 3D config (shape/colors/pockets), image upload | R20, R23, R6 |
| 5 | Ship orders | Orders → filter "paid" | Open order → set "shipped"; cancel path restores stock w/ audit | R22 |
| 6 | Audit | Stock audit | Who changed what stock, when, why | R18 |

## 5. Flow Diagrams

```
J1 storefront:  Home ─▶ Listing ─▶ Product ─▶ [3D rotate ⇄ peek inside] ─▶ Add to bag ─▶ Cart ─▶ Shipping ─▶ Payment(sim) ─▶ Review ─▶ Place ─▶ Confirmation (H-YYYYMMDD-NNNN)
(craft exploration can end here without purchase)

J3 admin:  /admin/login ─▶ Dashboard ─┬─▶ Products (CRUD, stock) ─▶ Stock audit
                                       └─▶ Orders (view, status, cancel→restock)
```

## 6. Success Signals (mapped to PRD §7)

- J1 happy path completes on mobile (R10–R16); **timing targets are goals, not constraints on exploration; card declines are expected nonorder outcomes.**
- Zero oversells in the concurrency test to verify (R15).
- Admin J3 add product and update stock each under the time targets in PRD §7 (R20, R21).
- Every edge case in J2 lands on a designed screen (no raw errors) — to be verified in BUILD M5/M7 smoke checklist.
