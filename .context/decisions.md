# Decision history

## D1 — 2026-11-05 — accepted by user (via Q&A) — E-commerce platform decisions for Habi (then unnamed)
- **Decision:** PHP 8.2+ backend (course-required) with Composer, Slim 4 + Twig 3, PDO **SQLite** (WAL) as DB, server-rendered pages + vanilla JS ES modules, vendored Three.js for procedural 3D bags, Docker deploy to **Railway** (Render documented as alternative), SQLite on a mounted volume.
- **Reason:** PHP is mandated by COMP-20163; SQLite keeps dev zero-setup (`php -S`) while remaining portable to MySQL later; procedural 3D reduces dependence on third-party model files (asset rights still require review); Railway volume solves DB persistence for the "deploy to web" requirement.
- **Alternatives considered:** vanilla PHP without framework (rejected: more boilerplate for routing/CSRF), MySQL from day one (rejected: setup friction), GLB model downloads (rejected: licensing + no real "inside" view), client-side-only cart (rejected: weak inventory story).
- **Consequences:** SDD isolates SQLite-vs-MySQL differences in `OrderService`; the formerly open brand name is resolved by D4.
- **Source:** docs/PRD.md, docs/SDD.md, docs/BUILD.md (all 2026-11-05).

## D2 — 2026-11-05 — accepted by user — Commerce scope
- **Decision:** Guest checkout + admin login (no customer accounts); **simulated** card payment with Luhn test cards (`4242…` approve, `4000…0002` decline, `4000…9995` insufficient funds), card numbers never stored; inventory decrements atomically on simulated success; ~10 seeded products; admin CRUD/stock/orders.
- **Reason:** Covers full checkout experience + inventory system without real-payment/PII scope; atomic decrement prevents oversell.
- **Source:** docs/PRD.md R7–R23, N3–N4; docs/SDD.md §7–§9; docs/USER-JOURNEY.md J1–J3.

## D3 — 2026-11-05 — accepted by user — Design direction & team shape
- **Decision:** **Artisan-luxury Filipino** design direction (terracotta/sand/moss/gold, Fraunces + Inter); 4-person team with tracks A backend / B 3D / C storefront UI / D admin+DevOps; docs live in `docs/` as 5 markdown files; build phased by feature milestones M0–M8, no fixed deadline.
- **Status:** visual direction superseded by D4 on 2026-11-09. Team shape, documentation layout and milestones remain current.
- **Source:** docs/DSD.md, docs/BUILD.md.

## D4 — 2026-11-09 — accepted by user — Habi identity, discovery & reference use
- **Decision:** adopt **Habi**, a contemporary Filipino craft direction. Canonical design and interview choices are in docs/DSD.md §§1–12; this replaces D3's visual direction, not the technical stack or simulated commerce/admin scope.
- **Reason/source:** user approved the direction brief after three interview rounds; wants handmade, contemporary, distinctly Filipino identity rather than generic e-commerce/editorial branding.
- **Asset boundary:** supplied images are inspiration and procedural-3D modeling references for the educational demo, not authorized publication assets or proof of product/maker ownership. DSD §9 governs generated/authorized website assets.
- **Consequences:** reconcile all five planning docs; brand naming is complete. Exact fonts/colors and original publishable assets remain implementation choices. No application implementation was requested or performed.
