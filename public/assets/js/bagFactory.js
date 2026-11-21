/**
 * bagFactory.js — procedural bag geometry for the Habi 3D viewer (SDD §11, R6).
 *
 * Builds a bag mesh group from a `three_config` object (shape, dimensions in
 * cm, colors, handle_style, material, lining, pockets, optional pattern).
 * All geometry is generated at runtime — no model files.
 *
 * Surface: each bag has a base weave texture per `material`
 * (rattan/canvas/leather) drawn in neutral greys and tinted by `colors.body`,
 * plus a Sobel-derived normal map from the same canvas. When the config
 * carries a `pattern` descriptor, the body instead gets a full-color canvas
 * pattern (checker / stripes / pixel-diamond) painted to match the product's
 * SVG illustration, with horizontal mosaic `bands` built as separate wrapped
 * trim pieces; an unknown or missing pattern falls back to the plain
 * material texture so admin-created bags still render.
 *
 * Pattern descriptor (all optional except kind/colors; validated defensively,
 * anything invalid falls back to the material texture):
 *   { kind: 'checker', cell: cm, colors: [light, dark],
 *     bands: [{ at: 0..1, height: 0..1, colors: [...] }] }   // mosaic bands
 *   { kind: 'stripes', period: cm, widths: [fractions], colors: [...],
 *     lines: cm }                                            // + weave lines
 *   { kind: 'pixel', tile: cm, grid: n, base, motif, accent } // cross-stitch
 * `colors` may also carry optional `handle` / `handle_secondary` hex accents
 * (fallback: `trim`).
 *
 * Silhouettes are lofted shells through stacked rounded-rect rings; every
 * catalog shape is an open-top container (tote, satchel, sling bucket) with
 * a walled shell and an inset lining cup, except the clutch which adds a
 * hinged envelope V-flap lying flush on the front. Edges carry dashed
 * stitch lines (one InstancedMesh per bag) and brass/rivet hardware where
 * the illustration shows anchors. Handles are arched tubes with end tabs.
 *
 * Group metadata contract consumed by viewer3d.js:
 * - userData.shape            shape id
 * - userData.bodyDimensions   config.dimensions (cm)
 * - userData.interior         THREE.Group holding lining + pockets
 * - userData.flap             THREE.Group hinged at the back rim, or null
 *                             for open-top shapes; rotation.x = 0 is the
 *                             exact closed state; Peek folds it back
 * - userData.handles          rim-axis pivots with physical open angles
 * - userData.interiorTarget   THREE.Vector3 focus point in bag-local coords
 */
import * as THREE from 'three';

const MATERIAL_ROUGHNESS = { rattan: 0.72, canvas: 0.95, leather: 0.48 };
const BRASS_COLOR = 0xc9a24b;
const DARK_METAL_COLOR = 0x352820;

/* ---------------------------------------------------------------- helpers */

/** Deterministic PRNG so generated texture noise is stable per session. */
function mulberry32(seed) {
    let a = seed >>> 0;
    return function next() {
        a |= 0; a = (a + 0x6D2B79F5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function makeDrawingCanvas(size) {
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    return canvas;
}

function makeCanvas(width, height) {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    return canvas;
}

/** Rounded-rect trace on a 2D context (arcTo; no reliance on ctx.roundRect). */
function traceRoundedRect(ctx, x, y, w, h, r) {
    const rr = Math.max(0.5, Math.min(r, w / 2, h / 2));
    ctx.beginPath();
    ctx.moveTo(x + rr, y);
    ctx.arcTo(x + w, y, x + w, y + h, rr);
    ctx.arcTo(x + w, y + h, x, y + h, rr);
    ctx.arcTo(x, y + h, x, y, rr);
    ctx.arcTo(x, y, x + w, y, rr);
    ctx.closePath();
}

/** Rounded-rect path added to an existing Shape/Path (used for bodies and holes). */
function addRoundedRect(path, width, height, radius) {
    const w = width / 2;
    const h = height / 2;
    const r = Math.max(0.01, Math.min(radius, w, h));
    path.moveTo(-w + r, -h);
    path.lineTo(w - r, -h);
    path.absarc(w - r, -h + r, r, -Math.PI / 2, 0, false);
    path.lineTo(w, h - r);
    path.absarc(w - r, h - r, r, 0, Math.PI / 2, false);
    path.lineTo(-w + r, h);
    path.absarc(-w + r, h - r, r, Math.PI / 2, Math.PI, false);
    path.lineTo(-w, -h + r);
    path.absarc(-w + r, -h + r, r, Math.PI, Math.PI * 1.5, false);
    path.closePath();
    return path;
}

function roundedRectShape(width, height, radius) {
    return addRoundedRect(new THREE.Shape(), width, height, radius);
}

/** Extrude a shape along +Z and re-center it on Z=0. */
function extrudeCentered(shape, depth, bevel) {
    const geometry = new THREE.ExtrudeGeometry(shape, {
        depth: depth,
        bevelEnabled: bevel > 0,
        bevelThickness: bevel,
        bevelSize: bevel,
        bevelSegments: 3,
        curveSegments: 12,
    });
    geometry.translate(0, 0, -depth / 2);
    return geometry;
}

/** Darken a #rrggbb color by a factor (pocket fabric reads a step deeper). */
function shade(hex, factor) {
    return tint(hex, factor);
}

/** Scale a #rrggbb color, clamping channels at 255 (factor > 1 lightens). */
function tint(hex, factor) {
    const value = parseInt(hex.slice(1), 16);
    const channel = (shift) => Math.min(255, Math.round(((value >> shift) & 255) * factor));
    const toHex = (n) => n.toString(16).padStart(2, '0');
    return `#${toHex(channel(16))}${toHex(channel(8))}${toHex(channel(0))}`;
}

function isHexColor(value) {
    return typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value);
}

/* ------------------------------------------------------- canvas textures */

/**
 * Sobel-derived tangent-space normal map from a greyscale weave/grain canvas.
 * Downsampled to 512 to keep the one-time cost low; wraps like the color map.
 */
function makeNormalTexture(sourceCanvas, strength) {
    const size = 512;
    const canvas = makeDrawingCanvas(size);
    const ctx = canvas.getContext('2d');
    ctx.drawImage(sourceCanvas, 0, 0, size, size);
    const src = ctx.getImageData(0, 0, size, size).data;
    const out = ctx.createImageData(size, size);
    const dst = out.data;
    const heightAt = (x, y) => {
        const i = (((y + size) % size) * size + ((x + size) % size)) * 4;
        return (src[i] * 0.299 + src[i + 1] * 0.587 + src[i + 2] * 0.114) / 255;
    };
    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            const dx = (heightAt(x - 1, y) - heightAt(x + 1, y)) * strength;
            const dy = (heightAt(x, y - 1) - heightAt(x, y + 1)) * strength;
            const inv = 1 / Math.hypot(dx, dy, 1);
            const i = (y * size + x) * 4;
            dst[i] = (-dx * inv * 0.5 + 0.5) * 255;
            dst[i + 1] = (-dy * inv * 0.5 + 0.5) * 255;
            dst[i + 2] = (inv * 0.5 + 0.5) * 255;
            dst[i + 3] = 255;
        }
    }
    ctx.putImageData(out, 0, 0);
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.NoColorSpace;
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    return texture;
}

/** Color map (SRGB) + normal map + roughness map from one greyscale weave canvas. */
function makeSurfaceMaps(canvas, repeatX, repeatY, normalStrength) {
    const map = new THREE.CanvasTexture(canvas);
    map.colorSpace = THREE.SRGBColorSpace;
    map.wrapS = THREE.RepeatWrapping;
    map.wrapT = THREE.RepeatWrapping;
    map.repeat.set(repeatX, repeatY);
    const normal = makeNormalTexture(canvas, normalStrength);
    normal.repeat.set(repeatX, repeatY);
    const rough = new THREE.CanvasTexture(canvas);
    rough.colorSpace = THREE.NoColorSpace;
    rough.wrapS = THREE.RepeatWrapping;
    rough.wrapT = THREE.RepeatWrapping;
    rough.repeat.set(repeatX, repeatY);
    return { map, normal, rough };
}

/**
 * Rattan: basket-weave of over/under strands with per-strand shading.
 * Drawn in greys; the material color provides the tint.
 */
function drawRattan(size) {
    const cells = 16;
    const cell = size / cells;
    const canvas = makeDrawingCanvas(size);
    const ctx = canvas.getContext('2d');
    const random = mulberry32(0xBADA55);

    ctx.fillStyle = '#474747'; // shadow in the gaps between strands
    ctx.fillRect(0, 0, size, size);

    for (let row = 0; row < cells; row++) {
        for (let col = 0; col < cells; col++) {
            const horizontal = (row + col) % 2 === 0;
            const x = col * cell;
            const y = row * cell;
            const tone = 0.9 + random() * 0.2;
            const grey = (v) => Math.round(Math.min(255, v * tone));
            const gradient = horizontal
                ? ctx.createLinearGradient(x, y, x, y + cell)
                : ctx.createLinearGradient(x, y, x + cell, y);
            gradient.addColorStop(0, `rgb(${grey(112)},${grey(112)},${grey(112)})`);
            gradient.addColorStop(0.45, `rgb(${grey(226)},${grey(226)},${grey(226)})`);
            gradient.addColorStop(1, `rgb(${grey(104)},${grey(104)},${grey(104)})`);
            ctx.fillStyle = gradient;
            traceRoundedRect(ctx, x + 2.5, y + 2.5, cell - 5, cell - 5, cell * 0.3);
            ctx.fill();
            ctx.strokeStyle = 'rgba(0,0,0,0.28)';
            ctx.lineWidth = 2;
            ctx.stroke();
            // strand sheen line
            ctx.strokeStyle = 'rgba(255,255,255,0.3)';
            ctx.lineWidth = 1.6;
            ctx.beginPath();
            if (horizontal) {
                ctx.moveTo(x + 4, y + cell * 0.38);
                ctx.lineTo(x + cell - 4, y + cell * 0.38);
            } else {
                ctx.moveTo(x + cell * 0.38, y + 4);
                ctx.lineTo(x + cell * 0.38, y + cell - 4);
            }
            ctx.stroke();
        }
    }
    return canvas;
}

/** Canvas textile: fine crosshatch weave with seeded fibre noise and slubs. */
function drawCanvasTextile(size) {
    const canvas = makeDrawingCanvas(size);
    const ctx = canvas.getContext('2d');
    const random = mulberry32(0xC0FFEE);

    ctx.fillStyle = '#c9c9c9';
    ctx.fillRect(0, 0, size, size);

    ctx.strokeStyle = 'rgba(0,0,0,0.10)';
    ctx.lineWidth = 1;
    for (let y = 0; y < size; y += 3) {
        ctx.beginPath(); ctx.moveTo(0, y + 0.5); ctx.lineTo(size, y + 0.5); ctx.stroke();
    }
    ctx.strokeStyle = 'rgba(255,255,255,0.12)';
    for (let x = 0; x < size; x += 3) {
        ctx.beginPath(); ctx.moveTo(x + 0.5, 0); ctx.lineTo(x + 0.5, size); ctx.stroke();
    }
    // slub lines: occasional thicker weft threads
    ctx.strokeStyle = 'rgba(0,0,0,0.08)';
    for (let i = 0; i < 24; i++) {
        const y = random() * size;
        ctx.lineWidth = 2 + random() * 2;
        ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(size, y); ctx.stroke();
    }
    for (let i = 0; i < 2200; i++) {
        const dark = random() > 0.5;
        ctx.fillStyle = dark ? 'rgba(0,0,0,0.06)' : 'rgba(255,255,255,0.07)';
        ctx.fillRect(random() * size, random() * size, 1 + random() * 2, 1);
    }
    return canvas;
}

/** Smooth leather with fine pores, without large painted blotches. */
function drawLeather(size) {
    const canvas = makeDrawingCanvas(size);
    const ctx = canvas.getContext('2d');
    const random = mulberry32(0x5EED);
    ctx.fillStyle = '#eeeeee';
    ctx.fillRect(0, 0, size, size);
    for (let i = 0; i < 10000; i++) {
        ctx.fillStyle = random() > 0.5 ? 'rgba(0,0,0,0.035)' : 'rgba(255,255,255,0.06)';
        ctx.fillRect(random() * size, random() * size, 1.2, 1.2);
    }
    return canvas;
}

/**
 * Lining weaves, tinted by colors.lining: cotton is a fine plain weave,
 * canvas a chunkier one. Drawn in light greys so pale lining colors stay pale.
 */
function drawLining(size, coarse) {
    const canvas = makeDrawingCanvas(size);
    const ctx = canvas.getContext('2d');
    const random = mulberry32(coarse ? 0xCAFE12 : 0xC077EE);
    const pitch = coarse ? 7 : 4;

    ctx.fillStyle = '#d9d9d9';
    ctx.fillRect(0, 0, size, size);

    ctx.strokeStyle = 'rgba(0,0,0,0.08)';
    ctx.lineWidth = 1;
    for (let y = 0; y < size; y += pitch) {
        ctx.beginPath(); ctx.moveTo(0, y + 0.5); ctx.lineTo(size, y + 0.5); ctx.stroke();
    }
    ctx.strokeStyle = 'rgba(255,255,255,0.14)';
    for (let x = 0; x < size; x += pitch) {
        ctx.beginPath(); ctx.moveTo(x + 0.5, 0); ctx.lineTo(x + 0.5, size); ctx.stroke();
    }
    for (let i = 0; i < 900; i++) {
        ctx.fillStyle = random() > 0.5 ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.06)';
        ctx.fillRect(random() * size, random() * size, 1 + random() * 2, 1);
    }
    return canvas;
}

const TEXTURE_SIZE = 1024;

const TEXTURE_MAKERS = {
    rattan: () => makeSurfaceMaps(drawRattan(TEXTURE_SIZE), 3, 3, 1.3),
    canvas: () => makeSurfaceMaps(drawCanvasTextile(TEXTURE_SIZE), 4, 4, 0.7),
    leather: () => makeSurfaceMaps(drawLeather(TEXTURE_SIZE), 2, 2, 0.9),
};

const LINING_TEXTURE_MAKERS = {
    cotton: () => makeSurfaceMaps(drawLining(TEXTURE_SIZE, false), 4, 4, 0.5),
    canvas: () => makeSurfaceMaps(drawLining(TEXTURE_SIZE, true), 3, 3, 0.6),
};

/* ------------------------------------------------------ pattern textures */

const PATTERN_KINDS = new Set(['checker', 'stripes', 'pixel']);

/**
 * Validate a config `pattern` descriptor; returns a normalized descriptor or
 * null when anything is off (callers then fall back to the material texture).
 */
function normalizePattern(raw) {
    if (!raw || typeof raw !== 'object' || !PATTERN_KINDS.has(raw.kind)) return null;
    if (raw.kind === 'checker') {
        const cell = Number(raw.cell);
        const colors = raw.colors;
        if (!(cell >= 0.3 && cell <= 10) || !Array.isArray(colors) || colors.length < 2
            || !colors.slice(0, 2).every(isHexColor)) return null;
        const bands = Array.isArray(raw.bands)
            ? raw.bands.filter((b) => b && Number.isFinite(Number(b.at)) && Number.isFinite(Number(b.height))
                && Array.isArray(b.colors) && b.colors.length > 0 && b.colors.every(isHexColor))
                .map((b) => ({ at: Number(b.at), height: Number(b.height), colors: b.colors }))
            : [];
        return { kind: 'checker', cell, period: cell * 2, colors: colors.slice(0, 2), bands };
    }
    if (raw.kind === 'stripes') {
        const period = Number(raw.period);
        const colors = raw.colors;
        if (!(period >= 0.5 && period <= 40) || !Array.isArray(colors) || !colors.length
            || !colors.every(isHexColor)) return null;
        let widths = Array.isArray(raw.widths) && raw.widths.length === colors.length
            ? raw.widths.map(Number) : null;
        if (!widths || !widths.every((w) => w > 0)) widths = colors.map(() => 1);
        const sum = widths.reduce((a, b) => a + b, 0);
        const lines = Number(raw.lines);
        return {
            kind: 'stripes', period, colors,
            widths: widths.map((w) => w / sum),
            lines: Number.isFinite(lines) && lines > 0.1 ? lines : 0,
            bands: [],
        };
    }
    // pixel cross-stitch diamond
    const tile = Number(raw.tile);
    const grid = Math.round(Number(raw.grid));
    if (!(tile >= 1 && tile <= 40) || !(grid >= 5 && grid <= 25)
        || !isHexColor(raw.base) || !isHexColor(raw.motif)) return null;
    return {
        kind: 'pixel', period: tile, grid,
        base: raw.base, motif: raw.motif,
        accent: isHexColor(raw.accent) ? raw.accent : null,
        bands: [],
    };
}

const GAP_COLOR = '#ad8c5e'; // narrow, warm fibre gaps

/** Paint one woven strand pad: gradient across the strand + fibre streaks. */
function paintStrand(cctx, rctx, x, y, w, h, horizontal, color, random) {
    const jitter = 0.98 + random() * 0.04;
    const hi = tint(color, jitter * 1.08);
    const lo = tint(color, jitter * 0.88);
    const grad = horizontal
        ? cctx.createLinearGradient(x, y, x, y + h)
        : cctx.createLinearGradient(x, y, x + w, y);
    grad.addColorStop(0, lo);
    grad.addColorStop(0.45, hi);
    grad.addColorStop(1, lo);
    cctx.fillStyle = grad;
    traceRoundedRect(cctx, x, y, w, h, Math.min(w, h) * 0.09);
    cctx.fill();
    cctx.strokeStyle = 'rgba(20,14,10,0.14)';
    cctx.lineWidth = Math.max(1, Math.min(w, h) * 0.018);
    cctx.stroke();
    const streaks = 3;
    for (let s = 0; s < streaks; s++) {
        const t = (s + 1) / (streaks + 1);
        cctx.strokeStyle = s % 2 ? 'rgba(255,255,255,0.16)' : 'rgba(0,0,0,0.14)';
        cctx.lineWidth = Math.max(1, Math.min(w, h) * 0.03);
        cctx.beginPath();
        if (horizontal) {
            cctx.moveTo(x + w * 0.06, y + h * t);
            cctx.lineTo(x + w * 0.94, y + h * t);
        } else {
            cctx.moveTo(x + w * t, y + h * 0.06);
            cctx.lineTo(x + w * t, y + h * 0.94);
        }
        cctx.stroke();
    }
    const grey = Math.round(120 + random() * 60);
    rctx.fillStyle = `rgb(${grey},${grey},${grey})`;
    traceRoundedRect(rctx, x, y, w, h, Math.min(w, h) * 0.3);
    rctx.fill();
}

/**
 * Paint one square pattern tile (`size` px = pattern.period cm per side) as
 * real woven strands in full color, plus a matching roughness canvas.
 * Returns { color, rough } canvases.
 */
function paintPatternTile(pattern, size) {
    const color = makeDrawingCanvas(size);
    const rough = makeDrawingCanvas(size);
    const cctx = color.getContext('2d');
    const rctx = rough.getContext('2d');
    const random = mulberry32(0x51EED5);

    cctx.fillStyle = GAP_COLOR;
    cctx.fillRect(0, 0, size, size);
    rctx.fillStyle = '#a8a8a8';
    rctx.fillRect(0, 0, size, size);

    if (pattern.kind === 'checker') {
        const cells = Math.max(2, Math.round(pattern.period / pattern.cell));
        const cell = size / cells;
        const pad = cell * 0.96;
        const gap = (cell - pad) / 2;
        for (let row = 0; row < cells; row++) {
            for (let col = 0; col < cells; col++) {
                const horizontal = (row + col) % 2 === 0;
                const dye = pattern.colors[(row + col) % 2];
                const x = col * cell + (horizontal ? 0 : gap);
                const y = row * cell + (horizontal ? gap : 0);
                paintStrand(cctx, rctx, x, y, horizontal ? cell : pad, horizontal ? pad : cell, horizontal, dye, random);
            }
        }
        return { color, rough };
    }

    if (pattern.kind === 'stripes') {
        // Dye belongs to the vertical warp. Fine, low-contrast weft lines
        // must never turn those solid stripes into a second plaid pattern.
        let x = 0;
        pattern.colors.forEach((dye, i) => {
            const end = i === pattern.colors.length - 1 ? size : x + pattern.widths[i] * size;
            cctx.fillStyle = dye;
            cctx.fillRect(x, 0, end - x + 1, size);
            x = end;
        });
        const threads = Math.max(16, Math.round(pattern.period / 0.12));
        const step = size / threads;
        for (let i = 0; i < threads; i++) {
            cctx.fillStyle = 'rgba(0,0,0,0.10)';
            cctx.fillRect(i * step, 0, Math.max(1, step * 0.12), size);
            cctx.fillStyle = 'rgba(255,255,255,0.08)';
            cctx.fillRect(i * step + step * 0.3, 0, Math.max(1, step * 0.1), size);
            rctx.fillStyle = '#d8d8d8';
            rctx.fillRect(i * step + 1, 0, step * 0.75, size);
        }
        const weft = pattern.lines ? Math.max(2, Math.round(pattern.period / pattern.lines)) : threads;
        for (let i = 0; i < weft; i++) {
            cctx.fillStyle = 'rgba(24,16,10,0.19)';
            cctx.fillRect(0, i * size / weft, size, Math.max(1, size / weft * 0.14));
            rctx.fillStyle = '#999999';
            rctx.fillRect(0, i * size / weft, size, Math.max(1, size / weft * 0.14));
        }
        return { color, rough };
    }

    const n = pattern.grid;
    const px = size / n;
    const c = (n - 1) / 2;
    cctx.fillStyle = pattern.base;
    cctx.fillRect(0, 0, size, size);
    const fine = Math.max(2, size / 64);
    cctx.fillStyle = 'rgba(24,16,10,0.12)';
    for (let y = 0; y < size; y += fine) cctx.fillRect(0, y, size, 1);
    for (let x = 0; x < size; x += fine) cctx.fillRect(x, 0, 1, size);
    cctx.fillStyle = 'rgba(255,246,230,0.06)';
    for (let y = 1; y < size; y += fine) cctx.fillRect(0, y, size, 1);
    const baseGrey = 150;
    rctx.fillStyle = `rgb(${baseGrey},${baseGrey},${baseGrey})`;
    rctx.fillRect(0, 0, size, size);
    for (let row = 0; row < n; row++) {
        for (let col = 0; col < n; col++) {
            const s = Math.abs(col - c) + Math.abs(row - c);
            if (s > c) continue;
            const dye = s <= c * 0.4 && pattern.accent ? pattern.accent : pattern.motif;
            paintStrand(
                cctx, rctx,
                col * px + px * 0.07, row * px + px * 0.07, px * 0.86, px * 0.86,
                (row + col) % 2 === 0, dye, random,
            );
        }
    }
    return { color, rough };
}

/**
 * Full-body pattern surface. Loft UVs use continuous centimetres around a
 * whole-repeat circumference and y / height vertically. Relief comes from
 * the weave, not the brightness of the dyed stripe colors.
 */
function makePatternSurface(pattern, heightCm) {
    const { color, rough } = paintPatternTile(pattern, TEXTURE_SIZE);
    const map = new THREE.CanvasTexture(color);
    map.colorSpace = THREE.SRGBColorSpace;
    map.wrapS = THREE.RepeatWrapping;
    map.wrapT = THREE.RepeatWrapping;
    map.repeat.set(1 / pattern.period, heightCm / pattern.period);
    const normal = makeNormalTexture(pattern.kind === 'stripes' ? rough : color, 1.6);
    normal.repeat.copy(map.repeat);
    const roughTex = new THREE.CanvasTexture(rough);
    roughTex.colorSpace = THREE.NoColorSpace;
    roughTex.wrapS = THREE.RepeatWrapping;
    roughTex.wrapT = THREE.RepeatWrapping;
    roughTex.repeat.copy(map.repeat);
    return { map, normal, rough: roughTex };
}

/**
 * Mosaic band material for one horizontal trim band: woven dyed squares in
 * the band palette, one canvas per band height so cells stay square in cm.
 * UVs: u = fractional perimeter (period = pattern.period), v = 0..1 across
 * the band.
 */
function makeBandMaterial(palette, cellCm, bandCm) {
    const periodCm = cellCm * palette.length;
    const w = 512;
    const h = Math.max(48, Math.min(1024, Math.round((512 * bandCm) / periodCm)));
    const color = makeCanvas(w, h);
    const rough = makeCanvas(w, h);
    const cctx = color.getContext('2d');
    const rctx = rough.getContext('2d');
    const random = mulberry32(0xBAAD50);
    cctx.fillStyle = GAP_COLOR;
    cctx.fillRect(0, 0, w, h);
    rctx.fillStyle = '#9a9a9a';
    rctx.fillRect(0, 0, w, h);
    const rows = Math.max(1, Math.round(bandCm / cellCm));
    const cols = palette.length;
    const rowH = h / rows;
    const colW = w / cols;
    for (let row = 0; row < rows; row++) {
        for (let col = 0; col < cols; col++) {
            const dye = palette[(col + row) % palette.length];
            paintStrand(
                cctx, rctx,
                col * colW + colW * 0.05, row * rowH + rowH * 0.07,
                colW * 0.9, rowH * 0.86,
                (row + col) % 2 === 0, dye, random,
            );
        }
    }
    cctx.fillStyle = 'rgba(53,40,32,0.5)';
    cctx.fillRect(0, 0, w, 2);
    cctx.fillRect(0, h - 2, w, 2);

    const map = new THREE.CanvasTexture(color);
    map.colorSpace = THREE.SRGBColorSpace;
    map.wrapS = THREE.RepeatWrapping;
    map.wrapT = THREE.ClampToEdgeWrapping;
    map.repeat.set(1 / periodCm, 1);
    const normal = makeNormalTexture(color, 1.2);
    normal.repeat.set(1 / periodCm, 1);
    normal.wrapS = THREE.RepeatWrapping;
    normal.wrapT = THREE.ClampToEdgeWrapping;
    const roughTex = new THREE.CanvasTexture(rough);
    roughTex.colorSpace = THREE.NoColorSpace;
    roughTex.wrapS = THREE.RepeatWrapping;
    roughTex.wrapT = THREE.ClampToEdgeWrapping;
    roughTex.repeat.set(1 / periodCm, 1);
    const material = new THREE.MeshStandardMaterial({
        color: 0xffffff, map, normalMap: normal, roughnessMap: roughTex,
        normalScale: new THREE.Vector2(0.45, 0.45),
        roughness: 0.9, metalness: 0.02, side: THREE.DoubleSide, envMapIntensity: 0.5,
    });
    material.userData.patternPeriod = periodCm;
    return material;
}

/* --------------------------------------------------- geometry helpers */

/**
 * Points around a rounded-rect perimeter (shape space: x = width, y = depth),
 * counter-clockwise: `segmentsPerCorner` points per corner arc plus
 * `segmentsPerEdge` points evenly spaced along each straight edge, so every
 * ring has the same vertex count and straight-wall columns sit at a fixed
 * fraction of the edge (pattern stripes stay straight where rings change).
 */
const EDGE_SEGMENTS = 12;

function roundedRectPerimeter(width, depth, radius, segmentsPerCorner, segmentsPerEdge = EDGE_SEGMENTS) {
    const hw = width / 2;
    const hd = depth / 2;
    const r = Math.max(0.01, Math.min(radius, hw, hd));
    const corners = [
        [hw - r, hd - r, 0],
        [-hw + r, hd - r, Math.PI / 2],
        [-hw + r, -hd + r, Math.PI],
        [hw - r, -hd + r, Math.PI * 1.5],
    ];
    const points = [];
    for (let c = 0; c < 4; c++) {
        const [cx, cy, a0] = corners[c];
        for (let i = 0; i < segmentsPerCorner; i++) {
            const a = a0 + (i / segmentsPerCorner) * (Math.PI / 2);
            points.push(new THREE.Vector2(cx + Math.cos(a) * r, cy + Math.sin(a) * r));
        }
        // straight edge from this corner's end to the next corner's start
        const [nx, ny, na] = corners[(c + 1) % 4];
        const sx = cx + Math.cos(a0 + Math.PI / 2) * r;
        const sy = cy + Math.sin(a0 + Math.PI / 2) * r;
        const ex = nx + Math.cos(na) * r;
        const ey = ny + Math.sin(na) * r;
        for (let i = 1; i <= segmentsPerEdge; i++) {
            const t = i / (segmentsPerEdge + 1);
            points.push(new THREE.Vector2(sx + (ex - sx) * t, sy + (ey - sy) * t));
        }
    }
    return points;
}

/** Put the one duplicated UV seam at the inconspicuous back centre. */
function rotateToBackCenter(points, depth) {
    const hd = depth / 2;
    let best = 0;
    let bestD = Infinity;
    for (let i = 0; i < points.length; i++) {
        const d = points[i].x * points[i].x + (points[i].y + hd) * (points[i].y + hd);
        if (d < bestD) { bestD = d; best = i; }
    }
    return points.slice(best).concat(points.slice(0, best));
}


/**
 * Rounded shell with a duplicated back-centre UV seam. Every wall quad has
 * continuous UVs; no triangle interpolates across a wrapped texture boundary.
 * Pattern circumference uses whole repeats, with columns aligned on all rings.
 */

function perimeterLength(pts) {
    let len = 0;
    for (let i = 0; i < pts.length; i++) {
        const a = pts[i];
        const b = pts[(i + 1) % pts.length];
        len += Math.hypot(b.x - a.x, b.y - a.y);
    }
    return len;
}
function buildLoft(rings, { wallThickness = 0, capBottom = false, capTop = false, patternUV = null, rimSag = 0 } = {}) {
    // Segment counts from the widest ring so point spacing (and therefore the
    // UV-area/world-area ratio) is near-uniform around every ring.
    const widest = rings.reduce((a, b) => (b.w + b.d >= a.w + a.d ? b : a));
    const rMax = Math.min(widest.r, widest.w / 2, widest.d / 2);
    const segC = THREE.MathUtils.clamp(Math.round((rMax * Math.PI / 2) / 0.7), 6, 16);
    const segE = THREE.MathUtils.clamp(Math.round(Math.max(widest.w, widest.d) / 2 / 1.1), 8, 28);
    const n = (segC + segE) * 4;
    const vScale = patternUV ? patternUV.vScale : 1;
    const vOffset = patternUV ? (patternUV.vOffset ?? 0) : 0;
    const prep = (ring) => {
        const pts = rotateToBackCenter(
            roundedRectPerimeter(ring.w, ring.d, ring.r, segC, segE), ring.d,
        );
        return { y: ring.y, pts, len: perimeterLength(pts) };
    };
    const outer = rings.map(prep);
    const reference = prep(widest);
    const repeatLength = patternUV
        ? Math.max(1, Math.round(reference.len / patternUV.period)) * patternUV.period
        : reference.len;
    const referenceU = [0];
    for (let i = 1; i <= n; i++) {
        const a = reference.pts[i - 1];
        const b = reference.pts[i % n];
        referenceU.push(referenceU[i - 1] + a.distanceTo(b) * repeatLength / reference.len);
    }
    const inner = wallThickness > 0
        ? rings.map((ring) => prep({
            y: ring.y,
            w: Math.max(0.6, ring.w - wallThickness * 2),
            d: Math.max(0.6, ring.d - wallThickness * 2),
            r: Math.max(0.15, ring.r - wallThickness),
        }))
        : null;

    const positions = [];
    const uvs = [];
    const indices = [];

    const addRing = (ring) => {
        const base = positions.length / 3;
        let dist = 0;
        for (let i = 0; i <= n; i++) {
            const p = ring.pts[i % n];
            if (i > 0) dist += p.distanceTo(ring.pts[i - 1]);
            positions.push(p.x, ring.y, p.y);
            const u = patternUV ? referenceU[i] - repeatLength / 2 : dist;
            uvs.push(u, ring.y * vScale + vOffset);
        }
        return base;
    };

    const addSurface = (ringList) => {
        const bases = ringList.map(addRing);
        for (let j = 0; j < ringList.length - 1; j++) {
            const a = bases[j];
            const b = bases[j + 1];
            for (let i = 0; i < n; i++) {
                const k = i + 1;
                indices.push(a + i, b + i, b + k, a + i, b + k, a + k);
            }
        }
        return bases;
    };

    const outerBases = addSurface(outer);
    const innerBases = inner ? addSurface(inner) : null;

    const addCap = (ringIndex, top) => {
        const bOut = outerBases[ringIndex];
        const v = outer[ringIndex].y * vScale + vOffset;
        if (inner) {
            const bIn = innerBases[ringIndex];
            for (let i = 0; i < n; i++) {
                const k = i + 1;
                if (top) indices.push(bOut + i, bIn + i, bIn + k, bOut + i, bIn + k, bOut + k);
                else indices.push(bOut + i, bIn + k, bIn + i, bOut + i, bOut + k, bIn + k);
            }
        } else {
            const ring = outer[ringIndex];
            const c = positions.length / 3;
            positions.push(0, ring.y, 0);
            uvs.push(0, v);
            for (let i = 0; i < n; i++) {
                const k = i + 1;
                if (top) indices.push(c, bOut + i, bOut + k);
                else indices.push(c, bOut + k, bOut + i);
            }
        }
    };
    if (capBottom) addCap(0, false);
    if (capTop) addCap(rings.length - 1, true);
    if (rimSag > 0) {
        // A soft curved mouth, highest beside the side anchors.
        const last = rings[rings.length - 1];
        const hd = last.d / 2;
        for (const bases of [outerBases, innerBases ?? []]) {
            if (bases.length === 0) continue;
            const base = bases[bases.length - 1];
            for (let i = 0; i <= n; i++) {
                const idx = base + i;
                const x = positions[idx * 3] / (last.w / 2);
                const z = Math.min(1, Math.abs(positions[idx * 3 + 2] / hd));
                positions[idx * 3 + 1] -= rimSag * Math.max(0, 1 - x * x) * z;
            }
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();
    // The duplicated seam is a UV split, not a physical crease.
    const normals = geometry.getAttribute('normal');
    const seam = new THREE.Vector3();
    for (const bases of [outerBases, innerBases ?? []]) {
        for (const base of bases) {
            seam.set(
                normals.getX(base) + normals.getX(base + n),
                normals.getY(base) + normals.getY(base + n),
                normals.getZ(base) + normals.getZ(base + n),
            ).normalize();
            normals.setXYZ(base, seam.x, seam.y, seam.z);
            normals.setXYZ(base + n, seam.x, seam.y, seam.z);
        }
    }
    if (patternUV) geometry.userData.patternUV = true;
    else geometry.userData.uvInCentimeters = true;
    return geometry;
}

/** Interpolated ring dimensions at height y (for placing panels/bands on flares). */
function ringAt(rings, y) {
    if (y <= rings[0].y) return rings[0];
    for (let i = 0; i < rings.length - 1; i++) {
        const a = rings[i];
        const b = rings[i + 1];
        if (y <= b.y) {
            const t = (y - a.y) / Math.max(1e-6, b.y - a.y);
            return { y, w: a.w + (b.w - a.w) * t, d: a.d + (b.d - a.d) * t, r: a.r + (b.r - a.r) * t };
        }
    }
    return rings[rings.length - 1];
}

/**
 * Flat webbing ribbon swept along a curve: a triangle strip `width` wide,
 * using the curve's binormals as the width direction (face-visible from the
 * front for arches in the XY plane). UVs in centimetres.
 */
function ribbonGeometry(curve, width, segments = 64) {
    const frames = curve.computeFrenetFrames(segments, false);
    const positions = [];
    const uvs = [];
    const indices = [];
    const p = new THREE.Vector3();
    let prev = null;
    let dist = 0;
    for (let i = 0; i <= segments; i++) {
        curve.getPointAt(i / segments, p);
        if (prev) dist += p.distanceTo(prev);
        prev = p.clone();
        const b = frames.binormals[i];
        positions.push(p.x - b.x * width / 2, p.y - b.y * width / 2, p.z - b.z * width / 2);
        positions.push(p.x + b.x * width / 2, p.y + b.y * width / 2, p.z + b.z * width / 2);
        uvs.push(-width / 2, dist, width / 2, dist);
    }
    for (let i = 0; i < segments; i++) {
        const a = i * 2;
        const b = a + 1;
        const c = a + 2;
        const d = a + 3;
        indices.push(a, b, c, b, d, c);
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();
    geometry.userData.uvInCentimeters = true;
    return geometry;
}

/** Closed rounded-rect perimeter as 3D points in the XZ plane at height y. */
function rectPathXZ(width, depth, radius, y, spacing = 0.85) {
    const seg = Math.max(6, Math.min(28, Math.round((2 * (width + depth)) / (4 * spacing))));
    return roundedRectPerimeter(width, depth, radius, seg)
        .map((p) => new THREE.Vector3(p.x, y, p.y));
}

/** Sample a point loop into oriented stitch dashes (optionally closed). */
function stitchPath(points, spacing, closeLoop = true) {
    const dashes = [];
    const last = closeLoop ? points.length : points.length - 1;
    for (let i = 0; i < last; i++) {
        const a = points[i];
        const b = points[(i + 1) % points.length];
        const len = a.distanceTo(b);
        if (len < 0.05) continue;
        const steps = Math.max(1, Math.round(len / spacing));
        const dir = b.clone().sub(a).normalize();
        for (let s = 0; s < steps; s++) {
            dashes.push({ pos: a.clone().lerp(b, s / steps), dir });
        }
    }
    return dashes;
}

/**
 * Dashed stitch lines as one InstancedMesh (shared dash geometry) added to
 * `parent` — pass a flap mesh/group as parent so stitches travel with flaps.
 */
function addStitches(parent, paths, material, spacing = 0.85, closeLoop = true) {
    const dashes = paths.flatMap((path) => stitchPath(path, spacing, closeLoop));
    if (!dashes.length) return null;
    const mesh = new THREE.InstancedMesh(new THREE.BoxGeometry(0.55, 0.16, 0.16), material, dashes.length);
    const matrix = new THREE.Matrix4();
    const quat = new THREE.Quaternion();
    const xAxis = new THREE.Vector3(1, 0, 0);
    const one = new THREE.Vector3(1, 1, 1);
    dashes.forEach((dash, i) => {
        quat.setFromUnitVectors(xAxis, dash.dir);
        matrix.compose(dash.pos, quat, one);
        mesh.setMatrixAt(i, matrix);
    });
    mesh.instanceMatrix.needsUpdate = true;
    mesh.frustumCulled = false;
    mesh.userData.noShadow = true;
    parent.add(mesh);
    return mesh;
}

/**
 * Leather tab wrapping over the rim at a handle end: outer strip flush on the
 * wall face, a strip over the top edge, and a short inner lip — plus rivet
 * heads on the outer face. `end` is on the rim band's outer surface at the
 * rim top; `normal` is the outward wall normal (XZ); `wrap` = rim grow +
 * wall thickness the tab folds over. Local +z is the outward normal.
 */
function addRimTab(group, end, normal, rimTop, wrap, { tabMaterial, rivet, tabW = 2.2, tabH = 2.0 }) {
    const tabT = 0.28; // slim leather tab
    const lipH = 1.0;
    const tab = new THREE.Group();
    // Children are LOCAL to the tab group (rim top = local y 0); the group
    // itself is positioned at `end` (on the rim's outer surface at rimTop).
    const outer = new THREE.Mesh(
        extrudeCentered(roundedRectShape(tabW, tabH, 0.3), tabT, 0.05),
        tabMaterial,
    );
    outer.position.set(0, -tabH / 2, tabT / 2);
    const top = new THREE.Mesh(new THREE.BoxGeometry(tabW, tabT, wrap + tabT), tabMaterial);
    top.position.set(0, tabT / 2, (tabT - wrap) / 2);
    const lip = new THREE.Mesh(new THREE.BoxGeometry(tabW, lipH, tabT), tabMaterial);
    lip.position.set(0, -lipH / 2, -wrap - tabT / 2);
    for (const m of [outer, top, lip]) {
        m.userData.isTab = true;
        tab.add(m);
    }
    const rivetGeo = new THREE.CylinderGeometry(0.24, 0.24, 0.3, 10);
    for (const dx of [-tabW * 0.26, tabW * 0.26]) {
        const r = new THREE.Mesh(rivetGeo, rivet);
        r.rotation.x = Math.PI / 2;
        r.position.set(dx, -tabH * 0.42, tabT + 0.02);
        r.userData.isHandle = true;
        tab.add(r);
    }
    tab.position.copy(end);
    tab.quaternion.setFromUnitVectors(new THREE.Vector3(0, 0, 1), normal);
    group.add(tab);
    return tab;
}

/**
 * Arched handle between two rim anchors. The tube is a slightly oval rolled
 * leather tube (custom cross-section, centreline undistorted) rising from the
 * tabs' outer faces (with optional outward lean), edge-stitched on its
 * inner/underside. Both ends get a rim-wrapping tab with rivets.
 * All handle meshes are tagged userData.isHandle / isTab.
 */
function addArchHandle(group, { endA, endB, normalA, normalB, rise, radius, leanDir = null, lean = 0, rimTop, wrap, tubeMaterial, tabMaterial, rivet, stitch, stitchSide = -1 }) {
    // Centreline begins inside each wrapping tab, not above/outside it.
    const pA = endA.clone().addScaledVector(normalA, 0.16).add(new THREE.Vector3(0, -0.35, 0));
    const pB = endB.clone().addScaledVector(normalB, 0.16).add(new THREE.Vector3(0, -0.35, 0));
    const pivot = new THREE.Group();
    pivot.position.copy(pA).lerp(pB, 0.5);
    group.add(pivot);
    const points = [];
    for (let i = 0; i <= 16; i++) {
        const t = i / 16;
        const angle = Math.PI * t;
        const p = pA.clone().lerp(pB, (1 - Math.cos(angle)) / 2);
        p.y += Math.sin(angle) * rise;
        if (leanDir) p.addScaledVector(leanDir, Math.sin(angle) * lean);
        points.push(p.sub(pivot.position));
    }
    const curve = new THREE.CatmullRomCurve3(points);
    const tube = new THREE.Mesh(ovalTubeGeometry(curve, 48, radius, 10), tubeMaterial);
    tube.userData.isHandle = true;
    pivot.add(tube);
    (group.userData.handles ??= []).push({
        pivot, openAngle: normalA.z * Math.PI * 0.61,
    });

    if (stitch) {
        // edge seam on the inner/underside of the arch
        const off = radius * 1.22 + 0.08;
        const pts = curve.getPoints(48).map((p) => p.clone().add(new THREE.Vector3(0, 0, stitchSide * off)));
        addStitches(pivot, [pts], stitch, 0.8, false);
    }

    addRimTab(group, endA, normalA, rimTop, wrap, { tabMaterial, rivet });
    addRimTab(group, endB, normalB, rimTop, wrap, { tabMaterial, rivet });
    return tube;
}

/**
 * Tube along a curve with an oval cross-section (sy in y, sz in z) — used for
 * rolled handles; the centreline itself is not distorted.
 */
function ovalTubeGeometry(curve, segments, radius, radialSegments, sy = 0.88, sz = 1.22) {
    const frames = curve.computeFrenetFrames(segments, false);
    const positions = [];
    const uvs = [];
    const indices = [];
    const p = new THREE.Vector3();
    const off = new THREE.Vector3();
    for (let i = 0; i <= segments; i++) {
        curve.getPointAt(i / segments, p);
        const N = frames.normals[i];
        const B = frames.binormals[i];
        for (let j = 0; j <= radialSegments; j++) {
            const a = (j / radialSegments) * Math.PI * 2;
            const sin = Math.sin(a);
            const cos = Math.cos(a);
            off.set(0, 0, 0)
                .addScaledVector(N, cos * radius)
                .addScaledVector(B, sin * radius);
            positions.push(p.x + off.x, p.y + off.y * sy, p.z + off.z * sz);
            uvs.push(i / segments, j / radialSegments);
        }
    }
    for (let i = 0; i < segments; i++) {
        for (let j = 0; j < radialSegments; j++) {
            const a = i * (radialSegments + 1) + j;
            const b = a + radialSegments + 1;
            indices.push(a, b, a + 1, b, b + 1, a + 1);
        }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();
    return geometry;
}

/** Rolled binding (piping) tube around a rounded-rect rim, lying in the XZ plane. */
function roundedRectTubeGeometry(width, depth, radius, tubeRadius) {
    const pts = roundedRectPerimeter(width, depth, radius, 10)
        .map((p) => new THREE.Vector3(p.x, 0, p.y));
    return new THREE.TubeGeometry(new THREE.CatmullRomCurve3(pts, true), 96, tubeRadius, 8, true);
}

/** Rolled binding along the rim of an open-top bag. */
function addRimPiping(group, width, depth, radius, y, material) {
    const mesh = new THREE.Mesh(roundedRectTubeGeometry(width, depth, radius, 0.26), material);
    mesh.position.y = y;
    mesh.userData.isBinding = true;
    group.add(mesh);
    return mesh;
}


/** Vertical corner seam stitch lines on an open-top lofted shell. */
function addCornerSeams(group, rings, wall, h, material) {
    const paths = [];
    for (const sx of [-1, 1]) {
        for (const sz of [-1, 1]) {
            const pts = [];
            for (let y = wall + 0.5; y <= h - 1.4; y += 0.9) {
                const r = ringAt(rings, Math.min(y, h));
                // corner-arc midpoint (45°), nudged 0.06 cm proud of the wall
                const off = r.r * (1 - Math.SQRT1_2) + 0.06;
                pts.push(new THREE.Vector3(
                    sx * (r.w / 2 - off),
                    y,
                    sz * (r.d / 2 - off),
                ));
            }
            paths.push(pts);
        }
    }
    addStitches(group, paths, material, 0.8, false);
}

/* --------------------------------------------------------- bag builders */

/* Interior pocket conventions:
 * - Wall pockets tuck their back face POCKET_EMBED behind the lining surface
 *   (never coplanar) and protrude the rest into the cavity.
 * - Floor-standing pieces (divider, bottle slip) sink POCKET_SINK below the
 *   pocket base level so their bottom caps hide inside the lining floor.
 * - Pocket tops stay below the rim so inspection sees their openings.
 * - Pocket bounds are derived from the narrowest (base) ring, so panels stay
 *   inside the lining even where the shell flares outward.
 */
const POCKET_EMBED = 0.03;
const POCKET_SINK = 0.05;

/** Zippered pocket stitched to the back wall: fabric panel, teeth, pull. */
function addInteriorZip(interior, ctx, materials) {
    const cavityH = ctx.topY - ctx.floorY;
    const panelW = Math.min(ctx.w * 0.55, (ctx.rightX - ctx.leftX) * 0.72);
    const panelH = Math.min(ctx.h * 0.24, cavityH * 0.34);
    const depth = 0.3;
    const y = ctx.floorY + cavityH * 0.42;
    const z = ctx.backZ - POCKET_EMBED + depth / 2;

    const panel = new THREE.Mesh(new THREE.BoxGeometry(panelW, panelH, depth), materials.pocketFabric);
    panel.position.set(0, y, z);
    interior.add(panel);

    const teeth = new THREE.Mesh(new THREE.BoxGeometry(panelW * 0.94, 0.16, 0.14), materials.pocketHardware);
    teeth.position.set(0, y + panelH / 2 - 0.35, z + depth / 2 + 0.02);
    interior.add(teeth);

    const pull = new THREE.Mesh(new THREE.BoxGeometry(0.4, 0.9, 0.14), materials.pocketHardware);
    pull.position.set(panelW * 0.28, y + panelH / 2 - 0.85, z + depth / 2 + 0.05);
    interior.add(pull);
}

/** Plain slip pocket on the front wall with a hemmed top edge. */
function addSlipPocket(interior, ctx, materials) {
    const cavityH = ctx.topY - ctx.floorY;
    const panelW = Math.min(ctx.w * 0.42, (ctx.rightX - ctx.leftX) * 0.55);
    const panelH = Math.min(ctx.h * 0.28, cavityH * 0.4);
    const depth = 0.3;
    const x = -(ctx.rightX - ctx.leftX) * 0.14;
    const y = ctx.floorY + cavityH * 0.34;
    const z = ctx.frontZ + POCKET_EMBED - depth / 2;

    const panel = new THREE.Mesh(new THREE.BoxGeometry(panelW, panelH, depth), materials.pocketFabric);
    panel.position.set(x, y, z);
    interior.add(panel);

    const hem = new THREE.Mesh(new THREE.BoxGeometry(panelW * 1.04, 0.4, depth + 0.08), materials.pocketFabric);
    hem.position.set(x, y + panelH / 2 + 0.05, z);
    interior.add(hem);
}

/** Slip pocket on the back wall (back_slip): kept clear of other details. */
function addBackSlip(interior, ctx, materials) {
    const cavityH = ctx.topY - ctx.floorY;
    const panelW = Math.min(ctx.w * 0.42, (ctx.rightX - ctx.leftX) * 0.55);
    const panelH = Math.min(ctx.h * 0.24, cavityH * 0.34);
    const depth = 0.3;
    const y = ctx.floorY + cavityH * 0.42;
    const z = ctx.backZ - POCKET_EMBED + depth / 2;

    const panel = new THREE.Mesh(new THREE.BoxGeometry(panelW, panelH, depth), materials.pocketFabric);
    panel.position.set(0, y, z);
    interior.add(panel);

    const hem = new THREE.Mesh(new THREE.BoxGeometry(panelW * 1.04, 0.4, depth + 0.08), materials.pocketFabric);
    hem.position.set(0, y + panelH / 2 + 0.05, z);
    interior.add(hem);
}

/** Cylindrical bottle slip standing against the right-back corner. */
function addBottleSlip(interior, ctx, materials) {
    const cavityH = ctx.topY - ctx.floorY;
    const radius = Math.min(2.4, (ctx.rightX - ctx.leftX) * 0.2, (ctx.frontZ - ctx.backZ) * 0.28);
    const height = Math.min(ctx.h * 0.42, cavityH * 0.58);
    const x = ctx.rightX - radius - 0.18;
    const z = ctx.backZ + radius + 0.18;

    const barrel = new THREE.Mesh(new THREE.CylinderGeometry(radius, radius, height, 20, 1, true), materials.pocketFabric);
    barrel.position.set(x, ctx.floorY + height / 2 - POCKET_SINK, z);
    interior.add(barrel);

    const binding = new THREE.Mesh(new THREE.CylinderGeometry(radius + 0.14, radius + 0.14, 0.5, 20, 1, true), materials.pocketFabric);
    binding.position.set(x, ctx.floorY + height - 0.3, z);
    interior.add(binding);
}

/** Row of narrow pen channels on the front wall. */
function addPenSlip(interior, ctx, materials) {
    const cavityH = ctx.topY - ctx.floorY;
    const channelH = Math.min(ctx.h * 0.18, cavityH * 0.26);
    const depth = 0.22;
    const y = ctx.floorY + cavityH * 0.36;
    const z = ctx.frontZ + POCKET_EMBED - depth / 2;
    const spacing = Math.min(1.7, (ctx.rightX - ctx.leftX) * 0.08);
    const x0 = -(ctx.rightX - ctx.leftX) * 0.16 - spacing;

    for (let i = 0; i < 3; i++) {
        const channel = new THREE.Mesh(new THREE.BoxGeometry(1.0, channelH, depth), materials.pocketFabric);
        channel.position.set(x0 + i * spacing, y, z);
        interior.add(channel);
    }
}

/** Vertical divider wall creating a slim side compartment, with a slip on it. */
function addDividerPocket(interior, ctx, materials) {
    const cavityH = ctx.topY - ctx.floorY;
    const dividerH = Math.min(ctx.h * 0.52, cavityH * 0.68);
    const spanZ = ctx.frontZ - ctx.backZ - 0.3;
    const x = ctx.rightX - (ctx.rightX - ctx.leftX) * 0.3;

    const wall = new THREE.Mesh(new THREE.BoxGeometry(0.35, dividerH, spanZ), materials.pocketFabric);
    wall.position.set(x, ctx.floorY + dividerH / 2 - POCKET_SINK, 0);
    interior.add(wall);

    const slipSpan = spanZ * 0.7;
    const slip = new THREE.Mesh(new THREE.BoxGeometry(0.24, dividerH * 0.5, slipSpan), materials.pocketFabric);
    slip.position.set(x - 0.175 - 0.12 + POCKET_EMBED, ctx.floorY + dividerH * 0.42, 0);
    interior.add(slip);
}

/** Flat slip pocket on the left wall. */
function addInteriorSlip(interior, ctx, materials) {
    const cavityH = ctx.topY - ctx.floorY;
    const panelH = Math.min(ctx.h * 0.26, cavityH * 0.38);
    const panelD = Math.min(ctx.d * 0.5, (ctx.frontZ - ctx.backZ) * 0.55);
    const depth = 0.28;
    const y = ctx.floorY + cavityH * 0.36;
    const x = ctx.leftX - POCKET_EMBED + depth / 2;

    const panel = new THREE.Mesh(new THREE.BoxGeometry(depth, panelH, panelD), materials.pocketFabric);
    panel.position.set(x, y, 0);
    interior.add(panel);

    const hem = new THREE.Mesh(new THREE.BoxGeometry(depth + 0.08, 0.4, panelD * 1.04), materials.pocketFabric);
    hem.position.set(x, y + panelH / 2 + 0.05, 0);
    interior.add(hem);
}

/** Short leash strap with a ring, mounted on the right wall. */
function addKeyLeash(interior, ctx, materials) {
    const cavityH = ctx.topY - ctx.floorY;
    const mountDepth = 0.24;
    const mountX = ctx.rightX + POCKET_EMBED - mountDepth / 2;
    const mountY = ctx.floorY + cavityH * 0.58;

    const mount = new THREE.Mesh(new THREE.BoxGeometry(mountDepth, 1.2, 0.8), materials.pocketFabric);
    mount.position.set(mountX, mountY, 0);
    interior.add(mount);

    const strapLength = Math.min(3, cavityH * 0.2);
    const strap = new THREE.Mesh(new THREE.BoxGeometry(0.14, strapLength, 0.5), materials.pocketFabric);
    strap.position.set(ctx.rightX - 0.28, mountY - 0.6 - strapLength / 2, 0);
    interior.add(strap);

    const ring = new THREE.Mesh(new THREE.TorusGeometry(0.45, 0.12, 8, 16), materials.pocketHardware);
    ring.position.set(ctx.rightX - 0.28, mountY - 0.6 - strapLength - 0.35, 0);
    interior.add(ring);
}

/*
 * Interior pocket keys. Exterior keys (exterior_zip, back wall outer pockets
 * other than back_slip) describe outside features: they get exterior detail
 * only, never fake interior pockets.
 */
const POCKET_BUILDERS = {
    interior_zip: addInteriorZip,
    slip_pocket: addSlipPocket,
    bottle_slip: addBottleSlip,
    pen_slip: addPenSlip,
    divider_pocket: addDividerPocket,
    interior_slip: addInteriorSlip,
    back_slip: addBackSlip,
    key_leash: addKeyLeash,
};

function addPockets(interior, pockets, ctx, materials) {
    for (const kind of pockets) {
        const build = POCKET_BUILDERS[kind];
        if (build) build(interior, ctx, materials);
    }
}

/** UV options for a patterned loft spanning [y0, y1] of a body of height h. */
function patternUVFor(materials, h, y0 = 0, span = null) {
    if (!materials.pattern) return undefined;
    const s = span ?? h;
    return { vScale: 1 / s, vOffset: -y0 / s, period: materials.pattern.period };
}

/**
 * Inset lining cup + floor for an open-top lofted shell, plus the configured
 * pockets. Bounds come from the narrowest (base) ring so nothing pokes
 * through the flared walls. Registers userData.interior on the group.
 */
function addOpenInterior(group, rings, wall, h, materials, pockets, dims) {
    const interior = new THREE.Group();
    group.add(interior);

    const inset = wall + 0.4;
    const floorY = wall + 0.2;
    const topY = h - 0.6;
    const ys = [floorY];
    for (const r of rings) {
        if (r.y > floorY + 0.3 && r.y < topY - 0.3) ys.push(r.y);
    }
    ys.push(topY);
    const liningRings = ys.map((y) => {
        const r = ringAt(rings, y);
        return {
            y,
            w: Math.max(1.2, r.w - inset * 2),
            d: Math.max(1.2, r.d - inset * 2),
            r: Math.max(0.3, r.r - inset),
        };
    });
    interior.add(new THREE.Mesh(
        buildLoft(liningRings, { capBottom: true, segmentsPerCorner: 12 }),
        materials.lining,
    ));

    const base = rings[0];
    const ctx = {
        w: dims.width, h, d: dims.depth,
        floorY: floorY + 0.25,
        topY,
        leftX: -(base.w / 2 - inset - 0.1),
        rightX: base.w / 2 - inset - 0.1,
        backZ: -(base.d / 2 - inset - 0.1),
        frontZ: base.d / 2 - inset - 0.1,
    };
    addPockets(interior, pockets, ctx, materials);

    group.userData.interior = interior;
    return ctx;
}

/**
 * Wrapped trim band around the shell between y0 and y1, grown slightly proud
 * of the body wall. Uses the mosaic band material (pattern space UVs) when
 * `key` resolves in materials.bandMats, otherwise the plain trim weave.
 */
function addBandLoft(group, rings, wall, y0, y1, materials, key, grow = 0.14, mat = null) {
    const r0 = ringAt(rings, Math.max(rings[0].y, y0));
    const r1 = ringAt(rings, Math.min(rings[rings.length - 1].y, y1));
    const bandRings = [
        { y: y0, w: r0.w + grow, d: r0.d + grow, r: r0.r + grow * 0.4 },
        { y: y1, w: r1.w + grow, d: r1.d + grow, r: r1.r + grow * 0.4 },
    ];
    const bandMat = key != null ? (materials.bandMats.get(key) ?? null) : null;
    const mesh = new THREE.Mesh(
        buildLoft(bandRings, {
            wallThickness: wall,
            segmentsPerCorner: 12,
            capBottom: y0 <= rings[0].y + 0.01,
            capTop: y1 >= rings[rings.length - 1].y - 0.01,
            patternUV: bandMat ? {
                vScale: 1 / (y1 - y0), vOffset: -y0 / (y1 - y0),
                period: bandMat.userData.patternPeriod,
            } : undefined,
        }),
        bandMat ?? mat ?? materials.trim,
    );
    group.add(mesh);
    return mesh;
}

/**
 * Slim rim band hugging the shell wall between y0 and the rim, with the top
 * edge arcing slightly down toward the front centre (like the sling SVG's
 * curved band). Closed tube (outer wall + top annulus + inner wall), grown
 * just proud of the body so it reads as a flush band, not a collar.
 * UVs in centimetres (normalized by buildBag like other weave surfaces).
 */
function addArchedRimBand(group, rings, wall, y0, h, materials, grow = 0.15, arc = 0.7, mat = null) {
    const bandRings = [y0, h + 0.05].map((y) => {
        const ring = ringAt(rings, Math.min(y, h));
        return { ...ring, y, w: ring.w + grow, d: ring.d + grow };
    });
    const geometry = buildLoft(bandRings, { wallThickness: wall, capTop: true, capBottom: true });
    const positions = geometry.getAttribute('position');
    for (let i = 0; i < positions.count; i++) {
        const y = positions.getY(i);
        const ring = ringAt(bandRings, y);
        const x = positions.getX(i) / (ring.w / 2);
        const z = positions.getZ(i) / (ring.d / 2);
        positions.setY(i, y - arc * Math.max(0, 1 - x * x) * Math.min(1, Math.abs(z)));
    }
    geometry.computeVertexNormals();
    group.add(new THREE.Mesh(geometry, mat ?? materials.trim));
}

/**
 * Tote (Sinag): open-top container tapering slightly wider at the rim,
 * tan checker weave with two horizontal multicolor mosaic bands (mid and
 * near the base) plus a thin mosaic rim, and two nested arch handles
 * (outer darker tan at the front, inner lighter tan at the back) with
 * dashed stitching, tabs and rivets.
 */
function buildTote(dims, materials, pockets) {
    const { width: w, height: h, depth: d } = dims;
    const group = new THREE.Group();
    const wall = 0.8;

    const rings = [
        { y: 0, w: w * 0.93, d: d * 0.92, r: 1.6 },
        { y: h * 0.25, w: w * 0.965, d: d * 0.96, r: 2.0 },
        { y: h * 0.5, w: w * 1.005, d: d * 1.005, r: 2.3 }, // soft mid-wall bulge
        { y: h * 0.75, w: w * 0.995, d: d * 0.995, r: 2.4 },
        { y: h, w, d, r: 2.4 },
    ];
    group.add(new THREE.Mesh(
        buildLoft(rings, {
            wallThickness: wall, capBottom: true, segmentsPerCorner: 12,
            patternUV: patternUVFor(materials, h), rimSag: 0.3,
        }),
        materials.body,
    ));

    addOpenInterior(group, rings, wall, h, materials, pockets, dims);
    group.userData.flap = null; // open top: no lid, nothing to hinge
    group.userData.interiorTarget = new THREE.Vector3(0, h * 0.4, 0);

    // Mosaic bands (mid + near base) and a thin mosaic rim.
    const bands = materials.pattern?.bands ?? [];
    bands.forEach((band, i) => {
        addBandLoft(group, rings, wall, band.at * h, (band.at + band.height) * h, materials, i);
    });
    const rimGrow = 0.45;
    addBandLoft(group, rings, wall, h - 0.9, h + 0.12, materials, 'rim', rimGrow);
    const rimTop = h + 0.12;

    // Rolled leather binding on the rim + stitched seams (rim ring + corners).
    addRimPiping(group, w + rimGrow + 0.1, d + rimGrow + 0.1, 2.7, rimTop - 0.05, materials.binding);
    const rimRing = ringAt(rings, h - 0.95);
    addStitches(group, [rectPathXZ(rimRing.w + 0.2, rimRing.d + 0.2, rimRing.r, h - 0.95)], materials.stitch);
    addCornerSeams(group, rings, wall, h, materials.stitch);

    if (materials.handleStyle === 'double_handles') {
        // One handle on the front wall, one on the back wall; ends at ~1/4 and
        // 3/4 width, arcs leaning slightly outward from the wall plane.
        const wrap = wall + rimGrow;
        const zf = d / 2 + rimGrow;
        addArchHandle(group, {
            endA: new THREE.Vector3(-w * 0.25, rimTop, zf),
            endB: new THREE.Vector3(w * 0.25, rimTop, zf),
            normalA: new THREE.Vector3(0, 0, 1), normalB: new THREE.Vector3(0, 0, 1),
            rise: h * 0.48, radius: 0.62, leanDir: new THREE.Vector3(0, 0, 1), lean: 0.8,
            rimTop, wrap,
            tubeMaterial: materials.handle, tabMaterial: materials.bindingTab,
            rivet: materials.brass, stitch: materials.stitch,
            stitchSide: -1,
        });
        addArchHandle(group, {
            endA: new THREE.Vector3(-w * 0.25, rimTop, -zf),
            endB: new THREE.Vector3(w * 0.25, rimTop, -zf),
            normalA: new THREE.Vector3(0, 0, -1), normalB: new THREE.Vector3(0, 0, -1),
            rise: h * 0.45, radius: 0.55, leanDir: new THREE.Vector3(0, 0, -1), lean: 0.8,
            rimTop, wrap,
            tubeMaterial: materials.handleSecondary, tabMaterial: materials.bindingTab,
            rivet: materials.brass, stitch: materials.stitch,
            stitchSide: 1,
        });
    }
    return group;
}

/**
 * Satchel (Lupa): small boxy open-top bag (no flap) in tan checker weave
 * with one mid mosaic band plus mosaic rim and base trim, and a single tan
 * arch top handle with tabs and brass rivets.
 */
function buildSatchel(dims, materials, pockets) {
    const { width: w, height: h, depth: d } = dims;
    const group = new THREE.Group();
    const wall = 0.7;
    const rings = [
        { y: 0, w: w * 0.955, d: d * 0.94, r: 1.1 },
        { y: h * 0.5, w: w * 0.985, d: d * 0.975, r: 1.25 }, // soft mid bulge
        { y: h, w, d, r: 1.4 },
    ];
    group.add(new THREE.Mesh(
        buildLoft(rings, {
            wallThickness: wall, capBottom: true, segmentsPerCorner: 12,
            patternUV: patternUVFor(materials, h), rimSag: 0.2,
        }),
        materials.body,
    ));

    addOpenInterior(group, rings, wall, h, materials, pockets, dims);
    group.userData.flap = null; // open top: no lid slab, peek shows the interior
    group.userData.interiorTarget = new THREE.Vector3(0, h * 0.4, 0);

    // Mid mosaic band, mosaic base trim, thin mosaic rim.
    const bands = materials.pattern?.bands ?? [];
    bands.forEach((band, i) => {
        addBandLoft(group, rings, wall, band.at * h, (band.at + band.height) * h, materials, i);
    });
    const rimGrow = 0.4;
    addBandLoft(group, rings, wall, h - 0.8, h + 0.1, materials, 'rim', rimGrow);
    const rimTop = h + 0.1;

    addRimPiping(group, w + rimGrow + 0.08, d + rimGrow + 0.08, 1.6, rimTop - 0.04, materials.binding);
    const rimRing = ringAt(rings, h - 0.85);
    addStitches(group, [rectPathXZ(rimRing.w + 0.2, rimRing.d + 0.2, rimRing.r, h - 0.85)], materials.stitch);
    addCornerSeams(group, rings, wall, h, materials.stitch);

    if (materials.handleStyle === 'top_handle') {
        // Single arch spanning left-right, ends riveted to tabs wrapping the
        // rim on the left and right side walls (matches the SVG front view).
        addArchHandle(group, {
            endA: new THREE.Vector3(-(w / 2 + rimGrow), rimTop, 0),
            endB: new THREE.Vector3(w / 2 + rimGrow, rimTop, 0),
            normalA: new THREE.Vector3(-1, 0, 0), normalB: new THREE.Vector3(1, 0, 0),
            rise: h * 0.6, radius: 0.7,
            rimTop, wrap: wall + rimGrow,
            tubeMaterial: materials.handle, tabMaterial: materials.bindingTab,
            rivet: materials.brass, stitch: materials.stitch,
            stitchSide: -1,
        });
    }

    return group;
}

/**
 * Sling (Tala): bucket bag widening toward a rounded tan base panel, body in
 * vertical rust/blue/white/near-black stripes with fine horizontal weave
 * lines, curved tan rim band near the top, and a single tall rust arch
 * handle anchored at the rim ends with dark rivets (or a crossbody webbing
 * strap for admin configs). Open top, no flap.
 */
function buildSling(dims, materials, pockets) {
    const { width: w, height: h, depth: d } = dims;
    const group = new THREE.Group();
    const wall = 0.6;

    // Bucket silhouette: widens toward the base with rounded bottom corners,
    // gently narrower at the rim (matches the SVG); soft mid-wall bulge.
    const rings = [
        { y: 0, w: w * 0.89, d: d * 0.72, r: 1.7 },
        { y: h * 0.045, w: w * 0.97, d: d * 0.92, r: 2.2 },
        { y: h * 0.12, w, d, r: 2.4 },
        { y: h * 0.45, w: w * 0.97, d: d * 0.97, r: 2.2 },
        { y: h * 0.85, w: w * 0.93, d: d * 0.93, r: 2.0 },
        { y: h, w: w * 0.91, d: d * 0.91, r: 1.9 },
    ];
    group.add(new THREE.Mesh(
        buildLoft(rings, {
            wallThickness: wall, capBottom: true, segmentsPerCorner: 12,
            patternUV: patternUVFor(materials, h), rimSag: 0.65,
        }),
        materials.body,
    ));

    addOpenInterior(group, rings, wall, h, materials, pockets, dims);
    group.userData.flap = null; // open top bucket: no flap, no lid
    group.userData.interiorTarget = new THREE.Vector3(0, h * 0.4, 0);

    // Smooth leather rim band (arced, flush) + reinforced leather base panel;
    // both get a stitched seam where they meet the weave.
    const rimGrow = 0.15;
    const rimTop = h + 0.05;
    addArchedRimBand(group, rings, wall, h - 1.6, h, materials, rimGrow, 0.7, materials.binding);
    const baseRings = rings.filter((ring) => ring.y < h * 0.22);
    const baseTop = ringAt(rings, h * 0.22);
    baseRings.push({ ...baseTop, y: h * 0.22 });
    group.add(new THREE.Mesh(buildLoft(baseRings.map((ring) => ({
        ...ring, w: ring.w + 0.12, d: ring.d + 0.12,
    })), { wallThickness: wall, capBottom: true }), materials.binding));

    const rimRing = ringAt(rings, h - 1.5);
    const baseRing = ringAt(rings, h * 0.22);
    addStitches(group, [
        rectPathXZ(rimRing.w + 0.2, rimRing.d + 0.2, rimRing.r, h - 1.5),
        rectPathXZ(baseRing.w + 0.12, baseRing.d + 0.12, baseRing.r, h * 0.22),
    ], materials.stitch);
    addCornerSeams(group, rings, wall, h, materials.stitch);

    if (materials.handleStyle === 'top_handle') {
        // Tall rust arch spanning left-right, ends on rim-wrapping tabs on the
        // left/right side walls, dark rivets through the outer faces.
        addArchHandle(group, {
            endA: new THREE.Vector3(-(w * 0.91 / 2 + rimGrow / 2), rimTop, 0),
            endB: new THREE.Vector3(w * 0.91 / 2 + rimGrow / 2, rimTop, 0),
            normalA: new THREE.Vector3(-1, 0, 0), normalB: new THREE.Vector3(1, 0, 0),
            rise: h * 0.8, radius: 0.5,
            rimTop, wrap: wall + rimGrow,
            tubeMaterial: materials.handle, tabMaterial: materials.bindingTab,
            rivet: materials.darkMetal, stitch: materials.stitch,
            stitchSide: -1,
        });
    } else if (materials.handleStyle === 'crossbody_strap') {
        // Flat webbing ribbon arcing above the body, ends on rim-wrapping tabs
        // with brass rings on the left/right side walls (admin-created slings).
        const rise = h * 0.95;
        const ax = w / 2 + rimGrow + 0.6;
        const curve = new THREE.CatmullRomCurve3([
            new THREE.Vector3(-ax, rimTop + 0.3, 0),
            new THREE.Vector3(-w * 0.52, h * 0.85, 0),
            new THREE.Vector3(-w * 0.3, h + rise * 0.72, 0),
            new THREE.Vector3(0, h + rise, 0),
            new THREE.Vector3(w * 0.3, h + rise * 0.72, 0),
            new THREE.Vector3(w * 0.52, h * 0.85, 0),
            new THREE.Vector3(ax, rimTop + 0.3, 0),
        ]);
        const strap = new THREE.Mesh(ribbonGeometry(curve, 2.4, 72), materials.webbing);
        strap.userData.isHandle = true;
        group.add(strap);

        const ringGeometry = new THREE.TorusGeometry(1.0, 0.24, 10, 24);
        for (const side of [-1, 1]) {
            addRimTab(group, new THREE.Vector3(side * (w * 0.92 / 2 + rimGrow), rimTop, 0),
                new THREE.Vector3(side, 0, 0), rimTop, wall + rimGrow,
                { tabMaterial: materials.bindingTab, rivet: materials.brass, tabW: 2.0, tabH: 2.2 });
            const ring = new THREE.Mesh(ringGeometry, materials.brass);
            ring.position.set(side * (w * 0.92 / 2 + rimGrow + 0.6), rimTop + 0.9, 0);
            ring.rotation.y = Math.PI / 2;
            ring.userData.isHandle = true;
            group.add(ring);
        }
    }

    return group;
}

/**
 * Clutch (Maya): flat envelope in golden yellow with a blue pixel/diamond
 * cross-stitch pattern and white accents, and a hinged envelope flap that
 * sits flush on the body — a thin strip over the top opening plus a V panel
 * down the front face meeting at a centre point with a small blue tab
 * clasp. The whole flap group swings back on Peek.
 */
function buildClutch(dims, materials, pockets) {
    const { width: w, height: h, depth: d } = dims;
    const group = new THREE.Group();
    const wall = 0.28;
    // Thin gusseted pouch: rounded bottom corners, softly full middle,
    // compressed mouth. Dimensions remain authored in three_config.
    const rings = [
        { y: 0, w: w * 0.91, d: d * 0.48, r: d * 0.23 },
        { y: h * 0.035, w: w * 0.97, d: d * 0.75, r: d * 0.35 },
        { y: h * 0.11, w, d: d * 0.92, r: d * 0.44 },
        { y: h * 0.45, w: w * 1.005, d, r: d * 0.48 },
        { y: h * 0.85, w, d: d * 0.78, r: d * 0.36 },
        { y: h, w: w * 0.985, d: d * 0.55, r: d * 0.26 },
    ];
    group.add(new THREE.Mesh(
        buildLoft(rings, {
            wallThickness: wall, capBottom: true, segmentsPerCorner: 12,
            patternUV: patternUVFor(materials, h),
        }),
        materials.body,
    ));

    // A single thin perimeter seam, not rigid metal-looking box rails.
    for (const side of [-1, 1]) {
        const points = rings.map((ring) => new THREE.Vector3(side * ring.w / 2, ring.y, 0));
        group.add(new THREE.Mesh(new THREE.TubeGeometry(
            new THREE.CatmullRomCurve3(points), 48, 0.1, 6, false,
        ), materials.binding));
    }
    const bottomSeam = new THREE.Mesh(
        new THREE.CapsuleGeometry(0.1, rings[0].w - 0.2, 4, 6), materials.binding,
    );
    bottomSeam.rotation.z = Math.PI / 2;
    bottomSeam.position.y = 0.02;
    group.add(bottomSeam);

    addOpenInterior(group, rings, wall, h, materials, pockets, dims);
    group.userData.interiorTarget = new THREE.Vector3(0, h * 0.45, 0);

    // Hinge at the back top edge; rotation.x = 0 is the flush closed state.
    const drop = h * 0.52;
    const mouth = rings[rings.length - 1];
    const flapWidth = mouth.w;
    // Flap UVs map to the body's pattern space: body u = arc length in cm from
    // the front centre (u = -x on the front face), v = y / h.
    const uAtX = (x) => -x;
    const flapGroup = new THREE.Group();
    flapGroup.position.set(0, h, -mouth.d / 2);
    // Soft fold at the hinge: rolled binding along the back top edge that
    // swings with the flap.
    const hingeRoll = new THREE.Mesh(
        new THREE.CylinderGeometry(0.12, 0.12, flapWidth - mouth.r * 2, 12),
        materials.bindingFlap,
    );
    hingeRoll.rotation.z = Math.PI / 2;
    hingeRoll.position.set(0, 0, 0);
    hingeRoll.userData.isBinding = true;
    flapGroup.add(hingeRoll);

    // Thin plain strip over the top opening, flush with the rim — no floating
    // slab, and no pattern streaks on its degenerate top face.
    const stripShape = roundedRectShape(flapWidth, 0.16, 0.06);
    const strip = new THREE.Mesh(extrudeCentered(stripShape, mouth.d + 0.1, 0.025), materials.flapStrip);
    strip.position.set(0, 0, mouth.d / 2);
    flapGroup.add(strip);

    // V panel lying on the front face, point meeting at mid-body. UVs remapped
    // to the body's pattern space so the pixel pattern continues across the seam.
    const flapShape = new THREE.Shape();
    flapShape.moveTo(-flapWidth / 2, 0.02);
    flapShape.lineTo(flapWidth / 2, 0.02);
    flapShape.lineTo(0, -drop);
    flapShape.closePath();
    const flapGeometry = extrudeCentered(flapShape, 0.16, 0.035);
    const flapPositions = flapGeometry.getAttribute('position');
    for (let i = 0; i < flapPositions.count; i++) {
        const ring = ringAt(rings, h + flapPositions.getY(i));
        flapPositions.setZ(i, flapPositions.getZ(i) + (ring.d - mouth.d) / 2);
    }
    flapGeometry.computeVertexNormals();
    const flap = new THREE.Mesh(flapGeometry, materials.flapBody);
    flap.position.set(0, 0, mouth.d + 0.16);
    remapExtrudeUVToPattern(flap.geometry, uAtX, h, h);
    flapGroup.add(flap);

    // Border stitching following the V outline, inset onto the flap face.
    const centroid = new THREE.Vector2(0, -drop * 0.4);
    const outline = flapShape.getPoints(28).map((p) => {
        const v = new THREE.Vector2(p.x, p.y).lerp(centroid, 0.035);
        const ring = ringAt(rings, h + v.y);
        return new THREE.Vector3(v.x, v.y, (ring.d - mouth.d) / 2 + 0.16);
    });
    addStitches(flap, [outline], materials.stitch, 0.8);

    // Small blue tab clasp at the V point + receiver plate on the body front.
    const tab = new THREE.Mesh(new THREE.BoxGeometry(1.7, 1.0, 0.3), materials.accent);
    tab.position.set(0, -drop + 0.4, (ringAt(rings, h - drop).d - mouth.d) / 2 + 0.2);
    flap.add(tab);
    const receiver = new THREE.Mesh(new THREE.BoxGeometry(1.9, 0.5, 0.25), materials.accent);
    receiver.position.set(0, h - drop - 0.1, ringAt(rings, h - drop).d / 2 + 0.1);
    group.add(receiver);

    group.add(flapGroup);
    group.userData.flap = flapGroup;

    return group;
}

/** Remap an extruded flap to body pattern UV space (u = uAtX(x), v = y/h). */
function remapExtrudeUVToPattern(geometry, uAtX, heightCm, yTop) {
    const pos = geometry.getAttribute('position');
    const uv = geometry.getAttribute('uv');
    for (let i = 0; i < pos.count; i++) {
        uv.setXY(i, uAtX(pos.getX(i)), (yTop + pos.getY(i)) / heightCm);
    }
    geometry.userData.skipUVNormalize = true;
}


const SHAPE_BUILDERS = {
    tote: buildTote,
    satchel: buildSatchel,
    sling: buildSling,
    clutch: buildClutch,
};

/* ------------------------------------------------------------ public API */

/**
 * Build the bag group from a three_config object.
 * Uses the backend config as authored; construction errors reach the page's
 * failure/retry state rather than silently substituting a different product.
 *
 * The returned group rests on y=0, centred on x/z, and exposes the metadata
 * contract documented in the file header: shape, bodyDimensions, interior
 * (lining + pockets group), flap (hinged group or null), rim-axis handle pivots,
 * and interiorTarget (bag-local focus point around body h*0.4).
 *
 * @param {object} config { shape, dimensions, colors, handle_style, material, lining, pockets, pattern? }
 * @returns {THREE.Group} group resting on y=0, centred on x/z.
 */
export function buildBag(config) {
    const {
        shape,
        dimensions: dims,
        colors,
        material,
        handle_style: handleStyle,
        lining: liningKind,
        pockets,
    } = config;

    // Patterned body when the config carries a valid descriptor; otherwise
    // the plain per-material weave tinted by colors.body (admin fallback).
    const pattern = normalizePattern(config.pattern);
    const baseSurface = (TEXTURE_MAKERS[material] ?? TEXTURE_MAKERS.canvas)();
    const liningSurface = (LINING_TEXTURE_MAKERS[liningKind] ?? LINING_TEXTURE_MAKERS.cotton)();
    const leatherSurface = TEXTURE_MAKERS.leather();
    const roughness = MATERIAL_ROUGHNESS[material] ?? 0.9;
    const patternSurface = pattern ? makePatternSurface(pattern, dims.height) : null;

    const bodyMaterial = new THREE.MeshStandardMaterial({
        color: pattern ? 0xffffff : colors.body,
        map: patternSurface ? patternSurface.map : baseSurface.map,
        normalMap: patternSurface ? patternSurface.normal : baseSurface.normal,
        roughnessMap: patternSurface ? patternSurface.rough : baseSurface.rough,
        normalScale: new THREE.Vector2(0.38, 0.38),
        roughness, metalness: 0.02,
        // Both sides of the open shell remain visible during inspection.
        side: THREE.DoubleSide,
        envMapIntensity: 0.5, // low reflection on fibres
    });
    const trimMaterial = new THREE.MeshStandardMaterial({
        color: colors.trim, map: baseSurface.map, normalMap: baseSurface.normal,
        roughnessMap: baseSurface.rough,
        normalScale: new THREE.Vector2(0.7, 0.7),
        roughness: Math.max(0.4, roughness - 0.1), metalness: 0.05,
        envMapIntensity: 0.6,
    });
    // Rolled leather binding (rim piping, base panel, edge binding, tabs):
    // leather grain, a touch more env reflection than the woven body.
    // Optional colors.binding overrides the trim tone (e.g. clutch edges).
    const bindingColor = isHexColor(colors.binding) ? colors.binding : colors.trim;
    const bindingMaterial = new THREE.MeshStandardMaterial({
        color: bindingColor, map: leatherSurface.map, normalMap: leatherSurface.normal,
        roughnessMap: leatherSurface.rough,
        normalScale: new THREE.Vector2(0.18, 0.18),
        roughness: 0.65, metalness: 0, envMapIntensity: 0.65,
        // double-sided: rim band / base panel inner faces show in Peek
        side: THREE.DoubleSide,
    });

    // One mosaic band material per configured band (+ 'rim'), pattern space.
    const bandMats = new Map();
    if (pattern && pattern.bands.length) {
        const cellCm = pattern.kind === 'checker' ? pattern.cell : pattern.period / 8;
        pattern.bands.forEach((band, i) => {
            bandMats.set(i, makeBandMaterial(
                band.colors, cellCm, Math.max(0.6, band.height * dims.height),
            ));
        });
        bandMats.set('rim', makeBandMaterial(pattern.bands[0].colors, cellCm, 1.0));
    }

    const handleColor = isHexColor(colors.handle) ? colors.handle : colors.trim;
    const handleSecondaryColor = isHexColor(colors.handle_secondary)
        ? colors.handle_secondary : tint(handleColor, 1.25);
    const accentColor = isHexColor(colors.trim) ? colors.trim : '#426A94';

    const materials = {
        body: bodyMaterial,
        trim: trimMaterial,
        bandMats,
        pattern,
        flapBody: bodyMaterial,
        // Plain (unpatterned) strip for the clutch's top flap edge — avoids
        // streaking where an extruded face has degenerate pattern UVs.
        flapStrip: new THREE.MeshStandardMaterial({
            color: pattern ? (pattern.base ?? pattern.colors?.[0] ?? colors.body) : colors.body,
            roughness: 0.85, metalness: 0.02, envMapIntensity: 0.6,
        }),
        handle: bindingMaterial.clone(),
        handleSecondary: bindingMaterial.clone(),
        // Leather accents share grain maps but retain their own color.
        binding: bindingMaterial,
        bindingTab: bindingMaterial.clone(),
        bindingFlap: bindingMaterial.clone(),
        webbing: new THREE.MeshStandardMaterial({
            color: colors.trim, map: baseSurface.map, normalMap: baseSurface.normal,
            roughnessMap: baseSurface.rough,
            normalScale: new THREE.Vector2(0.5, 0.5),
            roughness: 0.95, metalness: 0, side: THREE.DoubleSide, envMapIntensity: 0.5,
        }),
        // Brass-style hardware: full metal, low roughness; the viewer's env
        // map makes it read as polished metal.
        brass: new THREE.MeshStandardMaterial({
            color: BRASS_COLOR, metalness: 1.0, roughness: 0.26, envMapIntensity: 1.35,
        }),
        // Dark rivet metal for sling-style handle anchors.
        darkMetal: new THREE.MeshStandardMaterial({
            color: DARK_METAL_COLOR, metalness: 0.7, roughness: 0.42, envMapIntensity: 1.1,
        }),
        // Flap tab clasp accent (blue tab on the clutch envelope).
        accent: new THREE.MeshStandardMaterial({
            color: accentColor, roughness: 0.5, metalness: 0.1, envMapIntensity: 0.8,
        }),
        lining: new THREE.MeshStandardMaterial({
            color: colors.lining, map: liningSurface.map, normalMap: liningSurface.normal,
            roughnessMap: liningSurface.rough,
            normalScale: new THREE.Vector2(0.5, 0.5),
            roughness: 1, metalness: 0, side: THREE.DoubleSide, envMapIntensity: 0.4,
        }),
        pocketFabric: new THREE.MeshStandardMaterial({
            color: shade(colors.lining, 0.86), map: liningSurface.map, normalMap: liningSurface.normal,
            roughnessMap: liningSurface.rough,
            roughness: 1, metalness: 0, side: THREE.DoubleSide, envMapIntensity: 0.4,
        }),
        pocketHardware: new THREE.MeshStandardMaterial({
            color: BRASS_COLOR, roughness: 0.3, metalness: 1.0, envMapIntensity: 1.2,
        }),
        // Contrast stitching on fabric and leather seams.
        stitch: new THREE.MeshStandardMaterial({
            color: tint(colors.lining, 1.12), roughness: 0.85, metalness: 0, envMapIntensity: 0.3,
        }),
        handleStyle,
        hex: colors,
    };
    materials.handle.color.set(handleColor);
    materials.handleSecondary.color.set(handleSecondaryColor);

    const group = SHAPE_BUILDERS[shape](dims, materials, pockets);

    group.userData.bodyDimensions = dims;

    // ExtrudeGeometry's default UVs use centimeter coordinates (the lofted
    // shells flag the same); normalize them so the generated weave stays
    // legible instead of repeating per centimeter. Pattern-space geometry
    // (patternUV / skipUVNormalize) is already in texture units.
    const textureScale = Math.max(dims.width, dims.height, dims.depth);
    group.traverse((node) => {
        const geometry = node.geometry;
        if (!geometry) return;
        if (geometry.userData.skipUVNormalize) return;
        if (geometry.type !== 'ExtrudeGeometry' && !geometry.userData.uvInCentimeters) return;
        const uv = geometry.getAttribute('uv');
        for (let i = 0; i < uv.count; i++) {
            uv.setXY(i, uv.getX(i) / textureScale, uv.getY(i) / textureScale);
        }
    });

    // Rest the bag on y=0 and centre it on x/z so the viewer can frame it.
    const box = new THREE.Box3().setFromObject(group);
    const center = box.getCenter(new THREE.Vector3());
    group.position.x -= center.x;
    group.position.z -= center.z;
    group.position.y -= box.min.y;
    group.userData.shape = shape;

    return group;
}
