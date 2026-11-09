# DSD — Habi Design System Document

| | |
|---|---|
| Version | 1.1 |
| Date | 2026-11-09 |
| Direction | **Contemporary Filipino craft — approved by user, 2026-11-09** |
| Status | M0 shell and M1 discovery/catalog implemented; local runtime scope/checks are recorded in BUILD/README, not a claim that later commerce/viewer components exist |
| Serves | [PRD.md](PRD.md) R1–R28, N1–N8 · [BUILD.md](BUILD.md) · [USER-JOURNEY.md](USER-JOURNEY.md) |

**Craft first, commerce second.** Habi should feel handmade, contemporary, and distinctly
Filipino. A short, skippable introduction leads into free exploration of materials and
bags; shopping stays accessible without leading the opening. This specification replaces
the earlier artisan-luxury direction and the interview draft. It does not authorize app implementation.

## 1. Brand Essence

- **Name:** Habi; “weave” in Filipino, as supplied by the user.
- **Identity:** natural woven fibers and colorful woven textiles, expressed through structure,
  material details, and a repeatable interlacing motif—not a generic store with decorative trim.
- **Voice:** warm, direct, specific about craft. Do not invent maker biographies or cultural origins.
- **Composition:** expressive opening/craft sections; quiet, readable product inspection areas.
- **Avoid:** interchangeable modern editorial branding, shop-first heroes, ornamental clutter,
  copied logos, and unsupported provenance. Storytelling and whitespace themselves are welcome.
- **Demo truth:** this is an educational simulated shop, not a real retailer. Clearly identify
  the demo in the shell; payment and confirmation also carry the explicit N3 simulation notice.
  Seeded products and origin stories must be labeled illustrative, not represented as real inventory from actual makers.

## 2. Color Foundations & Semantic Roles

Approved palette direction: warm cream and natural fiber tones, dark brown text, with
rust, yellow, green, and blue accents. Use a limited subset within each section, not every
accent everywhere. Colorful textiles are the reference; burgundy/olive/metallic-gold luxury
styling is no longer the governing palette.

M0 implements exact palette values in `public/assets/css/app.css`: warm cream/fiber,
dark brown, rust action, yellow/green/blue accents. These are implementation selections,
not values approved in the earlier interview. Raw values live once in `:root`; components
consume purpose-named roles. Runtime contrast checks are recorded with milestone evidence.

| Planned role | Purpose |
|---|---|
| Page / inset / raised surface | Cream page, fiber-toned craft areas, quiet product/form surfaces |
| Primary / secondary text | Dark brown body/UI text and a readable secondary tone |
| Border / divider | Distinguish controls and panels without imitating a weave behind text |
| Primary action / action text / hover | A consistent high-contrast action pair; not a different color per button |
| Focus indicator | Visible on each adjacent surface, independent of decorative yellow |
| Decorative accents | Rust, yellow, green, blue in interlaced bands, framing, and section identifiers |
| Success / warning / error + foreground | Status-specific pairs with text/icon cues; not interchangeable decorative accents |

Components consume semantic roles, not raw palette literals. Measure actual foreground/
background and control-boundary pairs before claiming accessibility (§10).

## 3. Typography

**Approved:** crafted, expressive display lettering with simple, readable interface text.
The wordmark and a few short headings carry character; body text, prices, forms, navigation,
and admin tables prioritize legibility. Do not decorate every heading or imitate another brand's lettering.

**M0 selection (implementation decision):** locally hosted Bricolage Grotesque for display/
wordmark and DM Sans for interface/body text. Both use SIL OFL 1.1; license files are shipped
in `public/assets/fonts/`. These are inspected shell specimens, not custom lettering or an
approved final logo. Narrow/wide shell checks and measured contrast are recorded in BUILD evidence.

Existing scale retained as a starting specification: base 16 px; display 3 rem, h1 2.25,
h2 1.75, h3 1.375, body 1, small .875, caption .75. Headings line-height 1.15; body 1.6.
Adapt long headings to 360 px without clipping; never use tiny decorative labels for essential information.

## 4. Spacing, Shape, Elevation & Motion

- Retain the 4 px spacing grid: 4/8/12/16/24/32/48/64/96/128 px, defined once when implemented.
- Restrained geometry: existing 6 px control / 12 px panel corners are starting values;
  avoid making every section a floating rounded card. Pattern framing gives identity.
- Keep surfaces mostly flat; reserve elevation for overlays/drawers. Exact shadow values remain open.
- Existing interaction durations 120/240/420 ms are starting values, not a demand for animation.
  Viewer transitions may use the existing 700 ms target only when reduced motion is not requested.
- No forced cinematic intro, scroll locking, or required animation before accessing content.
  Keep native scrolling and persistent navigation; ordinary dialog focus handling is separate.
- With `prefers-reduced-motion`, disable auto-rotation, camera tweens, shimmer, and decorative
  motion; retain equivalent immediate controls and state changes (R25).

## 5. Iconography

One consistent inline-SVG family: 24 px grid, 1.75 px stroke, round caps. Planned icons:
bag/cart, search, menu, close, plus/minus, trash, arrows, chevrons, eye, rotate, check,
alert, lock, orders, cube, upload, edit, logout. Icons are functional, not invented cultural
symbols. No emoji in UI. Icon-only controls need accessible names; touch targets remain ≥44 px.

## 6. Planned Components

The table below remains the product-wide plan, not a blanket shipped API list.
M0/M1 implemented APIs are documented in §§13–14; later component states belong to their milestones.

| Component | Planned behavior and presentation |
|---|---|
| Button/link | Distinct primary, secondary, danger, and small treatments using semantic roles; visible hover/pressed/focus/disabled states. A link navigates; a button changes state. |
| Field | Persistent label, readable value/hint, visible resting boundary and focus indicator; inline errors linked to the input, not just a colored border. |
| Craft panel / material selector | Interlaced framing and close-up imagery; clear text links to home craft anchors and existing catalog/detail routes. Keyboard/touch accessible; no hover-only story. Reuse as a block only after actual repetition is demonstrated. |
| Product presentation | Quiet image background, name, price, category and stock text. Listing supports Add to bag (R7), reachable without hover. Keep product link and purchase button separate, never nested interactive elements. |
| Badge | Readable text plus a non-color cue for Sold out / Only N left / New; do not imply customer ratings or reviews. |
| Cart drawer/page | 400 px desktop drawer, full-width narrow-screen; quantity controls, remove, subtotal, shipping estimate, checkout action. Focus management, Esc close, focus return for drawer. |
| Checkout stepper | Shipping → Payment → Review → Confirmation; compact “Step N of 4” on narrow screens. Payment simulation succeeds before Review; Place order commits inventory. |
| Viewer controls | Photos/3D switch, loading/failure/fallback states, rotate controls and reversible Peek inside / Back outside. See §8. |
| Toast | Add-to-bag/stock/error feedback, polite live region. Important errors also persist at their source; do not rely only on a disappearing message. |
| Empty/error state | Explain what happened and offer the relevant browse/retry/correction action. No indefinite skeleton or fake success. |
| Admin table/form | Same semantic foundations, quieter and denser than craft storytelling; accessible row actions, labeled stock inputs, validated image upload (R23). |
| Demo/payment notice | Clearly distinguish educational catalog content from real goods. Payment/confirmation text: “SIMULATED PAYMENT — no real transaction occurs” (N3). |

No wishlist, ratings, fake newsletter subscription, customer login, or new commerce feature is implied.
Detailed component state/API documentation is an implementation deliverable, not evidence that these components exist.

## 7. Page Layouts & Discovery

- **Shell:** Habi wordmark, craft/explore links to home anchors, Shop to `/products`, search,
  cart count, visible demo identification. Craft leads the hierarchy; Shop remains available.
  Do not add dead About/Help destinations. Footer repeats useful existing links and demo notice.
- **Home (R1):** (1) woven-framed bag/material composition and short Habi introduction,
  primary invitation **“Discover the weave”**, plus an obvious skip to free exploration;
  (2) concise material/weaving story using original/authorized imagery;
  (3) freely explorable material/bag presentations leading to existing catalog/product pages.
  Striking product imagery follows the craft; the opening is not a conventional sale/product grid.
  No autoplay prerequisite or mandatory shopping step. The homepage need not mount a 3D renderer.
- **Listing (R2):** search, category, price/newest sort, pagination/load more and result count;
  responsive product grid with stock badges. Price-range and in-stock-only filters are not required.
- **Product (R3):** desktop ≥900 px, gallery/3D tabs left and details right; mobile stacked,
  3D in a fullscreen dialog. Show description, dimensions, demo materials/origin labels, stock,
  quantity and Add to bag. Product inspection uses a quiet background without patterned overlays.
- **Cart (R8):** drawer plus `/cart` full-page alternative; show shipping estimate as an estimate.
- **Checkout (R10–R16):** narrow form column (720 px starting maximum), desktop summary beside it,
  collapsible summary on mobile. Shipping uses R12's region map. Payment → simulation → Review
  → Place order → Confirmation. Confirmation includes order number, summary, simulated ETA,
  simulation notice, and a route back to exploration.
- **Admin:** clear navigation to dashboard, products, orders, stock audit and logout. Use the
  same identity without textile-heavy backgrounds behind forms/tables.

No shopping action is required to finish the craft-discovery journey. Existing commerce
acceptance criteria still apply when a visitor chooses to shop.

## 8. 3D Viewer Design (R4–R6, R27)

- Per-product procedural geometry/config remains the approved technical approach ([SDD.md](SDD.md) §11).
  Supplied bag photos inform silhouette, proportions, handles, weave structure, and color studies.
  They are not imported as textures, copied logos, downloaded models, or proof of actual materials/origin.
- Plan original generated weave/textile patterns for natural-fiber and colorful-textile looks;
  retain the existing material variants where useful, without claiming a specific fiber from appearance alone.
- Neutral cream/fiber backdrop, warm studio lighting, contact shadow. Reserve patterned framing
  for outside the inspection canvas; details and interior must remain easy to see.
- Desktop viewer inline; narrow-screen viewer a labeled fullscreen dialog with focus containment,
  Esc/close action and focus return. Keep orbit/peek controls usable by keyboard as well as drag/touch.
- Idle auto-rotation (R4) stops for user interaction/reduced motion and has a pause/resume control.
  Hints may recede after use; essential controls remain discoverable.
- Peek inside exposes lining/pockets through opening/camera/cutaway behavior from SDD §11;
  Back outside restores exterior. An open tote need not acquire an invented flap.
- Loading: clear progress label; failure: explanation and retry; WebGL unavailable: generated/
  authorized static product image with “3D unavailable in this browser” (R27). Never leave loading permanent.

## 9. Reference & Asset Policy

**User clarification, 2026-11-09:** all supplied images are inspiration only and may also
serve as 3D-modeling references for this non-real shop. This is not permission to publish them.

- First reference group: an ornate cream/burgundy/olive storefront, red/black textile framing,
  a natural woven close-up, colorful circular woven forms. Adopt richness, framing and tactile
  rhythm—not the henna storefront's culture-specific ornament or another identity.
- Second group: checker-woven bags, painted bags, geometric color weaving, neutral contemporary
  totes, patterned textiles, a painterly weaving scene, and a maker weaving a colorful mat.
  These establish visual reference, not authorship, regional attribution, or product ownership.
- Do not ship attachments as website images, image-derived texture maps, traced artworks,
  testimonials, logos, or photographs of supposed Habi makers. A demo setting does not establish rights.
- Seed catalog images: original procedural renders/illustrations or separately authorized assets,
  consistent 4:5 framing on quiet backgrounds. Label them demo products, not real photographed stock.
- Craft imagery: original diagrams/material studies or authorized documented photography. Do not
  portray illustrations as documentary maker photos or invent community stories.
- For published imagery/fonts/models, record source, license/permission and intended use before
  inclusion. Specific cultural attribution requires a reliable source and appropriate context.

## 10. Accessibility & Verification Targets (R24, R25)

These remain product-wide acceptance targets. M0/M1 runtime results are recorded in BUILD;
they do not certify unimplemented checkout or viewer surfaces.

- WCAG 2.2 AA where applicable: text contrast ≥4.5:1, qualifying large text ≥3:1;
  necessary control boundaries/focus indicators ≥3:1 against adjacent colors. Measure real pairs.
- Keyboard-accessible discovery, skip links, shopping, checkout and viewer controls; visible focus.
  Dialogs/drawers contain focus while open and return it on close. No nested product-card controls.
- Persistent input labels, accessible icon names, linked errors, polite cart/toast announcements;
  status meaning must not depend on color alone.
- 360 px layout, readable long names, text resizing, ≥44×44 px touch targets; essential actions
  must not disappear on small screens or require hover.
- Reduced-motion equivalent for introduction, viewer and transitions; pause/stop automatic motion.
- BUILD M0–M7 must inspect actual narrow/wide screens and relevant loading/empty/error/success
  states. A token table, source review or Lighthouse score alone does not prove the full experience.

## 11. Themes

Light mode only remains the planned scope. No dark theme or automatic theme inversion is promised.
Use stable semantic roles so later theme work would not require one-off component colors.

## 12. Decision Ledger & Open Implementation Choices

| Decision | Provenance | Reason |
|---|---|---|
| Habi; handmade, contemporary, distinctly Filipino | User, interview 2026-11-09 | Explicit name and intended impression |
| Mixed ornament density, warm neutrals plus vibrant woven accents | User | Expressive craft experience without competing with bags |
| Crafted display lettering/simple UI type | User | Distinct identity without sacrificing legibility |
| Short guided intro followed by free exploration, commerce secondary | User | Craft leads, visitors choose their next step |
| Full direction brief, including restrained geometry and proposed journey | User approval, 2026-11-09 | Approved after three interview rounds |
| Attachments inspiration/modeling references, not publication assets | User clarification | Non-real shop; no publishing rights granted |
| PHP/Twig/vanilla JS, procedural Three.js, commerce/admin requirements | Existing-preserved | Current course/architecture constraints; no rewrite requested |
| Spacing/type scale, light-only theme, responsive viewer arrangement | Existing-preserved | Retain useful foundations unless actual specimens reveal a conflict |

**M0 implementation choices:** local Bricolage Grotesque/DM Sans, canonical warm palette,
original SVG shell motifs and fictional product illustrations/configs. These are engineering
selections under issue #1, not retroactive user approval of exact fonts/colors.

**Still open before affected milestones:** final wordmark treatment, overlay shadows/easing,
additional original/authorized assets, and any verified attribution. No reference attachments
may be published. M0 seed configs do not establish actual viewer/model behavior.

**Approval scope:** the original interview approved the direction/specification. Requests
to implement issues #1 and #2 authorize M0/M1, not later viewer/commerce/admin milestones.

## 13. M0 Foundations

Preview: follow [README](../README.md), then open `/`. M1 replaces the original foundation
specimen with craft discovery, importing the same canonical fonts, palette and `app.css`.

| API/source | Use and states |
|---|---|
| `.btn.btn--primary` | Anchor navigation or native form submission, with default/hover/focus-visible states. M1 usage is documented in §14. |
| `.nav-link`, `.link-quiet` | Named text links for existing routes/anchors. Hover underline/color and visible focus; no selected or validation state. Use a real button instead if an action changes application state. |
| `.skip-link` → `main#main-content[tabindex="-1"]` | First keyboard link, revealed on focus; moves to the main content. No dialog or focus trap exists. |
| `.demo-tag`, `.site-footer__notice` | Static, explicit educational/fictional identification. Not a stock, success or payment badge. |
| `icons.icon(name)` in `views/macros/icons.twig` | `weave`, `arrow-down`, `arrow-right`, `arrow-left`; decorative SVG alongside named text. Import explicitly in each consuming Twig template. Never pass product/user HTML or rely on an icon alone for a name. |

Good navigation composition (implemented API):

```html
<a class="btn btn--primary" href="#craft">Discover the weave</a>
```

Bad usage (not shipped): `class="btn btn--danger"` invents an unimplemented variant;
an anchor with `href="#"` and an Add-to-cart label would imply nonexistent behavior.

Fonts are local WOFF2 with `font-display: swap`; Bricolage Grotesque display and DM Sans
400/500/700 UI text. SIL OFL 1.1 notices in `public/assets/fonts/` retain author/source links:
[Bricolage](https://github.com/ateliertriay/bricolage) and
[DM Sans](https://github.com/googlefonts/dm-fonts). CSS/SVG framing and seeded illustrations
are original procedural assets, not supplied photographs. No custom font/logo or actual maker
provenance is claimed.

The foundations are flat, light-only and motion-free. No loading animation, modal or drawer
has been added merely to demonstrate a planned component.
Screen review instructions: `design-system/skills/review-screen/SKILL.md` (manual guide).

### Font provenance (downloaded 2026-11-09)

Source metadata: [Bricolage Grotesque v9](https://gwfh.mranftl.com/api/fonts/bricolage-grotesque)
and [DM Sans v17](https://gwfh.mranftl.com/api/fonts/dm-sans). Latin WOFF2 subsets, unmodified,
SIL OFL 1.1; intended use is local shell/display and UI text respectively.

| Local font file (`public/assets/fonts/`) | Download source |
|---|---|
| `bricolage-grotesque-latin-variable.woff2` | [Google Fonts v9](https://fonts.gstatic.com/s/bricolagegrotesque/v9/3y9U6as8bTXq_nANBjzKo3IeZx8z6up5BeSl5jBNz_19PpbpMXuECpwUxJBOm_OJWiaaD30YfKfjZZoLvfzl-Molsg.woff2) |
| `dm-sans-latin-400.woff2` | [Google Fonts v17 regular](https://fonts.gstatic.com/s/dmsans/v17/rP2tp2ywxg089UriI5-g4vlH9VoD8CmcqZG40F9JadbnoEwAopxRSW32.woff2) |
| `dm-sans-latin-500.woff2` | [Google Fonts v17 medium](https://fonts.gstatic.com/s/dmsans/v17/rP2tp2ywxg089UriI5-g4vlH9VoD8CmcqZG40F9JadbnoEwAkJxRSW32.woff2) |
| `dm-sans-latin-700.woff2` | [Google Fonts v17 bold](https://fonts.gstatic.com/s/dmsans/v17/rP2tp2ywxg089UriI5-g4vlH9VoD8CmcqZG40F9JadbnoEwARZtRSW32.woff2) |


## 14. Implemented M1 Discovery & Catalog

`/` is the real craft preview; `/products` is the quiet catalog. Both use canonical
`app.css`. Runtime scenarios and measured narrow/wide results are recorded in BUILD M1.

| API/source | Use and supported states |
|---|---|
| `.hero__actions` in `home.twig` | Discover the weave → `#craft`; Skip introduction → `#explore`, before the large illustration. Native scrolling, persistent navigation, no autoplay or scroll lock. |
| `.material-study` / native `details > summary` | Independent natural-fiber/colorful-textile studies in `#explore`. Closed/open, click/touch/Enter/Space, visible focus and native expanded semantics. No required order, purchase or JS. |
| `.material-study__weave` / `--textile` | Original CSS pattern diagrams with accessible image descriptions and explanatory text; not verified material samples or named traditional textiles. |
| `_product-card.twig` | Repeated on home and catalog; receives one public ProductService row. List item + article, separate image/name links, price/category/material/origin, In stock / Only N left / Sold out text. No nested actions or hover-only controls. |
| `.collection-filters__input`, `__select` | Labeled native search/category/sort controls, resting boundary via `--color-control-border`, focus ring, values retained after GET submission. No price-range or stock-only filter. |
| `.btn.btn--primary` | Discovery/collection anchors and native Apply filters submit. Default/hover/focus-visible. No fake loading/disabled/async states; no secondary/danger variants shipped. |
| `.pagination__link` / `--disabled` | Previous/Next preserve filters. Unavailable direction is noninteractive `aria-disabled` text; page status stays visible. |
| `.empty-state--filtered` / `--catalog` | No matches offers Clear filters; no active bags offers Explore the weave. Neither hides navigation or claims a successful purchase. |
| `.product-detail` in `product.twig` | Read-only real seed data, image/price/stock/dimensions and fictional labels, with Back to collection. Full R3 controls/viewer and cart remain later milestones. |

`--color-stock-low` supplies the readable low-stock foreground on the page surface;
stock always has a text cue. Controls and product-name links retain ≥44 px touch
height. Keyboard focus on image links is not clipped by the card.

Good composition (implemented API):

```twig
<ul class="product-grid">
  {% for product in products %}
    {% include 'storefront/_product-card.twig' with { product: product } only %}
  {% endfor %}
</ul>
```

Bad usage (not shipped): wrapping the whole card in another anchor creates nested
links; adding a decorative Add to bag link would imply unavailable M4 behavior.
Cards are for quiet inspection, not patterned craft backgrounds.

Asset provenance: `public/assets/img/craft-weave.svg` and the material-study CSS diagrams
are original procedural artwork created for issue #2 on 2026-11-09, intended for Habi's
fictional craft introduction/exploration. No supplied references, external photographs,
maker biographies or traditional-pattern attribution are published. Existing font
licenses and original seeded bag illustration provenance remain unchanged.

