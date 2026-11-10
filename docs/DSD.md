# DSD — Habi Design System

| | |
|---|---|
| Version | 2.0 · 2026-11-10 |
| Authority | [Published Habi UI and design system](https://fit-igloo-66636772.figma.site/) |
| User decision | **Follow the published UI and design system 1:1. Replace the current and previous visual direction; do not blend or reinterpret them.** |
| Implementation | PHP/Twig, canonical `public/assets/css/app.css`, native JavaScript and the real Three.js viewer |
| Requirements | [PRD](PRD.md) owns R#/N# and business rules; [BUILD](BUILD.md) records exercised checks and shipped scope |

## 1. Source of truth

The published system and its **Application Examples** define the storefront's visual
contract. Use the actual rendered components and stylesheet, not just their prose
summaries. Match component anatomy, artwork, type weights, dimensions, spacing,
backgrounds, responsive behavior and interaction states. The surrounding design-system
sidebar, preview frame, viewport selector and “Demo state” control are showcase tools,
not storefront chrome.

The earlier cream footer, wrapped mobile navigation, permanently visible header search
field, custom three-bag hero, two material disclosures and uniform featured-product grid
are superseded. They are not permitted alternatives to the reference.

Adaptation is limited to real application state and PHP integration: existing product
IDs, stock, prices and routes remain data-driven; reference sample cart counts/orders
must not be fabricated. Keep the working procedural Three.js renderer instead of the
showcase's conceptual CSS model. M3 adds real interior cutaways; M4 adds the session
cart; M5 adds simulated checkout and atomic orders; M6 adds the admin panel. Where
sample business values differ, [PRD §9](PRD.md#prototype-vs-requirements) prevails;
that does not permit changing the visual treatment.

## 2. Canonical CSS and tokens

`public/assets/css/app.css` contains the reference foundations, shared native component
classes and the utility rules actually consumed by the Twig/JavaScript implementation.
No Tailwind build, React runtime, CDN stylesheet or second token file is required.
Reference utility names such as `bg-page`, `text-ink`, `border-line` and container-query
variants are deliberate parts of this clean cutover, not a second legacy system.

Raw primitives live once in CSS. Components use the published purpose-named roles;
old `--color-page`, `--color-surface`, `--color-action` and similar aliases are removed.
SVG artwork is a self-contained publication asset and carries its own illustration colors.

| Primitive | Value |
|---|---|
| `--habi-cream-50` / `--habi-cream-100` | `#FFFCF6` / `#F8F4EA` |
| `--habi-fiber-200` / `--habi-fiber-300` / `--habi-fiber-500` | `#EDE1CB` / `#DCCBAB` / `#B08F62` |
| `--habi-brown-900` / `--habi-brown-600` / `--habi-brown-400` | `#352820` / `#6D5A4B` / `#8C7764` |
| `--habi-rust-600` / `--habi-rust-700` | `#A8462C` / `#873620` |
| `--habi-yellow-400` / `--habi-green-700` / `--habi-blue-700` | `#E8B94D` / `#436D51` / `#426A94` |

| Semantic role | Binding / value |
|---|---|
| `--color-bg-page` / `--color-bg-surface` / `--color-bg-inset` | cream-100 / cream-50 / fiber-200 |
| `--color-bg-raised` | White |
| `--color-text-primary` / `--color-text-secondary` / `--color-text-inverse` | brown-900 / brown-600 / cream-50 |
| `--color-border-default` / `--color-border-strong` | `#DCCFB8` / brown-400 |
| `--color-action-primary` / `--color-action-primary-hover` / `--color-action-foreground` | rust-600 / rust-700 / cream-50 |
| `--color-focus-ring` | blue-700 |
| `--color-status-success` / `--color-status-success-bg` | `#3B6346` / `#E5EEE3` |
| `--color-status-warning` / `--color-status-warning-bg` | `#7A4E06` / `#F9EDCF` |
| `--color-status-error` / `--color-status-error-bg` | `#9E2F1D` / `#F8E3DC` |
| `--color-status-info` / `--color-status-info-bg` | `#375A80` / `#E3EBF3` |

Brown blocks, cream/fiber surfaces and bounded colored weave are all intentional.
Do not replace the dark CTA/footer with cream, or turn decorative yellow/blue/green
into competing primary actions. Status uses icon + text, never color alone.

## 3. Type, wordmark and geometry

- **Bricolage Grotesque:** actual reference variable font, weights 400–800 and optical
  sizing; brand, hero, section headings and product title. Wordmark uses optical size 96,
  lowercase `hab` + dotless `ı`, −0.035em tracking and the reference's 2×2 woven tittle.
  Header lockup includes the separate multicolor woven mark; wordmark sizes are 26 px
  in the header and 34 px inverse in the footer.
- **DM Sans:** actual reference variable font, weights 400–700, including real 600;
  body, navigation, controls, product cards, prices, labels and statuses. Do not substitute
  the previous static 500/700 files for semibold. Prices use tabular numerals and retain
  nonzero centavos; whole pesos may omit decimal zeros.
- Display 48 px / 1.08; H1 36 px / 1.15; H2 28 px / 1.15; H3 22 px / 1.2;
  body 16 px / 1.6, small 14 px / 1.5, caption 12 px / 1.4. Use the actual component's
  responsive classes: hero 36→48 px, product/catalog H1 30→36 px, craft titles 28→36 px.
  Balanced headings, exact widths and margins determine wrapping; do not replace them
  with a different fluid clamp.
- Four-pixel spacing rhythm. Container-query thresholds: `@3xl` 768 px, `@4xl` 896 px,
  `@5xl` 1024 px. The outer app is an inline-size container, not the showcase frame.
- Maximum shell width **1280 px including padding**: 16 px sides below 768 px, 32 px
  above it. Header height 64 px. Radius 4 px for small pieces, 6 px for controls, 12 px
  for panels. No pill redesigns.
- Reference flat/bordered surfaces, popover and overlay shadows remain exact CSS tokens.
  Motion uses 120/240/420/700 ms and `cubic-bezier(0.2, 0.7, 0.2, 1)` as defined by the
  source. Respect reduced motion and hover-capable pointers; motion must not gate content.

## 4. Screen contract

### Shell

Sticky translucent cream header: mobile menu button, woven mark + wordmark, desktop
Discover / Collection / Materials links, search icon and bag icon. Search navigates to
the actual catalog search, not a permanently expanded header field. The mobile menu is
a real accessible drawer/dialog, not wrapped desktop links. No fake sample bag count.
The footer is **dark brown**, inverse wordmark, Explore links and the educational-demo
notice with its info icon, preceded by the colored checker selvedge.

### Discovery (`/`, R1)

1. Exact split hero: original strand headline/copy/actions and the **six-column,
   six-row mosaic**. A framed bucket illustration sits beside textile swatches; yellow,
   green and blue strands pass over/under the panels at the reference's z-order. Desktop
   split starts at 1024 px; mosaic height 340→480 px. No explanatory caption below it.
2. Fiber inset introduction: heading/copy split, followed by three adjoining cream
   process cards with the exact Strand / Over & under / Pattern diagrams and numbering.
3. Four-way material explorer: Natural fiber / Flat strip / Yarn inlay / Loom cloth;
   swatch, pinned label, description, characteristics and links. Vertical tab rail above
   896 px, horizontal rail below it. Arrow/Home/End keyboard support and selected state;
   usable material content without JavaScript. No substitution with two disclosures.
4. Knot divider and asymmetric featured editorial: one large framed product/material
   presentation plus two smaller cards. Real product links/data; not a uniform three-card grid.
5. Full-width **dark brown CTA**, inverse headline/copy, cream action and bounded textile
   strip; continue into the matching dark footer.

### Catalog (`/products`, R2)

Reference display heading and quiet divider; search/category/sort grid in a 2:1:1 split
at 768 px, stacked below. Search icon inside the field. Results status and the actual
Hide sold-out bags checkbox share the next row. Native GET values/pagination remain
shareable; JavaScript enhances submission without making no-JS browsing unusable.

Eight rows per page, with 2/3/4 card columns at <768 / ≥768 / ≥1024 px. Cards use
neutral 4:5 reference illustrations, caption category, semibold DM Sans name, a quiet
material/variant line, then price + stock chip. No sun/moon poster illustrations,
verbose provenance paragraphs on cards, rounded filter-panel redesign or hover-only actions.

### Product (`/product/{slug}`, R3 inspection, R4–R6/R27)

Breadcrumb chevrons; gallery/viewer on the left and information on the right in a
1.15:1 split at 900 px. Desktop media is square and sticky; narrow media is 4:5 and
3D opens fullscreen. Match the Images / 3D segmented control, gallery frame/thumbnails,
Bricolage title, material line, price + status row and bordered purchase panel.

Materials/provenance and dimensions are **open sections**, not the previous accordions.
Use the reference's icon headings, labeled data rows, inset provenance notice and
measurement diagram. Shipping-estimate cards use PRD R12's values and are identified as
planned estimates, not a claim that shipping/checkout already works.

Keep real loading, failure/retry, WebGL image fallback, rotation/tilt/reset/pause,
reduced-motion behavior and mobile focus containment. No “Demo state” select or CSS model
pretending to be the real viewer. No invented interior images, technique, handle-drop
measurement, maker or geographic provenance. M4 enables stock-bounded quantities and
real Add to bag; sold-out products cannot be added.

M3 uses the published secondary small Peek inside / Back outside button as a sibling
of the persistent orbit toolbar, with its eye/up glyphs and an interior info chip.
Keep wrapping at narrow widths, persistent hint/status text and the same focusable stage.
The actual cutaway shows authored lining/pockets, not invented interior photographs;
an empty pocket configuration stays empty. Keyboard/reduced-motion switches are immediate.

### Bag (`/cart` and drawer, R7–R9/R17/R28)

Use the published cart composition: 1180 px page shell, 30→36 px display heading,
line list and 360 px Summary column above the 896 px container breakpoint. Lines
retain illustration, linked name, material label, line price, bounded quantity
stepper and labeled Remove. Drawer uses the same anatomy in a right-side panel,
full-width on narrow screens, with Close and a full-page alternative.

Summary keeps the region estimate, quantity count, subtotal and estimated total;
PRD R12 rates replace prototype sample values. Persistent stock corrections are
icon+text notices, not disappearing toast-only explanations. Add confirmation and
the header badge reflect real session state. Native `/cart` forms remain available
without JavaScript; dialog enhancement contains focus and returns it on close/Esc.
Session lifetime is explained without saved-cart promises; Proceed to checkout opens the real guest flow.

### Checkout and confirmation (R10–R17)

Follow the published Checkout/Confirmation examples: responsive stepper, shipping
form and summary, clearly simulated card form, review and received-order composition.
Native forms enforce Shipping → Payment → Review → Place → Confirmation without
JavaScript; enhancement adds processing status rather than a second checkout state.
Use server totals and PRD R12 fees, not the prototype's sample rates.

Show inline field errors with persistent labels and text/icon feedback. Declines
preserve shipping and bag contents. Review includes the already-issued retry key;
stock/price conflicts return to the corrected bag with an explanation. Confirmation
uses session-owned order snapshots, `H-YYYYMMDD-NNNN`, a clearly simulated delivery
estimate and the simulation notice. Never fabricate a successful order to match the
reference or retain entered card details in browser storage.

The name-on-card field reflects the already validated shipping name read-only;
Back to Shipping edits it, with no second stored identity. Optional address line 2
comes from the order schema. No “remember on this device” checkbox is offered:
shipping already lasts only for this session, with no persistent-device feature.
These data/privacy adaptations and binding Luhn/CVC/test-card text are not permission
to change the reference's component styling.

### Admin (`/admin`, R18–R23)

Follow the published Admin application example and Admin components: no storefront
header/footer and no woven textures behind data. A 232 px surface sidebar (woven mark,
22 px wordmark, Admin tag, 40 px items with icon + label, inverted current item, Log out
pinned last) appears from the 896 px container breakpoint; below it the same items form a
horizontally scrolling row and tables scroll inside their bordered frame. A dashed
demo-data notice heads every page; its wording states the real situation (fictional
products, simulated orders) instead of the reference's "Planned feature" sample text.

Dashboard: four metric cards (Active products, Units in stock, Low or sold out with the
threshold caption, Orders to fulfil), low-stock alerts with Adjust and the recent-orders
table. Products and Inventory: "Filter by name" + Stock status filters, the published tables
(thumbnail/name/category/price/status/edit; on-hand count + stock bar/status/Adjust stock),
the Edit product dialog and the two-step Adjust stock → Confirm stock change dialog. Orders:
Status filter and table; Stock audit: direction-icon rows with signed change, old → new,
reason · actor and time. Order status chips are icon + label; paid reads "Paid (simulated)".

Real-data adaptations, not visual changes: Add product (primary small action beside the
heading) and every R20 field in the 520 px product dialog (SKU, threshold, demonstration
material/place-of-make labels, dimensions, 3D model, demo illustration); "Archived" filter
and chip; From/To date filters; data-driven captions ("N placed today"); an order detail
page composed from the same panels/table/chips with one-step fulfilment and the destructive
cancel dialog; the sign-in panel composed from the admin lockup, Password field with Show
and checkout's alert. The reference's "Packing" label is shown as the stored status
"Packed". Orders use real `H-YYYYMMDD-NNNN` numbers, never sample data.

## 5. Implemented component APIs and compositions

| API / source | Contract |
|---|---|
| `.btn`, `.btn--primary`, `.btn--secondary`, `.btn--lg` | Reference native action/navigation treatments, states and dimensions. Use links for navigation, buttons for state. Disabled purchase actions explain why. |
| `.icon-btn` | 44 px named icon action; keep the label accessible and glyph decorative. |
| `.input`, `.field-label`, `.field-hint`, `.check` | Reference persistent labels, native field semantics, strong boundaries and focus treatment. |
| `.chip` + status roles | Stock icon + words; success, warning and sold-out/error pairs. No ratings or New badges. |
| `.link`, `.eyebrow` | Reference low-emphasis navigation and category labels. |
| `icons.icon(name, size = 24, class = '')` in `views/macros/icons.twig` | Shared 1.75 px round-stroke family, with the reference's per-control sizes and optional utility classes. Only names defined in the macro are supported. |
| `_product-card.twig` | Repeated on catalog and discovery; escaped ProductService data, exact price formatting, separate product destination and native Add to bag form with no nested controls. |
| `data-*` storefront controls | Progressive mobile navigation, material tabs and catalog GET submission; no framework or fake application state. |
| `product.js` + `BagViewer` | One real renderer, gallery/mode switching, persistent Peek inside / Back outside controls, live status, retry/fallback and native narrow-screen dialog. `setInside(inside, {animate})` emits `viewchange`; reset stays in the current inspection mode. |
| `cart.js` + `_cart-content.twig` | Native cart forms enhanced with authoritative server fragments; synchronized header count, drawer/page lines and totals, persistent stock notices, add feedback, named quantity/remove controls and accessible native dialog. |
| `checkout.js` + `checkout-{shipping,payment,review}.twig` | Native PRG forms, server-owned progress/totals, inline errors, first-error/step focus, visible busy status/spinner, reduced motion and card clearing on pagehide/pageshow. `_checkout-stepper.twig` and `_checkout-summary.twig` share responsive progress and native summary disclosure. |
| `order.twig` | Session-owned order snapshots, simulated status/ETA and payment disclaimer; no gateway or shipping action is fabricated. |
| `views/admin/layout.twig` + `_macros.twig` | Admin shell (sidebar/scroll nav, demo notice, flash) and helpers: `money`, `stock_chip`, `status_chip`, `status_label`, `input`, `select`, `field_error`, `pagination`, `flash`. Templates set `dialog_open` to make the shell `inert` behind a dialog. |
| `views/admin/_dialog.twig` (`{% embed %}`) | Published dialog anatomy (brown/45 scrim, surface panel, header with Close, scrolling body, page-coloured footer) as a server-rendered overlay reached by URL; optional wrapping form. Import macros inside each embed body. |
| `.btn--destructive` | Reference destructive action (Archive…, Cancel order) — white on status-error. |
| `admin.js` | Dialog initial focus (first invalid field, first field, else Close), Tab containment, Esc to the close URL; filter auto-submit; password Show/Hide; chosen-file name and early 2 MB warning. Everything works without it. |
| `.touch-target`, `.touch-link`, `.touch-stepper` (M7) | Coarse-pointer only: compact reference controls (`.btn--sm` too), inline text links and 44 px stepper buttons inside a 1.5 px border reach a 44 px touch target. Fine-pointer layouts keep the published sizes. Stretched card links already cover the whole card. |
| `views/layouts/base.twig` head blocks (M7, R26) | `title`, `description` (text only), optional `robots` and `og_type`; base renders the meta description, canonical, Open Graph/Twitter card (absolute `site_url` ~ `canonical_path`, `/assets/img/og-habi.png`) and favicons once. Session pages (bag, checkout, order) set `noindex`. |
| `ErrorHandler::page(status)` | Standalone 404/405/500 page with the storefront lockup, footer demo notice and two recovery actions; no Twig, session or database, so it renders after failed initialization. |

Good navigation:

```html
<a class="btn btn--primary btn--lg" href="#craft">Discover the weave</a>
```

Bad: `href="#"` labeled Add to bag suggests an unavailable mutation; decorative textile
colors on competing buttons discard the reference's action hierarchy. Do not add
undocumented variants or recreate tokens in individual templates.

Checkout templates consume the published composition with real server state; admin
templates consume the published Admin example the same way.

## 6. Assets, fonts and provenance

The implementation ports the **rendered SVG artwork from the user-approved published
application**: `public/assets/img/reference/` contains the brand/tittle, hero panels,
process diagrams, four material swatches, divider, textile band and footer selvedge.
`public/assets/img/products/*.svg` uses that same published illustration family for the
existing fictional catalog. These are stylized illustrations, not photographs or proof
that an actual maker/material/origin exists. The old illustration direction is removed.
Supplied reference photographs remain inspiration only and are not published or traced.

Both font families retain their shipped SIL OFL 1.1 licenses in `public/assets/fonts/`.
Exact normal reference variants, downloaded 2026-11-09 from Google Fonts:

| Local file | Source |
|---|---|
| `bricolage-grotesque-latin-variable.woff2` | [Bricolage v9 Latin](https://fonts.gstatic.com/s/bricolagegrotesque/v9/3y9K6as8bTXq_nANBjzKo3IeZx8z6up5BeSl9D4dj_x9PpZBMlGIInE.woff2) |
| `bricolage-grotesque-latin-ext-variable.woff2` | [Bricolage v9 Latin-ext](https://fonts.gstatic.com/s/bricolagegrotesque/v9/3y9K6as8bTXq_nANBjzKo3IeZx8z6up5BeSl9D4dj_x9PpZBMlGGInHEVA.woff2) |
| `dm-sans-latin-variable.woff2` | [DM Sans v17 Latin](https://fonts.gstatic.com/s/dmsans/v17/rP2Hp2ywxg089UriCZOIHQ.woff2) |
| `dm-sans-latin-ext-variable.woff2` | [DM Sans v17 Latin-ext](https://fonts.gstatic.com/s/dmsans/v17/rP2Hp2ywxg089UriCZ2IHSeH.woff2) |

Latin-ext includes the peso glyph; the Latin subset includes dotless ı. All fonts are
local at runtime. This Make showcase supplies CSS/components, not native Figma variables
or component sets; do not claim those were created.

## 7. Verification and future changes

Follow [the screen review guide](../design-system/skills/review-screen/SKILL.md). Compare
actual native-width app screens against the published components at 1440, 768 and 360 px,
including mobile menu, selected material, catalog filters/empty state and product modes.
A successful compile or token match does not establish 1:1 visual fidelity.

Maintain keyboard access, visible focus, relevant 44 px icon targets, text/status cues,
reduced motion and WCAG AA contrast. Preserve original data/security requirements. Measure
actual foreground/background pairs and inspect the rendered surfaces; do not certify the
whole application from a palette table. Run directly affected service checks and exercise
the real UI. Current results and any precise differences belong in BUILD, not an invented
pixel-perfect claim.

Later changes must update the canonical source and affected consumers together. Do not
add old-token aliases, alternate mobile navigation, local color guesses or visual “improvements”
that depart from the approved reference.
