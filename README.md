# Habi

Educational Filipino-inspired handbag storefront. Products, illustrations and origin labels are fictional demonstrations, not real goods or verified maker stories. M1 supplies craft discovery and a searchable SQLite catalog; **M2 adds product inspection and a procedural exterior 3D viewer.** Peek inside (M3), cart (M4), checkout, admin UI and deployment belong to later [milestones](docs/BUILD.md).

## Local setup — three steps

Requires PHP **8.2+**, Composer 2, and PHP extensions `pdo_sqlite`, `mbstring`, `dom`, `xml`, `xmlwriter` (the XML extensions support the development tools). No Node build, database server or CDN is needed.

1. Install pinned PHP dependencies:
   ```sh
   composer install
   ```
2. Copy and configure local settings:
   ```sh
   cp .env.example .env
   php -r 'fwrite(STDOUT, "Local admin password: "); echo password_hash(rtrim(fgets(STDIN), "\r\n"), PASSWORD_DEFAULT), PHP_EOL;'
   ```
   Choose a local-only password; the prompt echoes input, so use a private terminal. Paste the generated hash into `ADMIN_SEED_PASSWORD_HASH` in `.env`, keeping **single quotes**. Set `ADMIN_SEED_EMAIL` and optionally `ADMIN_SEED_NAME`. Never put a plaintext password in `.env` or commit it. Process environment values override `.env` settings.
3. Start the development server from the repository root:
   ```sh
   php -S localhost:8000 -t public
   ```
   Open <http://localhost:8000>. The first request initializes `data/app.sqlite` and seeds 10 fictional bags across four categories plus your configured administrator. Later requests/restarts preserve data and do not reset credentials. Admin login is not implemented until M6.

`DB_DSN=sqlite:data/app.sqlite` resolves relative to the repository root; an absolute SQLite path is also supported. The database directory must be writable. Missing/invalid first-boot admin settings prevent initialization rather than creating a default password. Fix `.env` and request the page again. No credentials or internal errors are shown in the browser; inspect server stderr for local diagnosis.

## Data and intentional reset

Money is integer centavos (₱1 = 100). Product images are original local SVG illustrations; each product has a procedural `three_config` consumed by the exterior viewer. Schema: [`migrations/001_schema.sql`](migrations/001_schema.sql), seed: [`seed/`](seed/).

```sh
php seed/seed.php --force
```

**Destructive:** this explicitly resets demo data, including orders, inventory and administrator, using current environment settings. Do not run against data you need. Running without `--force` refuses the reset.

## Checks

```sh
composer validate --strict
vendor/bin/phpunit
vendor/bin/phpcs --standard=PSR12 src public/index.php seed tests
find src public seed tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

PHPUnit uses isolated databases, not your local demo inventory. Catalog coverage includes search/category combinations, price/newest order, pagination, inactive products, sold-out visibility and empty results; inspection coverage includes active-only configs and ordered galleries. Inspect the actual changed surface at 360 px and wide widths with keyboard navigation; PHP checks do not establish visual accessibility. On product pages, also exercise viewer loading/retry, WebGL fallback, pause/reduced motion and mobile dialog close/Esc/focus return. Exact observed scenarios/timings are recorded in [BUILD M2](docs/BUILD.md#m2--product-detail--3d-viewer-mvp-a--b--c).

## Architecture and scope

`public/index.php` → Slim 4 → `StorefrontController` → `ProductService`/PDO → Twig 3; PSR-4 `Habi\` classes live in `src/`. Support helpers load environment settings and provide PDO SQLite, with first-boot transactional initialization. Templates autoescape HTML. Later milestones add commerce mutations, sessions and CSRF.

| Route | Available behavior |
|---|---|
| `/` | Short skippable introduction, material/bag exploration without purchase, optional featured collection |
| `/products?q=&category=&sort=&page=` | Active products, literal text search over name/description/materials, category, `newest`/`price_asc`/`price_desc`, six products per page |
| `/product/{slug}` | Original-image gallery, details/stock, illustrative labels, exterior 3D; quantity/Add to bag visibly disabled until M4 |
| `/api/product/{slug}/stock` | Uncached JSON `{slug, stock, low_stock_threshold}`; missing/inactive products return JSON 404 |
| `/api/product/{slug}/3d` | Uncached per-product procedural JSON config; missing/inactive products return JSON 404; sold-out products remain inspectable |

Search and category combine; pagination retains those filters and sorting. Out-of-range pages use the nearest available page. Sold-out products remain visible; inactive products do not. A missing/inactive product detail returns 404.

Canonical visual source: [`public/assets/css/app.css`](public/assets/css/app.css); design and asset policy: [DSD](docs/DSD.md). Use semantic tokens instead of ad hoc component colors. Font license files are shipped with the fonts; supplied reference images are not website assets.

### Exterior viewer

Open a product and choose **3D view**. Desktop inspection is inline; below 900 px
it opens a fullscreen dialog. Drag/touch to orbit or use the named rotation buttons.
Idle rotation has pause/resume; reduced motion disables automatic rotation and inertia.
Photos remain available without JavaScript. If WebGL is unavailable, the original
product illustration remains with an explanatory note; fetch/module failures offer retry.
This is exterior-only inspection, not Peek inside or an Add-to-Cart implementation.

Three.js **0.160.1 / r160**, MIT, is vendored locally: official
[`three.module.min.js`](https://cdn.jsdelivr.net/npm/three@0.160.1/build/three.module.min.js)
is saved as `public/assets/vendor/three/three.module.js`, alongside the matching
[`OrbitControls.js`](https://cdn.jsdelivr.net/npm/three@0.160.1/examples/jsm/controls/OrbitControls.js)
and [`LICENSE`](public/assets/vendor/three/LICENSE). The import map uses
`three.module.php?v=0.160.1`: this small PHP adapter negotiates compression with
PHP's **zlib** extension, needed for the recorded N1 timing on the uncompressed
development server. Upgrade both modules from the same release, retain its license,
and change the import-map version to invalidate immutable caching. No CDN is
contacted at runtime and no frontend build step is required. Geometry and
canvas-generated material patterns are original Habi demo artwork, not supplied
reference photographs or downloaded models.

Requirements: [PRD](docs/PRD.md) · architecture: [SDD](docs/SDD.md) · [issue #3](https://github.com/fr-anz/COMP-20163-WEB-DEVELOPMENT/issues/3). N6 local execution is M0 scope; Docker, deployment and persistence on a hosted volume remain M8. No real payments are processed. Local implementation does not imply human PR review, merge or deployment.
