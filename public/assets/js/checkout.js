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
 */
(function () {
  'use strict';

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
      busy(paymentForm.querySelector('[data-checkout-submit]'), 'Processing\u2026');
    });
  }

  var placeForm = document.querySelector('form[data-checkout-place]');
  if (placeForm) {
    placeForm.addEventListener('submit', function () {
      busy(placeForm.querySelector('[data-checkout-submit]'), 'Placing\u2026');
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

  function busy(button, label) {
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
    if (status) {
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
