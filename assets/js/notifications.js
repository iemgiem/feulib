/* ============================================================================
   FEU LFMS — Live notification bell polling (Task 20)
   Polls GET ?p=api.notifications every 60 s and updates the badge count
   without a full page reload. The markup in partials/header.php is the
   server-rendered baseline; this script keeps it in sync client-side.

   Also announces new notifications through the polite #toast-region
   live region so screen-reader users hear when the badge changes.

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

  // Seed previous count from the server-rendered badge so we don't announce
  // notifications that were already on screen when the page loaded.
  var previousUnread = (function () {
    var badge = document.querySelector('.app-header-bell .app-header-bell-badge');
    if (!badge) return 0;
    var n = parseInt((badge.textContent || '0').replace(/[^0-9]/g, ''), 10);
    return isNaN(n) ? 0 : n;
  }());

  function announce(message) {
    if (!message) return;
    var region = document.getElementById('toast-region');
    if (!region) return;
    // Wrap each message in its own element so consecutive polls with the
    // same text still get announced (changing textContent on the parent
    // would deduplicate in some screen readers).
    var line = document.createElement('p');
    line.textContent = message;
    region.appendChild(line);
    // Trim history so the region doesn't grow unbounded over a long session.
    while (region.childNodes.length > 5) {
      region.removeChild(region.firstChild);
    }
  }

  function announceIfNew(unread, items) {
    if (unread <= previousUnread) {
      previousUnread = unread;
      return;
    }
    var delta = unread - previousUnread;
    previousUnread = unread;

    // Find the most recent unread item to read out by title.
    var firstUnread = null;
    if (Array.isArray(items)) {
      for (var i = 0; i < items.length; i++) {
        if (!items[i].is_read) { firstUnread = items[i]; break; }
      }
    }
    if (firstUnread && firstUnread.title) {
      if (delta === 1) {
        announce('New notification: ' + firstUnread.title);
      } else {
        announce('New notification: ' + firstUnread.title +
                 ' (and ' + (delta - 1) + ' more)');
      }
    } else {
      announce(delta === 1
        ? 'You have 1 new notification.'
        : 'You have ' + delta + ' new notifications.');
    }
  }

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
          announceIfNew(data.unread, data.items);
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
