/*
 * Habi storefront enhancements (progressive, no dependencies).
 * - Mobile menu: native <dialog id="mobile-menu">, opened by [data-menu-open];
 *   Esc closes natively, backdrop click and [data-menu-close] close it, and
 *   focus returns to the opener.
 * - Material tabs (home): roving tabindex over [data-material-tab]; panels
 *   [data-material-panel] toggle the native hidden attribute so the no-JS
 *   fallback (all panels visible) stays intact.
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

  /* --- Material tabs (home) -------------------------------------------- */

  var tablist = document.querySelector('[data-material-tabs]');
  if (tablist) {
    var tabs = Array.prototype.slice.call(tablist.querySelectorAll('[data-material-tab]'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('[data-material-panel]'));
    var orientation = window.matchMedia('(min-width: 896px)');
    function updateOrientation() {
      tablist.querySelector('[role="tablist"]').setAttribute('aria-orientation',
        orientation.matches ? 'vertical' : 'horizontal');
    }
    orientation.addEventListener('change', updateOrientation);
    updateOrientation();

    function selectTab(id, moveFocus) {
      tabs.forEach(function (tab) {
        var selected = tab.getAttribute('data-material-tab') === id;
        tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        tab.setAttribute('tabindex', selected ? '0' : '-1');
        var selectedClasses = ['bg-inset', 'font-semibold', '@4xl:border-action'];
        var unselectedClasses = ['text-ink-2', 'hover:bg-page', 'hover:text-ink', '@4xl:border-transparent'];
        selectedClasses.forEach(function (className) {
          tab.classList.toggle(className, selected);
        });
        unselectedClasses.forEach(function (className) {
          tab.classList.toggle(className, !selected);
        });
        if (selected && moveFocus) {
          tab.focus();
        }
      });
      panels.forEach(function (panel) {
        panel.hidden = panel.getAttribute('data-material-panel') !== id;
      });
    }

    if (tabs.length) {
      selectTab(tabs[0].getAttribute('data-material-tab'), false);
    }

    tabs.forEach(function (tab, index) {
      tab.addEventListener('click', function () {
        selectTab(tab.getAttribute('data-material-tab'), false);
      });
      tab.addEventListener('keydown', function (event) {
        var next = null;
        if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
          next = tabs[(index + 1) % tabs.length];
        } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
          next = tabs[(index - 1 + tabs.length) % tabs.length];
        } else if (event.key === 'Home') {
          next = tabs[0];
        } else if (event.key === 'End') {
          next = tabs[tabs.length - 1];
        }
        if (next) {
          event.preventDefault();
          selectTab(next.getAttribute('data-material-tab'), true);
        }
      });
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
