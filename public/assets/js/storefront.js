/*
 * Habi storefront enhancements (progressive, no dependencies).
 * - Mobile menu: native <dialog id="mobile-menu">, opened by [data-menu-open];
 *   Esc closes natively, backdrop click and [data-menu-close] close it, and
 *   focus returns to the opener.
 * - Home story ([data-story]): with motion allowed, <html> gets
 *   .story-motion. [data-reveal] scenes fade/wipe in once; the sticky weave
 *   stage ([data-story-weave]) sets data-step from scroll progress; the
 *   wrapper takes the data-tone of the scene at mid-viewport (CSS fades the
 *   background); the opening sets --exit as it scrolls away; and one thread
 *   path is laid through the [data-thread] anchors and revealed with scroll.
 *   Without JS or with reduced motion everything renders in its final
 *   state, so motion never gates content.
 * - Catalog filters (form[data-filters]): auto-submit on change; the
 *   [data-filter-submit] button is hidden with JS and remains the no-JS
 *   native submit fallback.
 */
(function () {
  'use strict';

  /* --- Mobile menu ----------------------------------------------------- */

  var menu = document.getElementById('mobile-menu');
  var openers = document.querySelectorAll('[data-menu-open]');
  var lastOpener = null;

  function openMenu(opener) {
    if (!menu || typeof menu.showModal !== 'function') {
      return;
    }
    lastOpener = opener || null;
    if (!menu.open) {
      menu.showModal();
    }
    openers.forEach(function (button) {
      button.setAttribute('aria-expanded', 'true');
    });
  }

  function closeMenu() {
    if (!menu || !menu.open) {
      return;
    }
    menu.close();
    openers.forEach(function (button) {
      button.setAttribute('aria-expanded', 'false');
    });
    if (lastOpener && typeof lastOpener.focus === 'function') {
      lastOpener.focus();
    }
    lastOpener = null;
  }

  Array.prototype.forEach.call(openers, function (button) {
    button.hidden = !menu || typeof menu.showModal !== 'function';
    button.addEventListener('click', function () {
      if (menu && menu.open) {
        closeMenu();
      } else {
        openMenu(button);
      }
    });
  });

  if (menu) {
    menu.addEventListener('close', function () {
      openers.forEach(function (button) {
        button.setAttribute('aria-expanded', 'false');
      });
    });
    menu.addEventListener('click', function (event) {
      var bounds = menu.getBoundingClientRect();
      var outside = event.clientX < bounds.left || event.clientX > bounds.right ||
        event.clientY < bounds.top || event.clientY > bounds.bottom;
      if ((event.target === menu && outside) || event.target.closest('a[href]')) {
        closeMenu();
      }
    });
    menu.addEventListener('keydown', function (event) {
      if (event.key !== 'Tab') {
        return;
      }
      var controls = Array.prototype.slice.call(menu.querySelectorAll('button:not(:disabled), a[href]'));
      var first = controls[0];
      var last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    menu.addEventListener('cancel', function (event) {
      // Esc: let the browser close the dialog, then restore focus ourselves.
      event.preventDefault();
      closeMenu();
    });
    Array.prototype.forEach.call(menu.querySelectorAll('[data-menu-close]'), function (button) {
      button.addEventListener('click', closeMenu);
    });
  }

  /* --- Home story motion ------------------------------------------------ */

  var story = document.querySelector('[data-story]');
  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  var root = document.documentElement;

  if (story && !reducedMotion.matches && 'IntersectionObserver' in window) {
    var weave = story.querySelector('[data-story-weave]');
    var steps = weave ? weave.querySelectorAll('[data-weave-step]').length : 0;
    var opening = story.querySelector('.story-open');
    var scenes = Array.prototype.filter.call(story.children, function (child) {
      return child.hasAttribute('data-tone');
    });
    var thread = story.querySelector('[data-story-thread]');
    var threadPath = thread ? thread.querySelector('path') : null;
    var anchors = story.querySelectorAll('[data-thread]');
    var threadHeight = 0;

    var revealObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-in');
          revealObserver.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -12% 0px', threshold: 0.15 });
    Array.prototype.forEach.call(story.querySelectorAll('[data-reveal]'), function (element) {
      revealObserver.observe(element);
    });

    // x of the gutter between a two-column element's first two items, or
    // null when they are stacked (narrow layouts).
    function gutterX(element) {
      var items = Array.prototype.map.call(element.children, function (child) {
        return child.getBoundingClientRect();
      }).sort(function (a, b) { return a.left - b.left; });
      if (items.length < 2 || items[1].left < items[0].right - 1) {
        return null;
      }
      return (items[0].right + items[1].left) / 2;
    }

    // Lays one smooth path through every anchor, in document order. Wide
    // layouts weave through gutters; narrow ones keep a lane at the edge,
    // except through the loom, where the thread is the lead warp.
    function layoutThread() {
      if (!threadPath) {
        return;
      }
      var box = story.getBoundingClientRect();
      var width = story.clientWidth;
      var lane = 8;
      var view = window.innerHeight;
      var points = [];
      Array.prototype.forEach.call(anchors, function (anchor) {
        var rect = anchor.getBoundingClientRect();
        var top = rect.top - box.top;
        var middle = top + rect.height / 2;
        var kind = anchor.getAttribute('data-thread');
        if (kind === 'start') {
          points.push([rect.left - box.left + rect.width / 2, top + rect.height]);
        } else if (kind === 'feed') {
          var loom = anchor.querySelector('.story-loom').getBoundingClientRect();
          var x = loom.left - box.left + loom.width / 2;
          points.push([x, top + view * 0.1], [x, top + rect.height - view * 0.1]);
        } else if (kind === 'center') {
          points.push([width >= 768 ? rect.left - box.left + rect.width / 2 : lane, middle]);
        } else if (kind === 'gap') {
          var gutter = gutterX(anchor);
          points.push([gutter === null ? lane : gutter - box.left, middle]);
        } else if (kind === 'end') {
          points.push([width >= 768 ? width + 24 : lane, middle]);
        }
      });
      if (points.length < 2) {
        return;
      }
      var d = 'M' + points[0][0] + ' ' + points[0][1];
      for (var i = 1; i < points.length; i++) {
        var from = points[i - 1];
        var to = points[i];
        var bend = (to[1] - from[1]) / 2;
        d += ' C' + from[0] + ' ' + (from[1] + bend) + ' ' + to[0] + ' ' + (to[1] - bend) +
          ' ' + to[0] + ' ' + to[1];
      }
      threadHeight = box.height;
      thread.setAttribute('viewBox', '0 0 ' + width + ' ' + threadHeight);
      threadPath.setAttribute('d', d);
    }

    var ticking = false;
    function update() {
      ticking = false;
      var view = window.innerHeight;

      if (steps) {
        var rect = weave.getBoundingClientRect();
        var travel = rect.height - view;
        var progress = travel > 0 ? Math.min(1, Math.max(0, -rect.top / travel)) : 1;
        var step = String(Math.min(steps - 1, Math.floor(progress * steps)));
        if (weave.getAttribute('data-step') !== step) {
          weave.setAttribute('data-step', step);
        }
      }

      for (var i = 0; i < scenes.length; i++) {
        var bounds = scenes[i].getBoundingClientRect();
        if (bounds.top <= view / 2 && bounds.bottom > view / 2) {
          var tone = scenes[i].getAttribute('data-tone');
          if (story.getAttribute('data-tone') !== tone) {
            story.setAttribute('data-tone', tone);
          }
          break;
        }
      }

      if (opening) {
        var open = opening.getBoundingClientRect();
        opening.style.setProperty('--exit', Math.min(1, Math.max(0, -open.top / open.height)).toFixed(3));
      }

      if (threadHeight) {
        var drawn = view * 0.75 - story.getBoundingClientRect().top;
        thread.style.clipPath = 'inset(0 0 ' + Math.max(0, threadHeight - drawn) + 'px 0)';
      }
    }
    function requestUpdate() {
      if (!ticking) {
        ticking = true;
        window.requestAnimationFrame(update);
      }
    }
    function relayout() {
      layoutThread();
      requestUpdate();
    }

    root.classList.add('story-motion');
    relayout();
    window.addEventListener('scroll', requestUpdate, { passive: true });
    window.addEventListener('resize', relayout);
    window.addEventListener('load', relayout);
    var resizeObserver = 'ResizeObserver' in window ? new ResizeObserver(relayout) : null;
    if (resizeObserver) {
      resizeObserver.observe(story);
    }

    // A mid-visit switch to reduced motion shows every final state at once.
    reducedMotion.addEventListener('change', function (event) {
      if (event.matches) {
        root.classList.remove('story-motion');
        revealObserver.disconnect();
        if (resizeObserver) {
          resizeObserver.disconnect();
        }
        window.removeEventListener('scroll', requestUpdate);
        window.removeEventListener('resize', relayout);
        window.removeEventListener('load', relayout);
        if (weave) {
          weave.setAttribute('data-step', String(steps - 1));
        }
      }
    });
  }

  /* --- Catalog filters: progressive auto-submit ------------------------- */

  var filters = document.querySelector('form[data-filters]');
  if (filters) {
    var submitButton = filters.querySelector('[data-filter-submit]');
    if (submitButton) {
      submitButton.style.display = 'none'; // JS present: auto-submit instead
    }
    filters.addEventListener('change', function (event) {
      if (event.target.matches('select, input[type="checkbox"], input[type="search"]')) {
        filters.submit();
      }
    });
  }
})();
