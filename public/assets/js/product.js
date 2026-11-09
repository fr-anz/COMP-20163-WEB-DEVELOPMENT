/* Habi product detail page glue (M2).
 *
 * Owns: gallery thumbnail switching, Photos/3D mode switching, BagViewer
 * lifecycle (fetch config + dynamic import, retry, WebGL fallback), the
 * mobile fullscreen dialog, and accessible orbit controls.
 *
 * Out of scope: any cart behavior. Purchase controls stay disabled until M4.
 */
(() => {
  'use strict';

  // Must match the desktop breakpoint in app.css (900px = 56.25em).
  const WIDE_QUERY = '(min-width: 56.25em)';
  const REDUCED_QUERY = '(prefers-reduced-motion: reduce)';

  const page = document.querySelector('[data-product-page]');
  if (!page) return;

  const slug = page.dataset.productSlug;
  const fallbackImage = page.dataset.fallbackImage || '';
  const productName = page.querySelector('h1')?.textContent.trim() || 'Product';

  const els = {
    modeButtons: Array.from(page.querySelectorAll('[data-mode]')),
    photosPanel: page.querySelector('[data-photos-panel]'),
    viewer: page.querySelector('[data-viewer]'),
    inlineSlot: page.querySelector('[data-viewer-inline-slot]'),
    container: page.querySelector('[data-viewer-container]'),
    status: page.querySelector('[data-viewer-status]'),
    controls: page.querySelector('[data-viewer-controls]'),
    note: page.querySelector('[data-viewer-note]'),
    loadingOverlay: page.querySelector('[data-viewer-loading]'),
    errorOverlay: page.querySelector('[data-viewer-error]'),
    nowebglOverlay: page.querySelector('[data-viewer-nowebgl]'),
    retryButton: page.querySelector('[data-viewer-retry]'),
    imagesButton: page.querySelector('[data-viewer-images]'),
    iconPause: page.querySelector('[data-viewer-icon-pause]'),
    iconPlay: page.querySelector('[data-viewer-icon-play]'),
    expandIndicator: page.querySelector('[data-viewer-expand]'),
    belowHint: page.querySelector('[data-viewer-below-hint]'),
    stage: page.querySelector('[data-viewer-stage]'),
    pauseButton: page.querySelector('[data-viewer-pause]'),
    resetButton: page.querySelector('[data-viewer-reset]'),
    dialog: document.querySelector('[data-viewer-dialog]'),
    dialogSlot: document.querySelector('[data-viewer-dialog-slot]'),
    dialogClose: document.querySelector('[data-viewer-close]'),
    thumbs: page.querySelector('[data-gallery-thumbs]'),
    mainImage: page.querySelector('[data-gallery-image]'),
  };

  const wideQuery = window.matchMedia(WIDE_QUERY);
  const reducedMotionQuery = window.matchMedia(REDUCED_QUERY);

  const state = {
    mode: 'photos',
    viewerState: 'loading', // loading | ready | error | webgl-unavailable
    viewer: null,
    autoRotate: true,
    configPromise: null,
    modulePromise: null,
    moduleFailed: false,
  };

  const isWide = () => wideQuery.matches;

  /* ---------------------------------------------------------------- View */

  function setNote(text) {
    els.note.hidden = text === '';
    els.note.textContent = text;
  }

  function updatePauseButton() {
    if (!els.pauseButton) return;
    const reduced = reducedMotionQuery.matches;
    const running = state.autoRotate && !reduced;
    const label = reduced ? 'Automatic rotation off (reduced motion)' :
      (running ? 'Pause automatic rotation' : 'Start automatic rotation');
    els.pauseButton.setAttribute('aria-pressed', String(running));
    els.pauseButton.setAttribute('aria-label', label);
    els.pauseButton.title = label;
    if (els.iconPause) els.iconPause.hidden = !running;
    if (els.iconPlay) els.iconPlay.hidden = running;
    els.pauseButton.disabled = reduced;
    setNote(reduced ? 'Auto-rotate is off because your system prefers reduced motion.' : '');
  }

  function showWebglFallback() {
    els.container.replaceChildren();
    if (fallbackImage) {
      const img = document.createElement('img');
      img.src = fallbackImage;
      img.alt = productName + ' — illustrative demo illustration';
      img.className = 'viewer__fallback-img';
      els.container.appendChild(img);
    }
  }

  function render3dState() {
    // Hiding the retry button must not strand focus inside a hidden state;
    // move focus to the 3D mode button (or dialog close) before swapping.
    if (els.errorOverlay?.contains(document.activeElement)) {
      const target = els.dialog.open
        ? els.dialogClose
        : els.modeButtons.find((button) => button.dataset.mode === '3d');
      target?.focus();
    }
    els.status.replaceChildren();

    const loading = state.viewerState === 'loading';
    const failed = state.viewerState === 'error';
    const noWebgl = state.viewerState === 'webgl-unavailable';
    if (els.loadingOverlay) els.loadingOverlay.hidden = !loading;
    if (els.errorOverlay) els.errorOverlay.hidden = !failed;
    if (els.nowebglOverlay) els.nowebglOverlay.hidden = !noWebgl;
    els.controls.hidden = state.viewerState !== 'ready';

    if (loading) {
      els.status.textContent = 'Loading 3D view';
      return;
    }

    if (state.viewerState === 'ready') {
      els.status.textContent = '3D exterior ready.';
      updatePauseButton();
      return;
    }

    if (noWebgl) {
      showWebglFallback();
      // Exact required note (R27).
      els.status.textContent = '3D unavailable in this browser';
      return;
    }

    // Fetch or module failure: the error overlay explains and offers retry.
    els.status.textContent = '';
  }

  function loadViewer() {
    state.viewerState = 'loading';
    render3dState();
    state.moduleFailed = false;
    let timer;
    const deadline = new Promise((_, reject) => {
      timer = window.setTimeout(() => reject(new Error('Viewer loading timed out')), 10000);
    });

    const configPromise = fetch(
      '/api/product/' + encodeURIComponent(slug) + '/3d',
      { cache: 'no-store', signal: AbortSignal.timeout(10000) }
    ).then(async (response) => {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    });

    if (!state.modulePromise) {
      state.modulePromise = import('/assets/js/viewer3d.js').catch((error) => {
        state.moduleFailed = true;
        throw error;
      });
    }

    state.configPromise = configPromise;
    const attempt = state.configPromise;

    Promise.race([Promise.all([configPromise, state.modulePromise]), deadline])
      .then(([config, module]) => {
        if (attempt !== state.configPromise) return; // superseded by a retry
        state.viewer = new module.BagViewer(els.container, config);
        state.viewer.setAutoRotate(state.autoRotate);
        state.viewerState = 'ready';
        // Coordinator measures viewer readiness from navigation start (N1).
        if (typeof window.performance.mark === 'function') {
          window.performance.mark('habi-viewer-ready');
        }
        render3dState();
        syncActivity();
      })
      .catch((error) => {
        if (attempt !== state.configPromise) return;
        if (error && error.name === 'WebGLUnavailableError') {
          state.viewerState = 'webgl-unavailable';
        } else {
          state.viewerState = 'error';
          // Allow a module import retry on the next attempt.
          state.modulePromise = null;
        }
        render3dState();
        syncActivity();
      }).finally(() => window.clearTimeout(timer));
  }

  /* ------------------------------------------------------ Mode switching */

  function syncActivity() {
    if (!state.viewer) return;
    const visible =
      state.mode === '3d' &&
      (isWide() || els.dialog.open) &&
      !document.hidden;
    state.viewer.setActive(visible);
  }

  function setMode(mode) {
    state.mode = mode;
    const threeD = mode === '3d';

    for (const button of els.modeButtons) {
      const selected = button.dataset.mode === mode;
      button.setAttribute('aria-pressed', String(selected));
      for (const token of ['bg-ink', 'text-inverse']) button.classList.toggle(token, selected);
      for (const token of ['text-ink', 'hover:bg-inset']) button.classList.toggle(token, !selected);
    }
    els.photosPanel.hidden = threeD;
    els.viewer.hidden = !threeD;

    if (threeD && !isWide()) {
      // One viewer container reparented into the dialog — never a second renderer.
      els.dialogSlot.appendChild(els.viewer);
      if (!els.dialog.open) els.dialog.showModal();
    } else {
      if (els.dialog.open) els.dialog.close();
      els.inlineSlot.appendChild(els.viewer);
    }
    syncActivity();
  }

  /* ------------------------------------------------------------- Wiring */

  for (const button of els.modeButtons) {
    button.addEventListener('click', () => setMode(button.dataset.mode));
  }

  if (els.dialogClose) {
    els.dialogClose.addEventListener('click', () => els.dialog.close());
  }

  // Native Esc handling; explicitly wrap Tab at the dialog's control boundaries.
  els.dialog.addEventListener('keydown', (event) => {
    if (event.key !== 'Tab') return;
    const buttons = Array.from(els.dialog.querySelectorAll('button:not(:disabled)'))
      .filter((button) => button.getClientRects().length > 0);
    const first = buttons[0];
    const last = buttons[buttons.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
  els.dialog.addEventListener('close', () => {
    if (state.mode === '3d' && !isWide()) setMode('photos');
    if (!isWide()) {
      const threeDButton = els.modeButtons.find((b) => b.dataset.mode === '3d');
      if (threeDButton) threeDButton.focus(); // focus return
    }
    syncActivity();
  });

  // Orientation announcer: the sr-only live region is the reference's
  // orientation label (no visible readout in the source overlay).
  const announce = (text) => {
    els.status.textContent = text;
  };

  els.controls.addEventListener('click', (event) => {
    const button = event.target.closest('button');
    if (!button || !state.viewer) return;
    if (button.hasAttribute('data-viewer-rotate')) {
      const yaw = parseFloat(button.dataset.h || '0');
      state.viewer.rotate(yaw, parseFloat(button.dataset.v || '0'));
      announce(yaw < 0 ? 'Rotated left' : 'Rotated right');
    } else if (button === els.resetButton) {
      state.viewer.reset();
      announce('Orientation reset');
    } else if (button === els.pauseButton) {
      if (state.autoRotate) {
        state.viewer.setAutoRotate(false);
        state.autoRotate = false;
      } else {
        if (reducedMotionQuery.matches) return; // control is disabled anyway
        state.viewer.setAutoRotate(true);
        state.autoRotate = true;
      }
      updatePauseButton();
    }
  });

  // Static retry in the error overlay (same retry semantics as before).
  els.retryButton?.addEventListener('click', () => {
    if (state.moduleFailed) window.location.reload();
    else loadViewer();
  });

  // The nowebgl overlay's escape hatch back to the photo views.
  els.imagesButton?.addEventListener('click', () => setMode('photos'));

  // Arrow keys rotate/tilt from the focused stage, as the reference viewer does.
  const KEY_STEPS = {
    ArrowLeft: [-0.523599, 0, 'Rotated left'],
    ArrowRight: [0.523599, 0, 'Rotated right'],
    ArrowUp: [0, 0.261799, 'Tilted up'],
    ArrowDown: [0, -0.261799, 'Tilted down'],
  };
  els.stage?.addEventListener('keydown', (event) => {
    const step = KEY_STEPS[event.key];
    if (!step || !state.viewer) return;
    event.preventDefault();
    state.viewer.rotate(step[0], step[1]);
    announce(step[2]);
  });

  // Gallery: works without JS (main image only); thumbs appear when JS runs.
  if (els.thumbs && els.mainImage) {
    els.thumbs.hidden = false;
    els.thumbs.addEventListener('click', (event) => {
      const button = event.target.closest('[data-gallery-thumb]');
      if (!button) return;
      els.mainImage.src = button.dataset.src;
      if (button.dataset.alt) els.mainImage.alt = button.dataset.alt;
      for (const other of els.thumbs.querySelectorAll('[data-gallery-thumb]')) {
        const selected = other === button;
        other.setAttribute('aria-pressed', String(selected));
        other.classList.toggle('border-ink', selected);
        for (const token of ['border-transparent', 'opacity-80', 'hover:opacity-100']) {
          other.classList.toggle(token, !selected);
        }
      }
    });
  }

  // Below 900px the reference shows the fullscreen affordance: an expand
  // glyph on the 3D toggle and the "opens fullscreen" hint under the stage.
  const syncBelowWide = () => {
    if (els.expandIndicator) els.expandIndicator.hidden = isWide();
    if (els.belowHint) els.belowHint.hidden = isWide();
  };

  // Immediate response to state changes.
  const onViewportChange = () => {
    if (state.mode === '3d') {
      setMode('3d'); // re-place inline vs dialog for the new viewport
    } else {
      els.inlineSlot.appendChild(els.viewer);
    }
    syncBelowWide();
    syncActivity();
  };
  if (wideQuery.addEventListener) {
    wideQuery.addEventListener('change', onViewportChange);
  } else {
    wideQuery.addListener(onViewportChange);
  }

  const onReducedMotionChange = () => {
    if (state.viewerState === 'ready') updatePauseButton();
  };
  if (reducedMotionQuery.addEventListener) {
    reducedMotionQuery.addEventListener('change', onReducedMotionChange);
  } else {
    reducedMotionQuery.addListener(onReducedMotionChange);
  }

  document.addEventListener('visibilitychange', syncActivity);

  // A bfcache restore reuses this document after pagehide disposed its GPU state.
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) loadViewer();
  });

  window.addEventListener(
    'pagehide',
    () => {
      state.configPromise = null; // Ignore any load still pending during navigation.
      state.viewer?.dispose();
      state.viewer = null;
    }
  );

  /* ---------------------------------------------------------------- Boot */

  page.querySelector('[data-view-toggle]').hidden = false;
  syncBelowWide();
  setMode('photos');
  // Start fetching config + importing the viewer module promptly (N1),
  // even while the Photos view is the one on screen.
  loadViewer();
})();
