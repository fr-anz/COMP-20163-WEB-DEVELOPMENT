/**
 * bagFactory.js — procedural bag geometry for the Habi 3D viewer (SDD §11, R6).
 *
 * Builds an exterior bag mesh group from a `three_config` object
 * (shape, dimensions in cm, colors, handle_style, material). All geometry is
 * generated at runtime — no model files. Surface texture is an original
 * canvas-generated weave/textile pattern per material (rattan/canvas/leather),
 * drawn in neutral greys and tinted by the configured colors.
 *
 * M2 scope: exterior only. Interior lining/peek geometry (M3) is intentionally
 * not built here; the open-top tote shows a simple lining surface so the
 * silhouette reads correctly.
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

const TEXTURE_MAKERS = {
    rattan: makeRattanTexture,
    canvas: makeCanvasTexture2D,
    leather: makeLeatherTexture,
};

/* --------------------------------------------------------- bag builders */

function buildTote(dims, materials) {
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

    // Lining surface visible through the open top (simple M2 stand-in; the
    // M3 interior group with pockets/divider is out of scope here).
    const lining = new THREE.Mesh(
        new THREE.BoxGeometry(w - wall * 2 - 0.3, h * 0.92, d - wall * 2 - 0.3),
        materials.lining,
    );
    lining.position.y = wall + (h * 0.92) / 2;
    group.add(lining);

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
            const handle = new THREE.Mesh(handleGeometry, materials.trim);
            handle.position.set(0, h - 0.4, side * (d / 2 - 0.5));
            handle.castShadow = true;
            group.add(handle);
        }
    }

    return group;
}

function buildSatchel(dims, materials) {
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

    // Front flap hinged over the top edge.
    const flapHeight = h * 0.52;
    const flap = new THREE.Mesh(
        extrudeCentered(roundedRectShape(w + 0.4, flapHeight, 2.2), 1.0, 0.25),
        materials.body,
    );
    flap.position.set(0, h + 0.15 - flapHeight / 2 + footHeight, d / 2 + 0.5);
    group.add(flap);

    // Clasp on the flap plus a small pull tab.
    const clasp = new THREE.Mesh(new THREE.BoxGeometry(1.7, 1.3, 0.6), materials.trim);
    clasp.position.set(0, h + 0.15 - flapHeight + footHeight + 0.4, d / 2 + 1.15);
    group.add(clasp);

    if (materials.handleStyle === 'top_handle') {
        const radius = w * 0.24;
        const handleGeometry = new THREE.TorusGeometry(radius, 0.8, 10, 26, Math.PI);
        const handle = new THREE.Mesh(handleGeometry, materials.trim);
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

function buildSling(dims, materials) {
    const { width: w, height: h, depth: d } = dims;
    const group = new THREE.Group();
    const bevel = Math.min(w, h, d) * 0.22;

    const body = new THREE.Mesh(
        extrudeCentered(roundedRectShape(w - bevel * 2, h - bevel * 2, Math.min(w, h) * 0.3), d - bevel * 2, bevel),
        materials.body,
    );
    body.position.y = h / 2;
    group.add(body);

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
        const strap = new THREE.Mesh(new THREE.TubeGeometry(curve, 40, 0.7, 10), materials.trim);
        group.add(strap);

        const tabGeometry = new THREE.BoxGeometry(1.4, 2.4, d * 0.55);
        for (const side of [-1, 1]) {
            const tab = new THREE.Mesh(tabGeometry, materials.trim);
            tab.position.set(side * (w / 2 - 0.2), attachY, 0);
            group.add(tab);
        }
    }

    return group;
}

function buildClutch(dims, materials) {
    const { width: w, height: h, depth: d } = dims;
    const group = new THREE.Group();
    const bevel = 0.3;

    const body = new THREE.Mesh(
        extrudeCentered(roundedRectShape(w - bevel * 2, h - bevel * 2, 1.6), d - bevel * 2, bevel),
        materials.body,
    );
    body.position.y = h / 2;
    group.add(body);

    // Top band defining the fold line.
    const band = new THREE.Mesh(new THREE.BoxGeometry(w + 0.2, 0.9, d + 0.3), materials.trim);
    band.position.y = h - 0.45;
    group.add(band);

    // Envelope flap with a downward point.
    const flapWidth = w * 0.99;
    const drop = h * 0.5;
    const flapShape = new THREE.Shape();
    flapShape.moveTo(-flapWidth / 2, 0);
    flapShape.lineTo(flapWidth / 2, 0);
    flapShape.lineTo(flapWidth / 2, -drop * 0.55);
    flapShape.lineTo(0, -drop);
    flapShape.lineTo(-flapWidth / 2, -drop * 0.55);
    flapShape.closePath();
    const flap = new THREE.Mesh(
        new THREE.ExtrudeGeometry(flapShape, {
            depth: 0.7, bevelEnabled: true, bevelThickness: 0.15, bevelSize: 0.15, bevelSegments: 2,
        }),
        materials.body,
    );
    flap.position.set(0, h - 0.9, d / 2 + 0.1);
    group.add(flap);

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
 * Build the exterior bag group from a three_config object.
 * Uses the backend config as authored; construction errors reach the page's
 * failure/retry state rather than silently substituting a different product.
 *
 * @param {object} config { shape, dimensions, colors, handle_style, material }
 * @returns {THREE.Group} group resting on y=0, centred on x/z.
 */
export function buildBag(config) {
    const { shape, dimensions: dims, colors, material, handle_style: handleStyle } = config;

    // One shared texture per bag; body/trim tint it via material color.
    const texture = TEXTURE_MAKERS[material]();
    const roughness = MATERIAL_ROUGHNESS[material];
    const materials = {
        body: new THREE.MeshStandardMaterial({ color: colors.body, map: texture, roughness, metalness: 0.02 }),
        trim: new THREE.MeshStandardMaterial({ color: colors.trim, map: texture, roughness: Math.max(0.4, roughness - 0.1), metalness: 0.05 }),
        lining: new THREE.MeshStandardMaterial({ color: colors.lining, roughness: 1, metalness: 0, side: THREE.BackSide }),
        handleStyle,
    };

    const group = SHAPE_BUILDERS[shape](dims, materials);

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
