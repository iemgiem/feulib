/* ============================================================================
   FEU LFMS — Live notification bell polling (Task 20)
   Polls GET ?p=api.notifications every 60 s and updates the badge count
   without a full page reload. The markup in partials/header.php is the
   server-rendered baseline; this script keeps it in sync client-side.

   No dependencies. Vanilla JS, IIFE-scoped.
   ============================================================================ */
(function () {
  'use strict';

  var POLL_MS = 60000;
  var endpoint = (function () {
    // Derive the API URL from the current page origin + path, replacing or
    // appending the `p` query parameter.
    var url = new URL(window.location.href);
    url.searchParams.set('p', 'api.notifications');
    // Strip any other params that belong to the current page.
    var clean = new URL(url.origin + url.pathname);
    clean.searchParams.set('p', 'api.notifications');
    return clean.toString();
  }());

  function updateBell(unread) {
    var bell = document.querySelector('.app-header-bell');
    if (!bell) return;

    var badge = bell.querySelector('.app-header-bell-badge');
    var count = Math.min(unread, 99);
    var label = unread > 0
      ? ' (' + unread + ' unread)'
      : '';

    // Update aria-label on the anchor itself
    var current = bell.getAttribute('aria-label') || '';
    bell.setAttribute(
      'aria-label',
      current.replace(/ \(\d+ unread\)$/, '') + label
    );

    if (unread > 0) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'app-header-bell-badge';
        bell.appendChild(badge);
      }
      badge.textContent = count + (unread > 99 ? '+' : '');
    } else {
      if (badge) {
        badge.parentNode.removeChild(badge);
      }
    }
  }

  function poll() {
    fetch(endpoint, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (res) {
        if (!res.ok) return;
        return res.json();
      })
      .then(function (data) {
        if (data && typeof data.unread === 'number') {
          updateBell(data.unread);
        }
      })
      .catch(function () {
        // Network failure — silently skip; will retry next interval.
      });
  }

  // Only run when the bell element exists (authenticated pages only).
  if (document.querySelector('.app-header-bell')) {
    setInterval(poll, POLL_MS);
  }
}());
