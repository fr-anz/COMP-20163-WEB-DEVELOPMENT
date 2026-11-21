/*
 * Habi checkout enhancements (M5, progressive, no dependencies).
 *
 * - Every checkout form posts natively and works without JavaScript; this
 *   file only adds focus management and processing feedback.
 * - On load, focus moves to the step heading (the reference's "focus moves
 *   to each new step heading"). When the server re-rendered a step with
 *   inline errors, focus goes to the first invalid field instead; the error
 *   summary links above the fields jump to each field.
 * - The payment simulation waits 1.5–2.5 s server-side (PRD R14). While a
 *   payment or place submission is in flight, its submit button shows the
 *   spinner with aria-busy and a polite status line appears; the page then
 *   navigates normally via 303, including safe validation/decline feedback
 *   displayed on a GET of the same step. Nothing is fetched client-side,
 *   so the server response always stays authoritative.
 * - Card inputs are rendered empty by the server, use autocomplete="off"
 *   and are never written to storage here; nothing about the card outlives
 *   the browser tab (N4).
 * - Payment methods (M11): without JavaScript every method's subform is
 *   visible and the radio decides; here the unselected subforms are hidden.
 *   The chosen receipt's file name replaces the upload hint, and Copy
 *   buttons (hidden without JavaScript) copy account and reference numbers.
 *   Only the card method shows the "Processing payment" status.
 */
(function () {
  'use strict';

  /* Customer strings come from the #i18n JSON block in the base layout
     (lang/*.php keys under js., N9); a missing key shows the key. */
  var STRINGS = (function () {
    var node = document.getElementById('i18n');
    try {
      return node ? JSON.parse(node.textContent) : {};
    } catch (error) {
      return {};
    }
  })();

  function i18n(key, params) {
    var text = Object.prototype.hasOwnProperty.call(STRINGS, key) ? STRINGS[key] : key;
    Object.keys(params || {}).forEach(function (name) {
      text = text.split('{' + name + '}').join(String(params[name]));
    });
    return text;
  }

  /* --- Focus on step entry ------------------------------------------------- */

  var firstInvalid = document.querySelector('main [aria-invalid="true"]');
  if (firstInvalid) {
    firstInvalid.focus();
  } else {
    var heading = document.querySelector('main h1[tabindex="-1"]');
    if (heading) {
      heading.focus();
    }
  }

  /* --- Processing feedback on payment / place -------------------------------- */

  var SPINNER =
    '<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="motion-safe:animate-spin">' +
    '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-opacity="0.25" stroke-width="2.5"></circle>' +
    '<path d="M21 12a9 9 0 0 0-9-9" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"></path></svg>';

  var paymentForm = document.querySelector('form[data-checkout-payment]');
  if (paymentForm) {
    paymentForm.addEventListener('submit', function () {
      var chosen = paymentForm.querySelector('input[name="method"]:checked');
      var card = !chosen || chosen.value === 'card';
      busy(paymentForm.querySelector('[data-checkout-submit]'), i18n(card ? 'checkout.processing' : 'checkout.saving'), card);
    });

    var methods = paymentForm.querySelectorAll('input[name="method"]');
    var syncPanels = function () {
      Array.prototype.forEach.call(methods, function (radio) {
        var panel = radio.getAttribute('aria-controls');
        panel = panel ? document.getElementById(panel) : null;
        if (panel) {
          panel.hidden = !radio.checked;
        }
        if (panel) {
          radio.setAttribute('aria-expanded', radio.checked ? 'true' : 'false');
        }
      });
    };
    Array.prototype.forEach.call(methods, function (radio) {
      radio.addEventListener('change', syncPanels);
    });
    syncPanels();

    Array.prototype.forEach.call(paymentForm.querySelectorAll('[data-file-input]'), function (input) {
      var hint = input.closest('div').querySelector('[data-file-name]');
      var original = hint ? hint.textContent : '';
      input.addEventListener('change', function () {
        if (hint) {
          hint.textContent = input.files && input.files[0] ? input.files[0].name : original;
        }
      });
    });
  }

  /* --- Copy buttons ---------------------------------------------------------- */

  if (navigator.clipboard && window.isSecureContext) {
    Array.prototype.forEach.call(document.querySelectorAll('button[data-copy]'), function (button) {
      var label = button.querySelector('[data-copy-label]');
      button.hidden = false;
      button.addEventListener('click', function () {
        navigator.clipboard.writeText(button.getAttribute('data-copy')).then(function () {
          if (label) {
            label.textContent = i18n('copied');
            window.setTimeout(function () { label.textContent = i18n('copy'); }, 2000);
          }
        });
      });
    });
  }

  var placeForm = document.querySelector('form[data-checkout-place]');
  if (placeForm) {
    placeForm.addEventListener('submit', function () {
      busy(placeForm.querySelector('[data-checkout-submit]'), i18n('checkout.placing'));
    });
  }

  /* --- Sensitive-input hygiene and control recovery -------------------------- */

  var SENSITIVE_SELECTOR =
    'form[data-checkout-payment] input[name="card_number"], ' +
    'form[data-checkout-payment] input[name="expiry"], ' +
    'form[data-checkout-payment] input[name="cvc"]';

  function clearSensitive() {
    Array.prototype.forEach.call(document.querySelectorAll(SENSITIVE_SELECTOR), function (input) {
      input.value = '';
    });
  }

  function recover(button) {
    if (!button || button.getAttribute('aria-busy') !== 'true') {
      return;
    }
    button.removeAttribute('aria-busy');
    button.disabled = false;
    delete button.dataset.busy;
    var slot = button.querySelector('[data-checkout-submit-label]');
    if (slot && button.originalLabel) {
      slot.innerHTML = button.originalLabel;
    }
    var form = button.closest('form');
    var status = form ? form.querySelector('[data-checkout-processing]') : null;
    if (status) {
      status.hidden = true;
      status.classList.add('hidden');
      status.classList.remove('flex');
    }
  }

  function busy(button, label, showStatus) {
    if (button.getAttribute('aria-busy') === 'true') {
      return;
    }
    var slot = button.querySelector('[data-checkout-submit-label]');
    if (slot) {
      button.originalLabel = slot.innerHTML;
    }
    button.setAttribute('aria-busy', 'true');
    button.disabled = true;
    if (slot) {
      slot.innerHTML = SPINNER + '<span></span>';
      slot.lastElementChild.textContent = label;
    }
    var form = button.closest('form');
    var status = form ? form.querySelector('[data-checkout-processing]') : null;
    if (status && showStatus !== false) {
      status.hidden = false;
      status.classList.remove('hidden');
      status.classList.add('flex');
    }
  }

  // Leaving the page discards anything typed into the card fields (N4). This
  // runs on pagehide — after the browser has serialized a native POST — and
  // again on pageshow, which also covers bfcache restores of this page.
  window.addEventListener('pagehide', clearSensitive);
  window.addEventListener('pageshow', function () {
    clearSensitive();
    var paymentButton = paymentForm && paymentForm.querySelector('[data-checkout-submit]');
    if (paymentButton) {
      recover(paymentButton);
    }
    var placeButton = placeForm && placeForm.querySelector('[data-checkout-submit]');
    if (placeButton) {
      recover(placeButton);
    }
  });
})();
