/*
 * Habi cart enhancements (M4, progressive, no dependencies).
 *
 * - Add/update/remove forms post natively (303 back to /cart); here they are
 *   intercepted to fetch JSON {cart, drawer_html, page_html, message,
 *   csrf_token}, and both rendered surfaces plus the header badge are replaced
 *   from the server response, so counts, totals and stock clamps are always
 *   authoritative. With JavaScript off, the native posts (including the
 *   functional stepper buttons), the region estimate reload and the /cart
 *   page keep working; the drawer is simply unreachable.
 * - Mutations are strictly serialized: while one is in flight every cart edit
 *   control is disabled and the request body is captured up front, so rapid
 *   clicks or edits can never race, double-add or silently drop a change.
 *   A dropped connection is NEVER replayed — the bag is re-fetched (GET is a
 *   safe read) and a persistent error explains that the outcome was uncertain.
 * - The bag control ([data-cart-bag]) stays a native /cart link; with JS it
 *   opens the native <dialog id="cart-drawer"> filled with fresh server HTML
 *   (fetched on every open and on page restoration); Tab/Shift+Tab are wrapped
 *   explicitly, Esc/backdrop/× close it, and focus returns to the opener (or
 *   the visible header bag when opened from the mobile menu).
 * - The selected shipping region is kept across re-renders and applied to
 *   both surfaces (R12 rates; free strictly above ₱5,000).
 */
(function () {
  'use strict';

  var drawer = document.getElementById('cart-drawer');
  var toasts = document.querySelector('[data-cart-toasts]');
  var pageContent = document.querySelector('[data-cart-page-content]');
  var lastOpener = null;
  var busy = false;
  var selectedRegion = '';

  var RATES = { metro_manila: 12000, luzon: 18000, visayas: 22000, mindanao: 25000 };
  var FREE_ABOVE = 500000;
  var LOWEST_RATE = RATES.metro_manila;

  var EDIT_CONTROLS = '[data-cart-step], input[data-cart-qty], ' +
    'form[data-cart-add] button[type="submit"], form[data-cart-update] button[type="submit"], ' +
    'form[data-cart-remove] button[type="submit"]';

  function eachNodeList(list, callback) {
    Array.prototype.forEach.call(list, callback);
  }

  function money(centavos) {
    var cent = Math.abs(centavos) % 100;
    return (centavos / 100).toLocaleString('en-US', {
      minimumFractionDigits: cent === 0 ? 0 : 2,
      maximumFractionDigits: 2
    });
  }

  /* --- Toasts ------------------------------------------------------------- */

  function iconSvg(paths, size, className) {
    return '<svg class="' + className + '" width="' + size + '" height="' + size +
      '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
      paths + '</svg>';
  }

  var ICON_CHECK = '<path d="m5 12.5 4.5 4.5L19 7.5"/>';
  var ICON_ALERT = '<path d="M12 3.5 21.5 20h-19L12 3.5Z"/><path d="M12 10v4.5M12 17.5v.01"/>';

  function showToast(title, body, isError, persistent) {
    if (!toasts) {
      return;
    }
    var toast = document.createElement('div');
    toast.className = 'anim-fade-up flex items-start gap-3 rounded-control bg-brown-900 py-3 pr-1.5 pl-4 text-cream-50 shadow-popover';
    var text = '<div class="flex-1 py-0.5"><p class="text-[15px] font-semibold"></p>';
    if (body) {
      text += '<p class="text-small text-cream-50/80"></p>';
    }
    text += '</div>';
    toast.innerHTML = iconSvg(isError ? ICON_ALERT : ICON_CHECK, 20, 'mt-0.5 flex-none text-yellow-400') + text;
    var paragraphs = toast.querySelectorAll('p');
    paragraphs[0].textContent = title;
    if (body) {
      paragraphs[1].textContent = body;
    }
    toasts.appendChild(toast);
    while (toasts.children.length > 3) {
      toasts.firstChild.remove();
    }
    if (!persistent) {
      window.setTimeout(function () {
        toast.remove();
      }, 6000);
    }
  }

  /* --- Badge, CSRF and summaries sync ---------------------------------------- */

  function updateBadge(count) {
    var badge = document.querySelector('[data-cart-count]');
    if (badge) {
      badge.textContent = String(count);
      badge.hidden = count === 0;
    }
    eachNodeList(document.querySelectorAll('[data-cart-bag]'), function (link) {
      var label = count === 0 ? 'Bag, empty' : 'Bag, ' + count + (count === 1 ? ' item' : ' items');
      link.setAttribute('aria-label', label);
      link.setAttribute('title', label);
    });
  }

  function updateTokens(token) {
    eachNodeList(document.querySelectorAll('input[name="csrf_token"]'), function (input) {
      input.value = token;
    });
  }

  function shippingFor(subtotal) {
    if (subtotal > FREE_ABOVE) {
      return { label: 'Free', centavos: 0 };
    }
    if (RATES[selectedRegion]) {
      return { label: '₱' + money(RATES[selectedRegion]), centavos: RATES[selectedRegion] };
    }
    return { label: 'From ₱' + money(LOWEST_RATE), centavos: LOWEST_RATE };
  }

  /* Keeps the page Summary and the drawer footer on one estimate. */
  function syncSummaries(subtotal) {
    var shipping = shippingFor(subtotal);
    eachNodeList(document.querySelectorAll('[data-cart-summary][data-cart-subtotal]'), function (panel) {
      var shippingRow = panel.querySelector('[data-cart-shipping]');
      var totalRow = panel.querySelector('[data-cart-total]');
      if (shippingRow) {
        shippingRow.textContent = shipping.label;
      }
      if (totalRow) {
        totalRow.textContent = '₱' + money(subtotal + shipping.centavos);
      }
    });
    var select = document.querySelector('[data-cart-region]');
    if (select && select.value !== selectedRegion) {
      select.value = selectedRegion;
    }
  }

  /* --- Focus retention across re-renders --------------------------------------- */

  function controlKind(element) {
    if (element.matches('input[data-cart-qty]')) {
      return 'input';
    }
    var step = element.closest('[data-cart-step]');
    if (step) {
      return step.getAttribute('data-cart-step') === '-1' ? 'minus' : 'plus';
    }
    if (element.closest('form[data-cart-remove]')) {
      return 'remove';
    }
    return 'input';
  }

  function captureFocus(scope) {
    var active = document.activeElement;
    if (!active || active === document.body) {
      return null;
    }
    var line = active.closest('[data-cart-line]');
    if (!line || !scope.contains(line)) {
      return null;
    }
    var lines = Array.prototype.slice.call(scope.querySelectorAll('[data-cart-line]'));
    return {
      productId: line.getAttribute('data-product-id'),
      kind: controlKind(active),
      index: lines.indexOf(line)
    };
  }

  function focusControl(line, kind) {
    var target = null;
    if (kind === 'remove') {
      target = line.querySelector('form[data-cart-remove] button[type="submit"]');
    } else if (kind === 'minus' || kind === 'plus') {
      target = line.querySelector('[data-cart-step="' + (kind === 'minus' ? '-1' : '1') + '"]');
    }
    if (!target || target.disabled) {
      target = line.querySelector('input[data-cart-qty]');
    }
    if (target) {
      target.focus();
      return true;
    }
    return false;
  }

  function restoreFocus(before, scope) {
    if (!before) {
      return false;
    }
    var line = scope.querySelector('[data-cart-line][data-product-id="' + before.productId + '"]');
    if (line && focusControl(line, before.kind)) {
      return true;
    }
    // The line is gone (removed or sold out): move to a sensible neighbour.
    var lines = Array.prototype.slice.call(scope.querySelectorAll('[data-cart-line]'));
    var next = lines[Math.min(before.index, lines.length - 1)];
    if (next && focusControl(next, 'input')) {
      return true;
    }
    var heading = scope.querySelector('#cart-drawer-title') ||
      document.querySelector('main h1[tabindex="-1"]');
    if (heading) {
      heading.focus();
      return true;
    }
    return false;
  }

  /* --- Server payload application -------------------------------------------------- */

  function applyPayload(data) {
    if (!data || !data.cart) {
      return;
    }
    if (data.csrf_token) {
      updateTokens(data.csrf_token);
    }
    updateBadge(data.cart.count);
    if (drawer && typeof data.drawer_html === 'string') {
      var hadFocusInside = drawer.open && drawer.contains(document.activeElement);
      var drawerFocus = drawer.open ? captureFocus(drawer) : null;
      drawer.innerHTML = data.drawer_html;
      eachNodeList(drawer.querySelectorAll('[data-cart-step]'), function (button) {
        button.hidden = false;
      });
      if (!restoreFocus(drawerFocus, drawer) && hadFocusInside &&
          !drawer.contains(document.activeElement)) {
        // Focus sat on the Close button or footer; put it back inside.
        var close = drawer.querySelector('[data-cart-close]');
        if (close) {
          close.focus();
        }
      }
    }
    if (pageContent && typeof data.page_html === 'string') {
      var hadPageFocus = pageContent.contains(document.activeElement);
      var pageFocus = captureFocus(pageContent);
      pageContent.innerHTML = data.page_html;
      eachNodeList(pageContent.querySelectorAll('[data-cart-step]'), function (button) {
        button.hidden = false;
      });
      eachNodeList(pageContent.querySelectorAll('[data-cart-region-submit]'), function (button) {
        button.hidden = true;
      });
      if (!restoreFocus(pageFocus, pageContent) && hadPageFocus &&
          !pageContent.contains(document.activeElement)) {
        var region = pageContent.querySelector('[data-cart-region]');
        var firstInput = pageContent.querySelector('input[data-cart-qty]');
        if (region) {
          region.focus();
        } else if (firstInput) {
          firstInput.focus();
        }
      }
    }
    syncSummaries(data.cart.subtotal_centavos);
  }

  /* --- Serialized mutations ------------------------------------------------------------ */

  function postForm(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: body,
      credentials: 'same-origin'
    }).then(function (response) {
      return response.json().then(function (data) {
        return { ok: response.ok, data: data };
      }, function () {
        return { failed: true };
      });
    }, function () {
      return { failed: true };
    });
  }

  function setDisabled(controls, value) {
    controls.forEach(function (control) {
      control.disabled = value;
    });
  }

  function enqueue(kind, form, body) {
    if (busy) {
      return Promise.resolve(); // Controls are disabled; this cannot happen.
    }
    busy = true;
    // Disabling the focused button blurs it before the response arrives.
    // Capture intent before locking, and preserve pre-existing stock limits.
    var focused = document.activeElement;
    var drawerFocus = drawer && drawer.open ? captureFocus(drawer) : null;
    var pageFocus = pageContent ? captureFocus(pageContent) : null;
    var controls = Array.prototype.slice.call(document.querySelectorAll(EDIT_CONTROLS));
    var disabledStates = controls.map(function (control) { return control.disabled; });
    function unlock() {
      controls.forEach(function (control, index) {
        if (control.isConnected) {
          control.disabled = disabledStates[index];
        }
      });
      busy = false;
      if (document.activeElement !== document.body) {
        return; // The user moved focus while waiting; do not steal it.
      }
      if (drawer && drawer.open && restoreFocus(drawerFocus, drawer)) {
        return;
      }
      if (pageContent && restoreFocus(pageFocus, pageContent)) {
        return;
      }
      if (focused && focused.isConnected && !focused.disabled) {
        focused.focus();
      }
    }
    setDisabled(controls, true);
    return postForm(form.getAttribute('action'), body).then(function (result) {
      if (result.failed) {
        // Uncertain outcome: NEVER replay the POST. Re-read the bag (a safe
        // GET) and explain; a manual retry is the user's choice.
        return fetchCart().then(function (data) {
          applyPayload(data);
          unlock();
          showToast('We couldn\u2019t confirm that change',
            data
              ? 'The response was lost and nothing was retried. Your bag now shows the current state — check it before trying again.'
              : 'The response was lost and nothing was retried. We could not refresh your bag; reconnect and open it before trying again.',
            true, true);
        });
      }
      applyPayload(result.data);
      unlock();
      if (!result.ok || !result.data || result.data.error) {
        showToast('Your bag was not updated',
          (result.data && result.data.error) || 'Please check your connection and try again.',
          true, true);
        return;
      }
      if (result.data.message) {
        // The server message is the truth, including stock-cap corrections
        // ("Only N of X in stock — your bag now holds all of them.").
        showToast(result.data.message, '', false);
      }
    });
  }

  /* --- Quantity steppers ------------------------------------------------------------------ */

  function syncStepper(input) {
    var box = input.parentElement;
    var stock = parseInt(input.getAttribute('data-stock'), 10) || 1;
    var value = parseInt(input.value, 10) || 1;
    var minus = box.querySelector('[data-cart-step="-1"]');
    var plus = box.querySelector('[data-cart-step="1"]');
    if (minus) {
      minus.disabled = value <= 1;
    }
    if (plus) {
      plus.disabled = value >= stock;
    }
  }

  function clampQuantity(input) {
    var stock = parseInt(input.getAttribute('data-stock'), 10) || 1;
    var value = parseInt(input.value, 10);
    if (isNaN(value) || value < 1) {
      value = 1;
    }
    input.value = String(Math.min(stock, value));
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-cart-step]');
    if (!button || button.disabled) {
      return;
    }
    var input = button.parentElement.querySelector('input[data-cart-qty]');
    if (!input) {
      return;
    }
    var value = parseInt(input.value, 10) || 1;
    var stock = parseInt(input.getAttribute('data-stock'), 10) || 1;
    var next = Math.min(stock, Math.max(1, value + (button.getAttribute('data-cart-step') === '-1' ? -1 : 1)));
    if (next === value) {
      return;
    }
    input.value = String(next);
    var form = input.closest('form[data-cart-update]');
    if (form) {
      // A boundary step must submit before disabling its own submitter.
      event.preventDefault();
      enqueue('update', form, new URLSearchParams(new FormData(form)));
    } else {
      syncStepper(input);
      if (button.disabled) {
        input.focus();
      }
    }
  });

  document.addEventListener('change', function (event) {
    var select = event.target.closest('[data-cart-region]');
    if (select) {
      selectedRegion = select.value;
      var panel = select.closest('[data-cart-summary]');
      syncSummaries(panel ? parseInt(panel.getAttribute('data-cart-subtotal'), 10) || 0 : 0);
      return;
    }
    var input = event.target.closest('input[data-cart-qty]');
    if (!input) {
      return;
    }
    clampQuantity(input);
    syncStepper(input);
    var form = input.closest('form[data-cart-update]');
    if (form) {
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }
  });

  /* --- Form interception ---------------------------------------------------------------------- */

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var kind = null;
    if (form.matches('form[data-cart-add]')) {
      kind = 'add';
    } else if (form.matches('form[data-cart-update]')) {
      kind = 'update';
    } else if (form.matches('form[data-cart-remove]')) {
      kind = 'remove';
    } else {
      return; // e.g. the region estimate form keeps its native GET reload.
    }
    event.preventDefault();
    // Capture the request fields now — the queue must never send a later state.
    enqueue(kind, form, new URLSearchParams(new FormData(form)));
  });

  /* --- Drawer -------------------------------------------------------------------------------------- */

  function fetchCart() {
    return fetch('/cart', {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(function (response) {
      if (!response.ok) {
        return null;
      }
      return response.json();
    }, function () {
      return null;
    });
  }

  function openDrawer(opener) {
    if (!drawer || typeof drawer.showModal !== 'function') {
      return;
    }
    // Returning focus to a hidden mobile-menu item would vanish; use the
    // always-visible header bag control instead.
    lastOpener = opener && opener.closest('#mobile-menu')
      ? document.querySelector('header [data-cart-bag]')
      : (opener || null);
    var menu = document.getElementById('mobile-menu');
    if (menu && menu.open) {
      menu.close(); // Never stack the menu and the drawer (inert focus).
    }
    fetchCart().then(function (data) {
      if (!data) {
        window.location.href = '/cart'; // No drawer without data: use the page.
        return;
      }
      applyPayload(data);
      if (!drawer.open) {
        drawer.showModal();
      }
      var close = drawer.querySelector('[data-cart-close]');
      if (close) {
        close.focus();
      }
    });
  }

  eachNodeList(document.querySelectorAll('[data-cart-bag]'), function (link) {
    link.addEventListener('click', function (event) {
      if (!drawer || typeof drawer.showModal !== 'function') {
        return; // Native /cart navigation.
      }
      event.preventDefault();
      openDrawer(link);
    });
  });

  if (drawer) {
    drawer.addEventListener('click', function (event) {
      if (event.target.closest('[data-cart-close]')) {
        drawer.close();
        return;
      }
      if (event.target === drawer) {
        var bounds = drawer.getBoundingClientRect();
        var outside = event.clientX < bounds.left || event.clientX > bounds.right ||
          event.clientY < bounds.top || event.clientY > bounds.bottom;
        if (outside) {
          drawer.close(); // Backdrop press.
        }
      }
    });
    drawer.addEventListener('keydown', function (event) {
      if (event.key !== 'Tab') {
        return;
      }
      var controls = Array.prototype.slice.call(
        drawer.querySelectorAll('button:not(:disabled), a[href], input:not([disabled]), select:not([disabled])'));
      if (!controls.length) {
        return;
      }
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
    drawer.addEventListener('close', function () {
      if (lastOpener && document.contains(lastOpener)) {
        lastOpener.focus();
      }
      lastOpener = null;
    });
  }

  /* --- Authoritative refresh on page restoration ------------------------------------------------------ */

  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) {
      return;
    }
    fetchCart().then(function (data) {
      if (data) {
        applyPayload(data);
      }
    });
  });

  /* --- Init: JS-only affordances -------------------------------------------------------------------------- */

  (function init() {
    var regionSelect = document.querySelector('[data-cart-region]');
    if (regionSelect) {
      selectedRegion = regionSelect.value;
      syncSummaries(parseInt(
        regionSelect.closest('[data-cart-summary]').getAttribute('data-cart-subtotal'), 10) || 0);
    }
    // The region estimate recomputes live; the no-JS submit button goes away.
    eachNodeList(document.querySelectorAll('[data-cart-region-submit]'), function (button) {
      button.hidden = true;
    });
    // Without JavaScript quantities remain editable with the native Update form.
    eachNodeList(document.querySelectorAll('[data-cart-step]'), function (button) {
      button.hidden = false;
    });
  })();
})();
