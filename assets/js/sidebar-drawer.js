/* ============================================================================
   FEU LFMS — Responsive sidebar drawer (ACCESSIBILITY.md #6, Task 27)
   Below 1024px the sidebar collapses off-canvas. The hamburger in the header
   slides it in as a sheet with backdrop + focus trap + ESC dismiss.

   Markup contract (see partials/header.php + partials/sidebar.php):
     <button class="app-header-hamburger" aria-controls="app-sidebar"
             aria-expanded="false">…</button>
     <aside class="app-sidebar" id="app-sidebar">
       <button class="app-sidebar-close" aria-label="Close navigation">…</button>
       <nav>…</nav>
     </aside>
     <div class="app-sidebar-backdrop" hidden></div>

   The drawer is purely a CSS-driven sheet: this script only toggles
   `body.sidebar-open`, manages aria-expanded, and traps focus while open.

   No dependencies. Vanilla JS, IIFE-scoped.
   ============================================================================ */
(function () {
  'use strict';

  var body      = document.body;
  var hamburger = document.querySelector('.app-header-hamburger');
  var sidebar   = document.getElementById('app-sidebar');
  var backdrop  = document.querySelector('.app-sidebar-backdrop');
  var closeBtn  = sidebar && sidebar.querySelector('.app-sidebar-close');

  if (!hamburger || !sidebar) return;

  var FOCUSABLE = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';
  var lastFocus = null;

  function isOpen() {
    return body.classList.contains('sidebar-open');
  }

  function focusables() {
    return Array.prototype.slice.call(sidebar.querySelectorAll(FOCUSABLE))
      .filter(function (el) { return el.offsetParent !== null; });
  }

  function open() {
    if (isOpen()) return;
    lastFocus = document.activeElement;
    body.classList.add('sidebar-open');
    hamburger.setAttribute('aria-expanded', 'true');
    if (backdrop) backdrop.hidden = false;
    var first = focusables()[0];
    if (first) {
      window.requestAnimationFrame(function () { first.focus(); });
    }
  }

  function close() {
    if (!isOpen()) return;
    body.classList.remove('sidebar-open');
    hamburger.setAttribute('aria-expanded', 'false');
    if (backdrop) backdrop.hidden = true;
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus(); } catch (_) { /* element gone */ }
    }
  }

  hamburger.addEventListener('click', function (e) {
    e.preventDefault();
    isOpen() ? close() : open();
  });

  if (closeBtn) {
    closeBtn.addEventListener('click', function (e) {
      e.preventDefault();
      close();
    });
  }

  if (backdrop) {
    backdrop.addEventListener('click', close);
  }

  // Close when a nav link is activated (route change unmounts the drawer
  // anyway, but we tidy up so the back-button history is consistent).
  sidebar.addEventListener('click', function (e) {
    var link = e.target.closest('a[href]');
    if (link && isOpen()) close();
  });

  document.addEventListener('keydown', function (e) {
    if (!isOpen()) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      close();
      return;
    }
    if (e.key === 'Tab') {
      var items = focusables();
      if (items.length === 0) {
        e.preventDefault();
        return;
      }
      var first = items[0];
      var last  = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  });

  // If the viewport resizes back to desktop while the drawer is open, close
  // it so the layout returns to its normal grid state cleanly.
  var DESKTOP_QUERY = window.matchMedia('(min-width: 1025px)');
  var handleQuery = function (mq) { if (mq.matches && isOpen()) close(); };
  if (typeof DESKTOP_QUERY.addEventListener === 'function') {
    DESKTOP_QUERY.addEventListener('change', handleQuery);
  } else if (typeof DESKTOP_QUERY.addListener === 'function') {
    DESKTOP_QUERY.addListener(handleQuery);  // pre-Safari 14
  }
}());
