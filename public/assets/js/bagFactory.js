/**
 * bagFactory.js — procedural bag geometry for the Habi 3D viewer (SDD §11, R6).
 *
 * Builds a bag mesh group from a `three_config` object (shape, dimensions in
 * cm, colors, handle_style, material, lining, pockets). All geometry is
 * generated at runtime — no model files. Surface texture is an original
 * canvas-generated weave/textile pattern per material (rattan/canvas/leather),
 * drawn in neutral greys and tinted by the configured colors; the lining gets
 * its own weave scaled by the configured lining kind (cotton/canvas).
 *
 * Every bag is a hollow shell: the exterior silhouette meshes plus a separate
 * interior group (userData.interior) holding the lining and any configured
 * pockets, offset from the shell so no faces are coplanar. The viewer's
 * cutaway plane removes the upper shell + lining to reveal the cavity.
 *
 * Group metadata contract consumed by viewer3d.js:
 * - userData.shape            shape id
 * - userData.bodyDimensions   config.dimensions (cm)
 * - userData.interior         THREE.Group holding lining + pockets
 * - userData.flap             THREE.Group hinged at the back rim, or null
 *                             for open (tote) and zip (sling) tops;
 *                             rotation.x = 0 is the exact closed state,
 *                             -Math.PI folds it back, clear of the cavity
 * - userData.cutawayMaterials materials the viewer may clip (shell, lining,
 *                             obstructing trim); never shared with the flap,
 *                             pockets, or handles
 * - userData.interiorTarget   THREE.Vector3 focus point in bag-local coords
 */
import * as THREE from 'three';

const MATERIAL_ROUGHNESS = { rattan: 0.72, canvas: 0.95, leather: 0.48 };

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
    const value = parseInt(hex.slice(1), 16);
    const channel = (shift) => Math.round(((value >> shift) & 255) * factor);
    const toHex = (n) => n.toString(16).padStart(2, '0');
    return `#${toHex(channel(16))}${toHex(channel(8))}${toHex(channel(0))}`;
}


/* ------------------------------------------------------- canvas textures */

/**
 * Rattan: basket-weave of over/under strands with per-strand shading.
 * Drawn in greys; the material color provides the tint.
 */
function makeRattanTexture() {
    const size = 256;
    const cells = 8;
    const cell = size / cells;
    const canvas = makeDrawingCanvas(size);
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#5a5a5a'; // shadow in the gaps between strands
    ctx.fillRect(0, 0, size, size);

    for (let row = 0; row < cells; row++) {
        for (let col = 0; col < cells; col++) {
            const horizontal = (row + col) % 2 === 0;
            const x = col * cell;
            const y = row * cell;
            const gradient = horizontal
                ? ctx.createLinearGradient(x, y, x, y + cell)
                : ctx.createLinearGradient(x, y, x + cell, y);
            gradient.addColorStop(0, '#7e7e7e');
            gradient.addColorStop(0.45, '#dcdcdc');
            gradient.addColorStop(1, '#6f6f6f');
            ctx.fillStyle = gradient;
            traceRoundedRect(ctx, x + 1.5, y + 1.5, cell - 3, cell - 3, cell * 0.32);
            ctx.fill();
            // strand sheen line
            ctx.strokeStyle = 'rgba(255,255,255,0.28)';
            ctx.lineWidth = 1.2;
            ctx.beginPath();
            if (horizontal) {
                ctx.moveTo(x + 3, y + cell * 0.38);
                ctx.lineTo(x + cell - 3, y + cell * 0.38);
            } else {
                ctx.moveTo(x + cell * 0.38, y + 3);
                ctx.lineTo(x + cell * 0.38, y + cell - 3);
            }
            ctx.stroke();
        }
    }
    return finishTexture(canvas, 3, 3);
}

/** Canvas textile: fine crosshatch weave with seeded fibre noise. */
function makeCanvasTexture2D() {
    const size = 256;
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
    for (let i = 0; i < 500; i++) {
        const dark = random() > 0.5;
        ctx.fillStyle = dark ? 'rgba(0,0,0,0.06)' : 'rgba(255,255,255,0.07)';
        ctx.fillRect(random() * size, random() * size, 1 + random() * 2, 1);
    }
    return finishTexture(canvas, 4, 4);
}

/** Leather: mottled grain with faint creases and pores. */
function makeLeatherTexture() {
    const size = 256;
    const canvas = makeDrawingCanvas(size);
    const ctx = canvas.getContext('2d');
    const random = mulberry32(0x5EED);

    ctx.fillStyle = '#c4c4c4';
    ctx.fillRect(0, 0, size, size);

    for (let i = 0; i < 70; i++) {
        const x = random() * size;
        const y = random() * size;
        const r = 8 + random() * 26;
        const gradient = ctx.createRadialGradient(x, y, 0, x, y, r);
        const light = random() > 0.5;
        gradient.addColorStop(0, light ? 'rgba(255,255,255,0.10)' : 'rgba(0,0,0,0.09)');
        gradient.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = gradient;
        ctx.beginPath();
        ctx.ellipse(x, y, r, r * (0.5 + random() * 0.5), random() * Math.PI, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.strokeStyle = 'rgba(0,0,0,0.06)';
    ctx.lineWidth = 1;
    for (let i = 0; i < 26; i++) {
        const x = random() * size;
        const y = random() * size;
        const r = 6 + random() * 18;
        ctx.beginPath();
        ctx.arc(x, y, r, random() * Math.PI, random() * Math.PI + Math.PI * 0.8);
        ctx.stroke();
    }
    for (let i = 0; i < 700; i++) {
        ctx.fillStyle = random() > 0.5 ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.05)';
        ctx.fillRect(random() * size, random() * size, 1, 1);
    }
    return finishTexture(canvas, 2, 2);
}

function finishTexture(canvas, repeatX, repeatY) {
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.repeat.set(repeatX, repeatY);
    return texture;
}

/**
 * Lining weaves, tinted by colors.lining: cotton is a fine plain weave,
 * canvas a chunkier one. Drawn in light greys so pale lining colors stay pale.
 */
function makeLiningTexture(coarse) {
    const size = 256;
    const canvas = makeDrawingCanvas(size);
    const ctx = canvas.getContext('2d');
    const random = mulberry32(coarse ? 0xCAFE12 : 0xC077EE);
    const pitch = coarse ? 6 : 3;

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
    for (let i = 0; i < 350; i++) {
        ctx.fillStyle = random() > 0.5 ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.06)';
        ctx.fillRect(random() * size, random() * size, 1 + random() * 2, 1);
    }
    return finishTexture(canvas, coarse ? 3 : 4, coarse ? 3 : 4);
}

const TEXTURE_MAKERS = {
    rattan: makeRattanTexture,
    canvas: makeCanvasTexture2D,
    leather: makeLeatherTexture,
};

const LINING_TEXTURE_MAKERS = {
    cotton: () => makeLiningTexture(false),
    canvas: () => makeLiningTexture(true),
};

/* --------------------------------------------------------- bag builders */

/* Interior pocket conventions:
 * - Wall pockets tuck their back face POCKET_EMBED behind the lining surface
 *   (never coplanar) and protrude the rest into the cavity.
 * - Floor-standing pieces (divider, bottle slip) sink POCKET_SINK below the
 *   pocket base level so their bottom caps hide inside the lining floor.
 * - All detail stays low: pocket tops sit below the viewer's cutaway height
 *   (bag.position.y + h * 0.68) even though pocket materials are unclipped.
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
 * other than back_slip) describe outside features and deliberately get no
 * interior geometry — no fake interior pockets are invented for them.
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

function buildTote(dims, materials, pockets) {
    const { width: w, height: h, depth: d } = dims;
    const group = new THREE.Group();
    const wall = 0.9;

    // Walls: rounded-rect ring (outer + inner hole) extruded vertically —
    // an open-top container with visible inner surfaces.
    const ring = roundedRectShape(w, d, 2);
    ring.holes.push(addRoundedRect(new THREE.Path(), w - wall * 2, d - wall * 2, 1.2));
    const wallsGeometry = new THREE.ExtrudeGeometry(ring, { depth: h, bevelEnabled: false, curveSegments: 10 });
    wallsGeometry.rotateX(-Math.PI / 2); // extrusion axis +Z → +Y
    group.add(new THREE.Mesh(wallsGeometry, materials.body));

    // Bottom panel closing the tube.
    const bottom = new THREE.Mesh(
        new THREE.BoxGeometry(w - wall * 2 + 0.6, wall, d - wall * 2 + 0.6),
        materials.body,
    );
    bottom.position.y = wall / 2;
    group.add(bottom);

    // Interior: open-top lining ring inset from the walls (no coplanar faces)
    // plus a lining floor panel, then the configured pockets.
    const interior = new THREE.Group();
    const liningFloorY = wall + 0.22;
    const liningTopY = h - 1.0;
    const liningRing = roundedRectShape(w - 2.1, d - 2.1, 1.5);
    liningRing.holes.push(addRoundedRect(new THREE.Path(), w - 3.0, d - 3.0, 1.1));
    const liningGeometry = new THREE.ExtrudeGeometry(liningRing, {
        depth: liningTopY - liningFloorY,
        bevelEnabled: false,
        curveSegments: 10,
    });
    liningGeometry.rotateX(-Math.PI / 2);
    const lining = new THREE.Mesh(liningGeometry, materials.lining);
    lining.position.y = liningFloorY;
    interior.add(lining);

    const floorPanel = new THREE.Mesh(
        new THREE.BoxGeometry(w - 2.7, 0.12, d - 2.7),
        materials.lining,
    );
    floorPanel.position.y = liningFloorY + 0.06;
    interior.add(floorPanel);
    group.add(interior);

    addPockets(interior, pockets, {
        w, h, d,
        floorY: liningFloorY + 0.34,
        topY: liningTopY,
        leftX: -(w / 2 - 1.5), rightX: w / 2 - 1.5,
        backZ: -(d / 2 - 1.5), frontZ: d / 2 - 1.5,
    }, materials);

    group.userData.interior = interior;
    group.userData.flap = null; // open top: no lid, nothing to hinge
    group.userData.interiorTarget = new THREE.Vector3(0, h * 0.4, 0);

    // Rim band along the top edge hides the raw wall caps.
    const rim = roundedRectShape(w + 0.8, d + 0.8, 2.4);
    rim.holes.push(addRoundedRect(new THREE.Path(), w - wall * 2 + 0.4, d - wall * 2 + 0.4, 1.2));
    const rimGeometry = new THREE.ExtrudeGeometry(rim, { depth: 0.7, bevelEnabled: false, curveSegments: 10 });
    rimGeometry.rotateX(-Math.PI / 2);
    const rimMesh = new THREE.Mesh(rimGeometry, materials.trim);
    rimMesh.position.y = h - 0.05;
    group.add(rimMesh);

    if (materials.handleStyle === 'double_handles') {
        // Paired front/back handles, sharing one arch geometry.
        const radius = w * 0.26;
        const handleGeometry = new THREE.TorusGeometry(radius, 0.65, 10, 28, Math.PI);
        for (const side of [-1, 1]) {
            const handle = new THREE.Mesh(handleGeometry, materials.hardware);
            handle.position.set(0, h - 0.4, side * (d / 2 - 0.5));
            handle.castShadow = true;
            group.add(handle);
        }
    }

    return group;
}

/**
 * Closed lining shell for the solid-body shapes: a profile ring matching the
 * body's rounded-rect cross-section, inset by a uniform clearance that also
 * clears the beveled front/back, and closed on all sides (the viewer's
 * cutaway plane removes its top together with the shell). Returns the cavity
 * metrics used for pocket placement.
 */
function addClosedLining(interior, dims, materials, bodyBottom, profileRadius) {
    const { width: w, height: h, depth: d } = dims;
    const zClear = 0.8;   // lining ends sit this far inside the beveled caps
    const margin = 0.25;  // extra profile clearance at the lining ends
    const thickness = 0.45;

    const outerW = w - 2 * (zClear + margin);
    const outerH = h - 2 * (zClear + margin);
    const outerR = Math.max(0.4, profileRadius - zClear - margin);
    const shape = roundedRectShape(outerW, outerH, outerR);
    shape.holes.push(addRoundedRect(
        new THREE.Path(),
        outerW - thickness * 2,
        outerH - thickness * 2,
        Math.max(0.3, outerR - thickness),
    ));
    const lining = new THREE.Mesh(extrudeCentered(shape, d - 2 * zClear, 0), materials.lining);
    lining.position.y = bodyBottom + h / 2;
    interior.add(lining);

    // The profile ring supplies the sides/floor, not the broad front/back
    // walls. Line those too, offset into the cavity so ring caps never fight.
    const wallGeometry = new THREE.ShapeGeometry(
        roundedRectShape(outerW - 0.1, outerH - 0.1, Math.max(0.3, outerR - 0.05)),
    );
    const wallZ = d / 2 - zClear - 0.04;
    for (const side of [-1, 1]) {
        const wall = new THREE.Mesh(wallGeometry, materials.lining);
        wall.position.set(0, bodyBottom + h / 2, side * wallZ);
        interior.add(wall);
    }

    return {
        floorY: bodyBottom + zClear + margin + thickness + 0.1,
        topY: bodyBottom + h - zClear - margin - 0.2,
        leftX: -(outerW / 2 - thickness),
        rightX: outerW / 2 - thickness,
        backZ: -(d / 2 - zClear - 0.04),
        frontZ: d / 2 - zClear - 0.04,
    };
}

function buildSatchel(dims, materials, pockets) {
    const { width: w, height: h, depth: d } = dims;
    const group = new THREE.Group();
    const bevel = 0.9;
    const footHeight = 0.55;

    const body = new THREE.Mesh(
        extrudeCentered(roundedRectShape(w - bevel * 2, h - bevel * 2, Math.min(w, h) * 0.16), d - bevel * 2, bevel),
        materials.body,
    );
    body.position.y = h / 2 + footHeight;
    group.add(body);

    // Interior: closed lining shell + configured pockets.
    const interior = new THREE.Group();
    group.add(interior);
    const cavity = addClosedLining(interior, dims, materials, footHeight, Math.min(w, h) * 0.16);
    addPockets(interior, pockets, { w, h, d, ...cavity }, materials);

    group.userData.interior = interior;
    group.userData.interiorTarget = new THREE.Vector3(0, footHeight + h * 0.4, 0);

    // Hinge at the back rim so opening swings the flap away from the cavity.
    // Child offsets preserve the exact closed front flap/clasp placement.
    const flapHeight = h * 0.52;
    const flapGroup = new THREE.Group();
    flapGroup.position.set(0, h + 0.15 + footHeight, -d / 2);
    const flap = new THREE.Mesh(
        extrudeCentered(roundedRectShape(w + 0.4, flapHeight, 2.2), 1.0, 0.25),
        materials.flapBody,
    );
    flap.position.set(0, -flapHeight / 2, d + 0.5);
    flapGroup.add(flap);
    const lid = new THREE.Mesh(new THREE.BoxGeometry(w, 0.5, d + 0.5), materials.flapBody);
    lid.position.set(0, -0.1, (d + 0.5) / 2);
    flapGroup.add(lid);

    const clasp = new THREE.Mesh(new THREE.BoxGeometry(1.7, 1.3, 0.6), materials.hardware);
    clasp.position.set(0, 0.4 - flapHeight, d + 1.15);
    flapGroup.add(clasp);
    group.add(flapGroup);
    group.userData.flap = flapGroup;

    if (materials.handleStyle === 'top_handle') {
        const radius = w * 0.24;
        const handleGeometry = new THREE.TorusGeometry(radius, 0.8, 10, 26, Math.PI);
        const handle = new THREE.Mesh(handleGeometry, materials.hardware);
        handle.position.set(0, h + footHeight - 0.5, 0);
        group.add(handle);
    }

    // Four feet sharing one geometry.
    const footGeometry = new THREE.CylinderGeometry(0.55, 0.6, footHeight, 10);
    for (const sx of [-1, 1]) {
        for (const sz of [-1, 1]) {
            const foot = new THREE.Mesh(footGeometry, materials.trim);
            foot.position.set(sx * (w / 2 - 1.6), footHeight / 2, sz * (d / 2 - 1.6));
            group.add(foot);
        }
    }

    return group;
}

function buildSling(dims, materials, pockets) {
    const { width: w, height: h, depth: d } = dims;
    const group = new THREE.Group();
    const bevel = Math.min(w, h, d) * 0.22;

    const body = new THREE.Mesh(
        extrudeCentered(roundedRectShape(w - bevel * 2, h - bevel * 2, Math.min(w, h) * 0.3), d - bevel * 2, bevel),
        materials.body,
    );
    body.position.y = h / 2;
    group.add(body);

    // Interior: closed lining shell + configured pockets. Zip-open top —
    // no flap is invented for this shape.
    const interior = new THREE.Group();
    group.add(interior);
    const cavity = addClosedLining(interior, dims, materials, 0, Math.min(w, h) * 0.3);
    addPockets(interior, pockets, { w, h, d, ...cavity }, materials);

    group.userData.interior = interior;
    group.userData.flap = null;
    group.userData.interiorTarget = new THREE.Vector3(0, h * 0.4, 0);

    // Zip pull across the top edge.
    const pull = new THREE.Mesh(new THREE.TorusGeometry(0.55, 0.16, 8, 16), materials.trim);
    pull.position.set(0, h + 0.1, d / 2 - 1.2);
    pull.rotation.x = Math.PI / 2;
    group.add(pull);

    if (materials.handleStyle === 'crossbody_strap') {
        // Long strap arcing well above the body, attached at both sides.
        const attachY = h * 0.55;
        const rise = h * 1.05;
        const curve = new THREE.CatmullRomCurve3([
            new THREE.Vector3(-w / 2 + 0.4, attachY, 0),
            new THREE.Vector3(-w * 0.42, h + rise * 0.55, 0),
            new THREE.Vector3(0, h + rise, 0),
            new THREE.Vector3(w * 0.42, h + rise * 0.55, 0),
            new THREE.Vector3(w / 2 - 0.4, attachY, 0),
        ]);
        const strap = new THREE.Mesh(new THREE.TubeGeometry(curve, 40, 0.7, 10), materials.hardware);
        group.add(strap);

        const tabGeometry = new THREE.BoxGeometry(1.4, 2.4, d * 0.55);
        for (const side of [-1, 1]) {
            const tab = new THREE.Mesh(tabGeometry, materials.hardware);
            tab.position.set(side * (w / 2 - 0.2), attachY, 0);
            group.add(tab);
        }
    }

    return group;
}

function buildClutch(dims, materials, pockets) {
    const { width: w, height: h, depth: d } = dims;
    const group = new THREE.Group();
    const bevel = 0.3;

    const body = new THREE.Mesh(
        extrudeCentered(roundedRectShape(w - bevel * 2, h - bevel * 2, 1.6), d - bevel * 2, bevel),
        materials.body,
    );
    body.position.y = h / 2;
    group.add(body);

    // Interior: closed lining shell + configured pockets (empty configs stay
    // empty — lining only, no invented pockets).
    const interior = new THREE.Group();
    group.add(interior);
    const cavity = addClosedLining(interior, dims, materials, 0, 1.6);
    addPockets(interior, pockets, { w, h, d, ...cavity }, materials);

    group.userData.interior = interior;
    group.userData.interiorTarget = new THREE.Vector3(0, h * 0.4, 0);

    // Top band defining the fold line.
    const band = new THREE.Mesh(new THREE.BoxGeometry(w + 0.2, 0.9, d + 0.3), materials.trim);
    band.position.y = h - 0.45;
    group.add(band);

    // Envelope flap folds back over the rear rim; the closure stud stays
    // on the body. The child offset preserves its original closed position.
    const flapWidth = w * 0.99;
    const drop = h * 0.5;
    const flapShape = new THREE.Shape();
    flapShape.moveTo(-flapWidth / 2, 0);
    flapShape.lineTo(flapWidth / 2, 0);
    flapShape.lineTo(flapWidth / 2, -drop * 0.55);
    flapShape.lineTo(0, -drop);
    flapShape.lineTo(-flapWidth / 2, -drop * 0.55);
    flapShape.closePath();
    const flapGroup = new THREE.Group();
    flapGroup.position.set(0, h - 0.9, -d / 2);
    const flap = new THREE.Mesh(
        new THREE.ExtrudeGeometry(flapShape, {
            depth: 0.7, bevelEnabled: true, bevelThickness: 0.15, bevelSize: 0.15, bevelSegments: 2,
        }),
        materials.flapBody,
    );
    flap.position.z = d + 0.1;
    flapGroup.add(flap);
    const fold = new THREE.Mesh(new THREE.BoxGeometry(flapWidth, 0.3, d + 0.1), materials.flapBody);
    fold.position.set(0, 0.55, (d + 0.1) / 2);
    flapGroup.add(fold);
    group.add(flapGroup);
    group.userData.flap = flapGroup;

    // Stud where the flap point lands.
    const stud = new THREE.Mesh(new THREE.CylinderGeometry(0.5, 0.55, 0.5, 12), materials.trim);
    stud.rotation.x = Math.PI / 2;
    stud.position.set(0, h - 0.9 - drop, d / 2 + 0.95);
    group.add(stud);

    return group;
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
 * (lining + pockets group), flap (hinged group or null), cutawayMaterials
 * (shell/lining/obstructing trim only — never shared with flap, pockets or
 * handles), and interiorTarget (bag-local focus point around body h*0.4).
 *
 * @param {object} config { shape, dimensions, colors, handle_style, material, lining, pockets }
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

    // One shared texture per bag; body/trim tint it via material color.
    const texture = TEXTURE_MAKERS[material]();
    const roughness = MATERIAL_ROUGHNESS[material];
    const liningTexture = LINING_TEXTURE_MAKERS[liningKind]();

    const bodyMaterial = new THREE.MeshStandardMaterial({
        color: colors.body, map: texture, roughness, metalness: 0.02,
        // Double-sided so the walls still read once the viewer's cutaway plane
        // removes the upper shell (from inside, front faces alone would leave
        // the body invisible).
        side: THREE.DoubleSide,
    });
    const trimMaterial = new THREE.MeshStandardMaterial({
        color: colors.trim, map: texture,
        roughness: Math.max(0.4, roughness - 0.1), metalness: 0.05,
    });
    const materials = {
        body: bodyMaterial,
        trim: trimMaterial,
        // Clones keep the flap and the handles out of the cutaway material
        // list so the clipping plane never slices them.
        flapBody: bodyMaterial.clone(),
        hardware: trimMaterial.clone(),
        lining: new THREE.MeshStandardMaterial({
            color: colors.lining, map: liningTexture, roughness: 1, metalness: 0,
            side: THREE.DoubleSide,
        }),
        pocketFabric: new THREE.MeshStandardMaterial({
            color: shade(colors.lining, 0.86), map: liningTexture, roughness: 1, metalness: 0,
            side: THREE.DoubleSide,
        }),
        pocketHardware: new THREE.MeshStandardMaterial({ color: colors.trim, roughness: 0.35, metalness: 0.45 }),
        handleStyle,
    };

    const group = SHAPE_BUILDERS[shape](dims, materials, pockets);

    // Materials the viewer may clip during inside inspection: shell, lining,
    // and obstructing trim only.
    group.userData.cutawayMaterials = [materials.body, materials.trim, materials.lining];
    group.userData.bodyDimensions = dims;

    // ExtrudeGeometry's default UVs use centimeter coordinates. Normalize them
    // so the generated weave stays legible instead of repeating per centimeter.
    const textureScale = Math.max(dims.width, dims.height, dims.depth);
    group.traverse((node) => {
        if (node.geometry?.type !== 'ExtrudeGeometry') return;
        const uv = node.geometry.getAttribute('uv');
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
