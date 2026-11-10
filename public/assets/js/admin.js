/**
 * Habi admin enhancements (M6). Every admin flow works without JavaScript;
 * this only improves it:
 * - Server-rendered dialogs ([data-admin-dialog]): initial focus (first
 *   invalid field, else first field, else the title), Tab/Shift+Tab
 *   containment, Esc returns to the dialog's close URL, page scroll lock.
 * - Filter forms ([data-admin-filters]): submit on change, hide Apply.
 * - Password Show/Hide toggle ([data-password-toggle]).
 * - Image inputs ([data-file-input]): show the chosen file name and warn
 *   early about files over 2 MB (the server still validates everything).
 */
(function () {
  'use strict';

  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
    'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  /* --- Dialogs ------------------------------------------------------------ */

  var overlay = document.querySelector('[data-admin-dialog]');
  if (overlay) {
    var panel = overlay.querySelector('[role="dialog"]');
    var closeHref = overlay.getAttribute('data-close-href');
    document.documentElement.style.overflow = 'hidden';

    var visible = function () {
      return Array.prototype.filter.call(panel.querySelectorAll(FOCUSABLE), function (el) {
        return el.offsetParent !== null || el.getClientRects().length > 0;
      });
    };

    var initial = panel.querySelector('[aria-invalid="true"]') ||
      panel.querySelector('.flex-1 input:not([type="hidden"]), .flex-1 select, .flex-1 textarea') ||
      panel.querySelector('[data-dialog-close]');
    if (initial) {
      initial.focus();
    }

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        window.location.assign(closeHref);
        return;
      }
      if (event.key !== 'Tab') {
        return;
      }
      var items = visible();
      if (items.length === 0) {
        return;
      }
      var first = items[0];
      var last = items[items.length - 1];
      if (!panel.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
  }

  /* --- Filter forms: progressive auto-submit ------------------------------ */

  Array.prototype.forEach.call(document.querySelectorAll('form[data-admin-filters]'), function (form) {
    var apply = form.querySelector('[data-filter-submit]');
    if (apply) {
      apply.hidden = true;
    }
    form.addEventListener('change', function (event) {
      if (event.target.matches('select, input')) {
        form.submit();
      }
    });
  });

  /* --- Password visibility -------------------------------------------------- */

  Array.prototype.forEach.call(document.querySelectorAll('[data-password-toggle]'), function (button) {
    var input = document.getElementById(button.getAttribute('aria-controls'));
    if (!input) {
      return;
    }
    button.hidden = false;
    button.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.setAttribute('aria-pressed', show ? 'true' : 'false');
      button.firstChild.nodeValue = show ? 'Hide' : 'Show';
    });
  });

  /* --- Image file name + early size warning --------------------------------- */

  Array.prototype.forEach.call(document.querySelectorAll('[data-file-input]'), function (input) {
    var label = input.closest('div').querySelector('[data-file-name]');
    var original = label ? label.textContent : '';
    input.addEventListener('change', function () {
      if (!label) {
        return;
      }
      var file = input.files && input.files[0];
      if (!file) {
        label.textContent = original;
        label.classList.remove('text-error');
        return;
      }
      var tooLarge = file.size > 2 * 1024 * 1024;
      label.textContent = tooLarge
        ? file.name + ' is larger than 2 MB — choose a smaller image.'
        : 'Selected: ' + file.name;
      label.classList.toggle('text-error', tooLarge);
    });
  });
})();
