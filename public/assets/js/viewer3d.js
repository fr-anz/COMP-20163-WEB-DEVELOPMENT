/**
 * viewer3d.js — BagViewer for the Habi product detail page (SDD §11, DSD §4).
 *
 * Contract (M2–M3):
 *   - `new BagViewer(container, config)` initializes and renders synchronously,
 *     or throws an error with `name === 'WebGLUnavailableError'` when WebGL is
 *     unavailable.
 *   - Methods: rotate(h, v), setAutoRotate(enabled), setActive(active),
 *     setInside(inside, { animate }), reset(), dispose().
 *   - OrbitControls pointer/touch orbit with damping (disabled under reduced
 *     motion); exterior idle auto-rotation at 1.5°/s after 3 s without interaction;
 *     setActive(false) suspends the frame loop (hidden Photos, closed dialog,
 *     hidden tab); ResizeObserver handles reparenting/dialog resizing.
 *   - renderer/camera/controls are exposed as public fields for manual
 *     inspection (not test hooks).
 *   - dispose() releases geometry, materials, textures, controls, observer,
 *     listeners and the frame loop; it also runs on pagehide.
 *
 * Reversible interior inspection with shell cutaway and applicable flap opening.
 * Dispatches 'viewchange', 'contextlost' and 'contextrestored' events.
 */
import * as THREE from 'three';
import { OrbitControls } from '../vendor/three/OrbitControls.js';
import { buildBag } from './bagFactory.js';

const IDLE_DELAY_MS = 3000;
const AUTO_ROTATE_DEG_PER_SEC = 1.5;
const MAX_PIXEL_RATIO = 2;

// Published --ease-weave: cubic-bezier(.2, .7, .2, 1).
function viewEase(progress) {
    let low = 0;
    let high = 1;
    let t = progress;
    for (let i = 0; i < 10; i++) {
        const x = 0.6 * t * (1 - t) + t * t * t;
        if (Math.abs(x - progress) < 0.0001) break;
        if (x < progress) low = t;
        else high = t;
        t = (low + high) / 2;
    }
    return 2.1 * t * (1 - t) * (1 - t) + 3 * t * t * (1 - t) + t * t * t;
}

function webglUnavailableError() {
    const error = new Error('WebGL is unavailable in this browser.');
    error.name = 'WebGLUnavailableError';
    return error;
}


/** Soft radial contact-shadow disc drawn on a canvas (original asset). */
function makeContactShadowTexture() {
    const size = 128;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    const gradient = ctx.createRadialGradient(
        size / 2, size / 2, size * 0.08,
        size / 2, size / 2, size / 2,
    );
    gradient.addColorStop(0, 'rgba(0,0,0,0.38)');
    gradient.addColorStop(0.55, 'rgba(0,0,0,0.16)');
    gradient.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, size, size);
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    return texture;
}

export class BagViewer extends THREE.EventDispatcher {
    /**
     * @param {HTMLElement} container element the canvas is appended to
     * @param {object} config three_config object from GET /api/product/{slug}/3d
     */
    constructor(container, config) {
        super();

        if (!(container instanceof HTMLElement)) {
            throw new TypeError('BagViewer requires a container element.');
        }
        this.container = container;

        this._disposed = false;
        this._active = true;
        this._loopRunning = false;
        this._contextLost = false;
        this._autoRotateEnabled = true;
        this._lastInteraction = performance.now();
        this.isInside = false;
        this._transition = null;
        this._exteriorView = null;

        // Reduced motion is owned by the viewer: no auto-rotation and no
        // damping inertia while the preference is active; changes apply live.
        this._motionQuery = typeof window.matchMedia === 'function'
            ? window.matchMedia('(prefers-reduced-motion: reduce)')
            : null;
        this._reducedMotion = this._motionQuery?.matches === true;

        let renderer;
        try {
            renderer = new THREE.WebGLRenderer({
                antialias: true,
                alpha: true,
                powerPreference: 'high-performance',
            });
        } catch {
            throw webglUnavailableError();
        }
        this.renderer = renderer;
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, MAX_PIXEL_RATIO));
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        renderer.toneMapping = THREE.ACESFilmicToneMapping;
        renderer.toneMappingExposure = 1.05;
        renderer.localClippingEnabled = true;

        const canvas = renderer.domElement;
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', 'Procedural bag exterior. Drag or use the rotation buttons to inspect.');
        canvas.style.display = 'block';
        canvas.style.width = '100%';
        canvas.style.height = '100%';
        canvas.style.touchAction = 'none'; // OrbitControls handles touch
        container.appendChild(canvas);

        const viewerStyle = getComputedStyle(container);
        const backdrop = viewerStyle.getPropertyValue('--color-bg-surface').trim();
        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(backdrop);

        this.camera = new THREE.PerspectiveCamera(35, 1, 0.1, 1000);

        this._addStudioLights();
        this._addGround();

        // Size the canvas/camera before framing so the fit uses the real
        // container aspect (inline panel or fullscreen dialog).
        this._resize();

        this.bag = buildBag(config);
        this.bag.traverse((node) => {
            if (node.isMesh) node.castShadow = true;
        });
        this.scene.add(this.bag);
        const interior = this.bag.userData;
        const dims = interior.bodyDimensions;
        this._insideTarget = this.bag.localToWorld(interior.interiorTarget.clone());
        this._bodyRadius = Math.hypot(dims.width, dims.height, dims.depth) / 2;
        this._cutaway = new THREE.Plane(
            new THREE.Vector3(0, -1, 0),
            this.bag.position.y + dims.height * 0.68,
        );
        this._cutawayPlanes = [this._cutaway];
        const box = new THREE.Box3().setFromObject(this.bag);
        const sphere = box.getBoundingSphere(new THREE.Sphere());
        const radius = Math.max(sphere.radius, 1);
        this._positionLights(sphere, radius);
        this._frameBag(box, sphere, radius);

        this.controls = new OrbitControls(this.camera, canvas);
        this.controls.enableDamping = !this._reducedMotion;
        this.controls.dampingFactor = 0.08;
        this.controls.enablePan = false;
        this.controls.minPolarAngle = 0.15;
        this.controls.maxPolarAngle = 1.45;
        this.controls.rotateSpeed = 0.9;
        // 1.5°/s: OrbitControls treats speed 2.0 as 12°/s at 60 fps.
        this.controls.autoRotateSpeed = AUTO_ROTATE_DEG_PER_SEC / 6;
        this.controls.autoRotate = false;
        this.controls.target.copy(this._initialTarget);
        this.controls.update();

        this._clock = new THREE.Clock();
        this._bindEvents();

        this._updateLoopState();
        // Synchronous first frame: the constructor must render before returning.
        this.renderer.render(this.scene, this.camera);
    }

    /* -------------------------------------------------- scene assembly */

    _addStudioLights() {
        this.scene.add(new THREE.HemisphereLight(0xfff3e0, 0x8a7355, 0.65));
        this.scene.add(new THREE.AmbientLight(0xfff2e0, 0.3));

        const key = new THREE.DirectionalLight(0xffe9c9, 2.2);
        key.castShadow = true;
        key.shadow.mapSize.set(1024, 1024);
        key.shadow.bias = -0.0005;
        key.shadow.radius = 4;
        this.scene.add(key);
        this._keyLight = key;

        const rim = new THREE.DirectionalLight(0xffd9a0, 0.8);
        this.scene.add(rim);
        this._rimLight = rim;

        const fill = new THREE.DirectionalLight(0xe8eef8, 0.45);
        this.scene.add(fill);
        this._fillLight = fill;
    }

    _positionLights(sphere, radius) {
        this._keyLight.position.copy(sphere.center).add(
            new THREE.Vector3(radius * 1.2, radius * 1.8, radius * 1.3),
        );
        this._rimLight.position.copy(sphere.center).add(
            new THREE.Vector3(-radius * 1.4, radius * 1.1, -radius * 1.7),
        );
        this._fillLight.position.copy(sphere.center).add(
            new THREE.Vector3(-radius * 1.3, radius * 0.6, radius * 1.3),
        );
    }

    _addGround() {
        this.ground = new THREE.Mesh(
            new THREE.PlaneGeometry(400, 400),
            new THREE.ShadowMaterial({ opacity: 0.22 }),
        );
        this.ground.rotation.x = -Math.PI / 2;
        this.ground.position.y = -0.05;
        this.ground.receiveShadow = true;
        this.scene.add(this.ground);

        // Soft contact shadow disc for grounding under the bag.
        this._contactShadow = new THREE.Mesh(
            new THREE.PlaneGeometry(1, 1),
            new THREE.MeshBasicMaterial({
                map: makeContactShadowTexture(),
                transparent: true,
                depthWrite: false,
            }),
        );
        this._contactShadow.rotation.x = -Math.PI / 2;
        this._contactShadow.position.y = 0.02;
        this.scene.add(this._contactShadow);
    }

    _frameBag(box, sphere, radius) {
        // Fit by the narrower of vertical/horizontal fov so all shapes frame
        // practically in both the inline panel and the fullscreen dialog.
        const vFov = THREE.MathUtils.degToRad(this.camera.fov);
        const hFov = 2 * Math.atan(Math.tan(vFov / 2) * this.camera.aspect);
        const fitFov = Math.min(vFov, hFov);
        const distance = (radius / Math.sin(fitFov / 2)) * 1.12;
        this._frameRadius = radius;
        this._fitDistance = distance;

        this._initialTarget = sphere.center.clone();
        this._initialCameraPosition = new THREE.Vector3().setFromSphericalCoords(
            distance,
            THREE.MathUtils.degToRad(66),
            THREE.MathUtils.degToRad(35),
        ).add(this._initialTarget);

        this.camera.near = Math.max(radius / 100, 0.05);
        this.camera.far = distance * 20;
        this.camera.position.copy(this._initialCameraPosition);
        this.camera.lookAt(this._initialTarget);
        this.camera.updateProjectionMatrix();

        // Fit the key-light shadow frustum and ground contact to the bag.
        const span = radius * 2.4;
        const shadowCamera = this._keyLight.shadow.camera;
        shadowCamera.left = -span;
        shadowCamera.right = span;
        shadowCamera.top = span;
        shadowCamera.bottom = -span;
        shadowCamera.near = 0.1;
        shadowCamera.far = distance * 4;
        shadowCamera.updateProjectionMatrix();
        this._keyLight.target.position.copy(sphere.center);
        this._keyLight.target.updateMatrixWorld();

        const footprint = Math.max(
            box.max.x - box.min.x,
            box.max.z - box.min.z,
            1,
        );
        this._contactShadow.scale.setScalar(footprint * 1.7);
        this._contactShadow.position.x = sphere.center.x;
        this._contactShadow.position.z = sphere.center.z;
    }

    /* ---------------------------------------------------------- events */

    _bindEvents() {
        this._onResize = () => this._resize();
        this._resizeObserver = new ResizeObserver(this._onResize);
        this._resizeObserver.observe(this.container);

        this._onControlStart = () => { this._lastInteraction = performance.now(); };
        this._onControlEnd = () => { this._lastInteraction = performance.now(); };
        this.controls.addEventListener('start', this._onControlStart);
        this.controls.addEventListener('end', this._onControlEnd);

        this._onVisibility = () => this._updateLoopState();
        document.addEventListener('visibilitychange', this._onVisibility);

        this._onMotionChange = (event) => {
            this._reducedMotion = event.matches;
            this.controls.enableDamping = !this._reducedMotion;
            if (this._reducedMotion) this.controls.autoRotate = false;
            if (this._reducedMotion) this._finishTransition();
        };
        this._motionQuery?.addEventListener?.('change', this._onMotionChange);

        this._onContextLost = (event) => {
            event.preventDefault();
            this._contextLost = true;
            this._updateLoopState();
            this.dispatchEvent({ type: 'contextlost' });
        };
        this._onContextRestored = () => {
            this._contextLost = false;
            this._updateLoopState();
            this.dispatchEvent({ type: 'contextrestored' });
        };
        this.renderer.domElement.addEventListener('webglcontextlost', this._onContextLost, false);
        this.renderer.domElement.addEventListener('webglcontextrestored', this._onContextRestored, false);

        this._onPageHide = () => this.dispose();
        window.addEventListener('pagehide', this._onPageHide, { once: true });
    }

    /* ------------------------------------------------------- frame loop */

    _shouldRun() {
        return !this._disposed && this._active && !this._contextLost
            && !document.hidden;
    }

    _updateLoopState() {
        if (this._disposed) return;
        const shouldRun = this._shouldRun();
        if (shouldRun && !this._loopRunning) {
            this._clock.getDelta(); // flush pause duration
            this.renderer.setAnimationLoop(this._onFrame);
            this._loopRunning = true;
        } else if (!shouldRun && this._loopRunning) {
            this.renderer.setAnimationLoop(null);
            this._loopRunning = false;
        }
    }

    _onFrame = () => {
        if (this._disposed) return;
        const deltaTime = this._clock.getDelta();
        const idle = performance.now() - this._lastInteraction > IDLE_DELAY_MS;
        this.controls.autoRotate = this._autoRotateEnabled
            && !this._reducedMotion
            && !this.isInside
            && !this._transition
            && idle
            && this._active;
        const hadTransition = !!this._transition;
        if (this._transition) this._advanceTransition(deltaTime * 1000);
        // deltaTime keeps the 1.5°/s idle rotation frame-rate independent.
        this.controls.update(deltaTime);
        // A completed transition owns the final pose: OrbitControls' update
        // above must not reintroduce residual damping on the exact restore.
        if (hadTransition && !this._transition && this._transitionDestination) {
            this.camera.position.copy(this._transitionDestination.camera);
            this.controls.target.copy(this._transitionDestination.target);
        }
        this.renderer.render(this.scene, this.camera);
    };

    /* ------------------------------------------------------ public API */
    /**
     * Switch inspection modes. Reversal retargets the current camera/flap pose;
     * the original exterior orbit/zoom is retained until the return completes.
     * Keyboard callers and reduced motion use an immediate equivalent state.
     */
    setInside(inside, { animate = true } = {}) {
        if (this._disposed || this._contextLost || inside === this.isInside) return;
        const duration = getComputedStyle(this.container).getPropertyValue('--duration-viewer').trim();
        this._viewDuration = parseFloat(duration) * (duration.endsWith('ms') ? 1 : 1000);
        this._clearInertia();
        if (inside && !this._exteriorView) {
            this._exteriorView = {
                camera: this.camera.position.clone(),
                target: this.controls.target.clone(),
            };
        }
        this.isInside = inside;
        this._lastInteraction = performance.now();
        this.controls.minPolarAngle = 0.15;
        this.controls.maxPolarAngle = inside ? 1.25 : 1.45;
        this.controls.minDistance = inside ? this._bodyRadius * 1.1 : 0;
        this.controls.maxDistance = inside ? this._insideDistance() * 1.8 : Infinity;
        if (inside) this._setCutaway(true);
        this._transition = {
            elapsed: 0,
            fromCamera: this.camera.position.clone(),
            fromTarget: this.controls.target.clone(),
            toCamera: inside ? this._insidePosition() : this._exteriorView.camera.clone(),
            toTarget: inside ? this._insideTarget : this._exteriorView.target,
            fromFlap: this.bag.userData.flap?.rotation.x ?? 0,
            toFlap: inside ? -Math.PI : 0,
        };
        this.controls.enabled = false;
        this.renderer.domElement.setAttribute('aria-label', inside
            ? 'Procedural bag interior cutaway. Lining and configured pockets. Drag or use the rotation buttons to inspect.'
            : 'Procedural bag exterior. Drag or use the rotation buttons to inspect.');
        if (!animate || this._reducedMotion || !this._shouldRun()) this._finishTransition();
        this.dispatchEvent({ type: 'viewchange', inside });
    }

    _insideDistance() {
        const vertical = THREE.MathUtils.degToRad(this.camera.fov);
        const horizontal = 2 * Math.atan(Math.tan(vertical / 2) * this.camera.aspect);
        return this._bodyRadius / Math.sin(Math.min(vertical, horizontal) / 2);
    }

    _insidePosition() {
        return new THREE.Vector3().setFromSphericalCoords(
            this._insideDistance(), 0.55, 0.25,
        ).add(this._insideTarget);
    }

    _setCutaway(enabled) {
        for (const material of this.bag.userData.cutawayMaterials) {
            material.clippingPlanes = enabled ? this._cutawayPlanes : null;
            material.clipShadows = enabled;
        }
    }

    _clearInertia() {
        const damping = this.controls.enableDamping;
        this.controls.enableDamping = false;
        this.controls.autoRotate = false;
        this.controls.update();
        this.controls.enableDamping = damping;
    }

    _advanceTransition(elapsed) {
        const transition = this._transition;
        if (!transition) return;
        transition.elapsed = Math.min(this._viewDuration, transition.elapsed + elapsed);
        const progress = transition.elapsed / this._viewDuration;
        const amount = progress === 1 ? 1 : viewEase(progress);
        if (progress === 1) {
            this.camera.position.copy(transition.toCamera);
            this.controls.target.copy(transition.toTarget);
        } else {
            this.camera.position.lerpVectors(transition.fromCamera, transition.toCamera, amount);
            this.controls.target.lerpVectors(transition.fromTarget, transition.toTarget, amount);
        }
        if (this.bag.userData.flap) {
            this.bag.userData.flap.rotation.x = THREE.MathUtils.lerp(
                transition.fromFlap, transition.toFlap, amount,
            );
        }
        if (progress === 1) {
            this._transitionDestination = {
                camera: transition.toCamera.clone(),
                target: transition.toTarget.clone(),
            };
            if (this.bag.userData.flap) this.bag.userData.flap.rotation.x = transition.toFlap;
            this._transition = null;
            this.controls.enabled = this._active;
            if (!this.isInside) {
                this._setCutaway(false);
                this._exteriorView = null;
            }
        }
    }

    _finishTransition() {
        if (!this._transition) return;
        // Finish with damping off so OrbitControls cannot add a residual pose
        // after the camera/target have reached their exact destinations.
        const damping = this.controls.enableDamping;
        this.controls.enableDamping = false;
        this._advanceTransition(this._viewDuration);
        this.controls.update();
        if (this._transitionDestination) {
            this.camera.position.copy(this._transitionDestination.camera);
            this.controls.target.copy(this._transitionDestination.target);
        }
        this.controls.enableDamping = damping;
    }

    /**
     * Orbit the camera by the given deltas (radians) around the target.
     * Used by keyboard controls in the UI; counts as user interaction.
     */
    rotate(horizontalRadians, verticalRadians) {
        if (this._disposed || this._contextLost) return;
        this._lastInteraction = performance.now();
        this._finishTransition();
        this._clearInertia();

        const offset = this._rotateOffset ??= new THREE.Vector3();
        const spherical = this._rotateSpherical ??= new THREE.Spherical();

        offset.copy(this.camera.position).sub(this.controls.target);
        spherical.setFromVector3(offset);
        spherical.theta -= horizontalRadians;
        spherical.phi = THREE.MathUtils.clamp(
            spherical.phi - verticalRadians,
            this.controls.minPolarAngle,
            this.controls.maxPolarAngle,
        );
        offset.setFromSpherical(spherical);
        this.camera.position.copy(this.controls.target).add(offset);
        this.camera.lookAt(this.controls.target);
    }

    /**
     * Master switch for idle auto-rotation. Auto-rotation still only starts
     * after the idle delay and never runs under prefers-reduced-motion.
     */
    setAutoRotate(enabled) {
        this._autoRotateEnabled = enabled === true;
    }

    /**
     * Suspend/resume rendering (hidden Photos view, closed dialog).
     * Page visibility is handled independently by the viewer.
     */
    setActive(active) {
        this._active = active === true;
        this.controls.enabled = this._active && !this._transition;
        if (this._active) this._lastInteraction = performance.now();
        this._updateLoopState();
    }

    /** Restore the current mode's framing; Reset inside does not exit inspection. */
    reset() {
        if (this._disposed || !this._initialCameraPosition) return;
        this._finishTransition();
        this._clearInertia();
        this.camera.position.copy(this.isInside ? this._insidePosition() : this._initialCameraPosition);
        this.controls.target.copy(this.isInside ? this._insideTarget : this._initialTarget);
        this._lastInteraction = performance.now();
        this.controls.update();
    }

    _resize() {
        if (this._disposed) return;
        const width = this.container.clientWidth;
        const height = this.container.clientHeight;
        if (width === 0 || height === 0) return; // hidden (e.g. closed dialog)
        this.renderer.setSize(width, height, false);
        const aspect = width / height;
        if (aspect !== this.camera.aspect && this._fitDistance && this.controls) {
            this._finishTransition();
            const verticalFov = THREE.MathUtils.degToRad(this.camera.fov);
            const horizontalFov = 2 * Math.atan(Math.tan(verticalFov / 2) * aspect);
            const distance = (this._frameRadius / Math.sin(Math.min(verticalFov, horizontalFov) / 2)) * 1.12;
            const scale = distance / this._fitDistance;
            // Preserve orbit/zoom while fitting the new inline or fullscreen shape.
            this.camera.position.sub(this.controls.target).multiplyScalar(scale).add(this.controls.target);
            this._initialCameraPosition.sub(this._initialTarget).multiplyScalar(scale).add(this._initialTarget);
            this._fitDistance = distance;
            if (this._exteriorView) {
                this._exteriorView.camera.sub(this._exteriorView.target)
                    .multiplyScalar(scale).add(this._exteriorView.target);
            }
            this.camera.far = distance * 20;
        }
        this.camera.aspect = aspect;
        this.camera.updateProjectionMatrix();
        if (this.isInside && this.controls) {
            this.controls.maxDistance = this._insideDistance() * 1.8;
        }
    }

    /** Release everything; idempotent, also wired to pagehide. */
    dispose() {
        if (this._disposed) return;
        this._disposed = true;

        this.renderer.setAnimationLoop(null);
        this._loopRunning = false;

        this._resizeObserver?.disconnect();
        this.controls.removeEventListener('start', this._onControlStart);
        this.controls.removeEventListener('end', this._onControlEnd);
        this.controls.dispose();
        document.removeEventListener('visibilitychange', this._onVisibility);
        this._motionQuery?.removeEventListener?.('change', this._onMotionChange);
        const canvas = this.renderer.domElement;
        canvas.removeEventListener('webglcontextlost', this._onContextLost);
        canvas.removeEventListener('webglcontextrestored', this._onContextRestored);
        window.removeEventListener('pagehide', this._onPageHide);

        this._disposeObject(this.scene);
        this.scene.clear();

        this.renderer.dispose();
        canvas.remove();
    }

    _disposeObject(root) {
        root.traverse((node) => {
            node.geometry?.dispose();
            const materials = Array.isArray(node.material)
                ? node.material
                : (node.material ? [node.material] : []);
            for (const material of materials) {
                for (const value of Object.values(material)) {
                    if (value && value.isTexture) value.dispose();
                }
                material.dispose();
            }
        });
    }
}
