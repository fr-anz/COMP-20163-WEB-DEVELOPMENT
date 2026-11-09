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
  const productName =
    page.querySelector('.product-detail__name')?.textContent.trim() || 'Product';

  const els = {
    modeButtons: Array.from(page.querySelectorAll('[data-mode]')),
    photosPanel: page.querySelector('[data-photos-panel]'),
    viewer: page.querySelector('[data-viewer]'),
    inlineSlot: page.querySelector('[data-viewer-inline-slot]'),
    container: page.querySelector('[data-viewer-container]'),
    status: page.querySelector('[data-viewer-status]'),
    controls: page.querySelector('[data-viewer-controls]'),
    note: page.querySelector('[data-viewer-note]'),
    hint: page.querySelector('[data-viewer-hint]'),
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
    els.pauseButton.textContent = reduced
      ? 'Auto-rotation off'
      : (state.autoRotate ? 'Pause rotation' : 'Resume rotation');
    els.pauseButton.disabled = reduced;
    els.hint.textContent = reduced
      ? 'Drag the bag or use the rotation buttons. Automatic motion is off.'
      : 'Drag the bag or use the rotation buttons. Idle rotation can be paused.';
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
    // Retry removes its own status button; move focus before detaching it.
    if (els.status.contains(document.activeElement)) {
      const target = els.dialog.open
        ? els.dialogClose
        : els.modeButtons.find((button) => button.dataset.mode === '3d');
      target.focus();
    }
    els.status.replaceChildren();

    if (state.viewerState === 'loading') {
      els.status.textContent = 'Loading the 3D view…';
      els.controls.hidden = true;
      els.hint.hidden = true;
      setNote('');
      return;
    }

    if (state.viewerState === 'ready') {
      els.status.textContent = '3D exterior ready.';
      els.controls.hidden = false;
      els.hint.hidden = false;
      updatePauseButton();
      return;
    }

    if (state.viewerState === 'webgl-unavailable') {
      showWebglFallback();
      // Exact required note (R27).
      els.status.textContent = '3D unavailable in this browser';
      els.controls.hidden = true;
      els.hint.hidden = true;
      setNote('');
      return;
    }

    // Fetch or module failure: explain and offer retry.
    els.status.textContent = 'We couldn’t load the 3D view right now. ';
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'viewer__retry';
    retry.textContent = 'Try again';
    retry.addEventListener('click', () => {
      if (state.moduleFailed) window.location.reload();
      else loadViewer();
    });
    els.status.appendChild(retry);
    els.controls.hidden = true;
    els.hint.hidden = true;
    setNote('');
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
      button.setAttribute('aria-pressed', String(button.dataset.mode === mode));
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

  els.controls.addEventListener('click', (event) => {
    const button = event.target.closest('button');
    if (!button || !state.viewer) return;
    if (button.hasAttribute('data-h') || button.hasAttribute('data-v')) {
      state.viewer.rotate(
        parseFloat(button.dataset.h || '0'),
        parseFloat(button.dataset.v || '0')
      );
    } else if (button === els.resetButton) {
      state.viewer.reset();
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

  // Gallery: works without JS (main image only); thumbs appear when JS runs.
  if (els.thumbs && els.mainImage) {
    els.thumbs.hidden = false;
    els.thumbs.addEventListener('click', (event) => {
      const button = event.target.closest('[data-gallery-thumb]');
      if (!button) return;
      els.mainImage.src = button.dataset.src;
      if (button.dataset.alt) els.mainImage.alt = button.dataset.alt;
      for (const other of els.thumbs.querySelectorAll('[data-gallery-thumb]')) {
        other.setAttribute('aria-pressed', String(other === button));
      }
    });
  }

  // Immediate response to state changes.
  const onViewportChange = () => {
    if (state.mode === '3d') {
      setMode('3d'); // re-place inline vs dialog for the new viewport
    } else {
      els.inlineSlot.appendChild(els.viewer);
    }
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

  page.querySelector('.view-toggle').hidden = false;
  setMode('photos');
  // Start fetching config + importing the viewer module promptly (N1),
  // even while the Photos view is the one on screen.
  loadViewer();
})();
