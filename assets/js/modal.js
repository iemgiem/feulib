/* ============================================================================
   FEU LFMS — Accessible modal component (Task 27)
   Wires up .modal markup rendered by partials/modal.php.

   Markup contract (see partials/modal.php):
     <div class="modal" id="foo" hidden data-modal [data-modal-no-dismiss]>
       <div class="modal-backdrop" data-modal-backdrop></div>
       <div class="modal-dialog" role="dialog|alertdialog" aria-modal="true" ...>
         <header class="modal-header">…<button data-modal-close>×</button></header>
         <div class="modal-body">…</div>
         <footer class="modal-footer">…</footer>
       </div>
     </div>

   Triggers (anywhere on the page):
     data-modal-open="<modal-id>"   — open the modal with that id
     data-modal-close               — close the enclosing modal
     data-modal-hold[="<ms>"]       — button must be held N ms (default 1500)
                                       before its enclosing <form> submits.

   No dependencies. Vanilla JS, IIFE-scoped.
   ============================================================================ */
(function () {
  'use strict';

  var HOLD_DEFAULT_MS = 1500;
  var FOCUSABLE = [
    'a[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])'
  ].join(',');

  // Stack of open modals so ESC closes the topmost.
  var openStack = [];

  function focusables(modal) {
    return Array.prototype.slice.call(
      modal.querySelectorAll(FOCUSABLE)
    ).filter(function (el) {
      return el.offsetParent !== null || el === document.activeElement;
    });
  }

  function openModal(modal) {
    if (!modal || openStack.indexOf(modal) !== -1) return;

    modal._previousFocus = document.activeElement;
    modal.hidden = false;
    document.body.classList.add('modal-open');
    openStack.push(modal);

    // Move focus into the dialog (prefer the first focusable that isn't the
    // close button, so users land on actionable content).
    var items = focusables(modal);
    var firstReal = items.filter(function (el) {
      return !el.hasAttribute('data-modal-close');
    })[0];
    var target = firstReal || items[0] || modal.querySelector('.modal-dialog');
    if (target) {
      // Defer to next frame so transition doesn't swallow focus.
      window.requestAnimationFrame(function () { target.focus(); });
    }
  }

  function closeModal(modal) {
    if (!modal) return;
    var idx = openStack.indexOf(modal);
    if (idx === -1) return;

    openStack.splice(idx, 1);
    modal.hidden = true;
    if (openStack.length === 0) {
      document.body.classList.remove('modal-open');
    }

    // Cancel any in-progress hold on buttons inside this modal.
    Array.prototype.forEach.call(
      modal.querySelectorAll('[data-modal-hold]'),
      function (btn) { resetHold(btn); }
    );

    var prev = modal._previousFocus;
    if (prev && typeof prev.focus === 'function') {
      try { prev.focus(); } catch (_) { /* element gone */ }
    }
  }

  function trapFocus(modal, event) {
    if (event.key !== 'Tab') return;
    var items = focusables(modal);
    if (items.length === 0) {
      event.preventDefault();
      return;
    }
    var first = items[0];
    var last  = items[items.length - 1];
    var active = document.activeElement;

    if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  // ---- Hold-to-confirm ------------------------------------------------------

  function resetHold(btn) {
    if (btn._holdTimer) {
      clearTimeout(btn._holdTimer);
      btn._holdTimer = null;
    }
    if (btn._holdRaf) {
      cancelAnimationFrame(btn._holdRaf);
      btn._holdRaf = null;
    }
    btn.classList.remove('is-holding');
    var bar = btn.querySelector('.modal-hold-progress');
    if (bar) bar.style.transform = 'scaleX(0)';
    btn.removeAttribute('aria-pressed');
  }

  function startHold(btn, event) {
    // Ignore right-click / middle-click and modified clicks.
    if (event.type === 'pointerdown' && event.button !== 0) return;
    if (btn.disabled) return;

    var ms = parseInt(btn.getAttribute('data-modal-hold'), 10);
    if (!ms || isNaN(ms) || ms <= 0) ms = HOLD_DEFAULT_MS;

    resetHold(btn);
    btn.classList.add('is-holding');
    btn.setAttribute('aria-pressed', 'true');

    var bar = btn.querySelector('.modal-hold-progress');
    if (bar) {
      var start = performance.now();
      var tick = function (now) {
        var pct = Math.min(1, (now - start) / ms);
        bar.style.transform = 'scaleX(' + pct + ')';
        if (pct < 1 && btn._holdTimer) {
          btn._holdRaf = requestAnimationFrame(tick);
        }
      };
      btn._holdRaf = requestAnimationFrame(tick);
    }

    btn._holdTimer = setTimeout(function () {
      btn._holdTimer = null;
      var form = btn.closest('form');
      resetHold(btn);
      if (form) {
        // requestSubmit() (no submitter arg) runs native constraint validation
        // and submits — unlike form.submit() which bypasses it. We cannot pass
        // btn here because the hold button is type="button", not type="submit",
        // and passing a non-submit-button to requestSubmit() throws TypeError
        // in spec-compliant browsers.
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      }
    }, ms);
  }

  // ---- Wiring ---------------------------------------------------------------

  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-modal-open]');
    if (opener) {
      e.preventDefault();
      var id = opener.getAttribute('data-modal-open');
      var modal = document.getElementById(id);
      if (modal) openModal(modal);
      return;
    }

    var closer = e.target.closest('[data-modal-close]');
    if (closer) {
      e.preventDefault();
      var m = closer.closest('.modal');
      if (m) closeModal(m);
      return;
    }

    var backdrop = e.target.closest('[data-modal-backdrop]');
    if (backdrop) {
      var bm = backdrop.closest('.modal');
      if (bm && !bm.hasAttribute('data-modal-no-dismiss')) {
        closeModal(bm);
      }
    }
  });

  document.addEventListener('keydown', function (e) {
    if (openStack.length === 0) return;
    var top = openStack[openStack.length - 1];

    if (e.key === 'Escape' && !top.hasAttribute('data-modal-no-dismiss')) {
      e.preventDefault();
      closeModal(top);
      return;
    }
    if (e.key === 'Tab') {
      trapFocus(top, e);
    }
  });

  // Hold-to-confirm pointer handling — delegated on document.
  document.addEventListener('pointerdown', function (e) {
    var btn = e.target.closest('[data-modal-hold]');
    if (!btn) return;
    e.preventDefault();
    startHold(btn, e);
  });

  var cancelHold = function (e) {
    var btn = e.target.closest('[data-modal-hold]');
    if (btn) resetHold(btn);
  };
  document.addEventListener('pointerup',     cancelHold);
  document.addEventListener('pointerleave',  cancelHold, true);
  document.addEventListener('pointercancel', cancelHold);

  // Hold buttons should NOT submit on a normal click — only on the timer.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-modal-hold]');
    if (btn) e.preventDefault();
  }, true);

  // Keyboard activation: Space/Enter held also triggers (accessibility).
  document.addEventListener('keydown', function (e) {
    if (e.key !== ' ' && e.key !== 'Enter') return;
    var btn = e.target.closest && e.target.closest('[data-modal-hold]');
    if (!btn || e.repeat) return;
    e.preventDefault();
    startHold(btn, e);
  });
  document.addEventListener('keyup', function (e) {
    if (e.key !== ' ' && e.key !== 'Enter') return;
    var btn = e.target.closest && e.target.closest('[data-modal-hold]');
    if (btn) resetHold(btn);
  });
}());
