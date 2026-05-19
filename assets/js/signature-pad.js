/* ============================================================================
   FEU LFMS — Signature pad component (ACCESSIBILITY.md #5)

   Progressively enhances a file input into a canvas-based signature pad.

   Markup contract:
     <div class="sig-pad" data-sig-pad>
       <input type="file" id="signature" name="signature" accept="image/*">
     </div>

   JS injects canvas + Clear button + status + a "Upload a file instead"
   toggle above the file input. The canvas writes a PNG to the file input
   via DataTransfer on every stroke end, so the server sees a normal file
   upload — no server-side change required.

   A11y notes:
     - Canvas has role="img" + aria-label so SRs can identify it, but is
       NOT focusable (keyboard users cannot draw — the fallback toggle is
       the operational path for them, and it's always present, not hidden
       behind a "didn't work" state).
     - Status text is aria-live="polite" — announces capture / clear.
     - touch-action: none on the canvas prevents page-scroll while drawing.

   No dependencies. Vanilla JS, IIFE-scoped.
   ============================================================================ */
(function () {
  'use strict';

  var CANVAS_HEIGHT_CSS = 180;
  var STROKE_COLOR = '#111';
  var STROKE_WIDTH = 2;

  function init(container) {
    var fileInput = container.querySelector('input[type="file"]');
    if (!fileInput) return;

    // ---- Build DOM --------------------------------------------------------

    var wrap = document.createElement('div');
    wrap.className = 'sig-pad-canvas-wrap';

    var canvas = document.createElement('canvas');
    canvas.className = 'sig-pad-canvas';
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label',
      'Signature drawing area. Use your finger, mouse, or pen to sign.');

    wrap.appendChild(canvas);

    var clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'btn btn-ghost btn-sm';
    clearBtn.textContent = 'Clear';

    var statusEl = document.createElement('p');
    statusEl.className = 'sig-pad-status';
    statusEl.setAttribute('aria-live', 'polite');
    statusEl.textContent = '';

    var toolbar = document.createElement('div');
    toolbar.className = 'sig-pad-toolbar';
    toolbar.appendChild(clearBtn);
    toolbar.appendChild(statusEl);

    var fallbackToggle = document.createElement('button');
    fallbackToggle.type = 'button';
    fallbackToggle.className = 'media-fallback-toggle';
    fallbackToggle.textContent = 'Upload a signature image instead';

    // Insert before the file input so visual order is: pad → toolbar → toggle → (hidden) file input.
    container.insertBefore(wrap, fileInput);
    container.insertBefore(toolbar, fileInput);
    container.insertBefore(fallbackToggle, fileInput);

    fileInput.classList.add('media-fallback-hidden');

    // ---- Drawing state ----------------------------------------------------

    var ctx = canvas.getContext('2d');
    var strokes = []; // array of arrays of {x, y} (CSS pixels)
    var current = null;
    var dpr = 1;

    function resize() {
      var rect = wrap.getBoundingClientRect();
      var width = Math.max(1, Math.floor(rect.width));
      dpr = window.devicePixelRatio || 1;
      canvas.width = width * dpr;
      canvas.height = CANVAS_HEIGHT_CSS * dpr;
      canvas.style.width = width + 'px';
      canvas.style.height = CANVAS_HEIGHT_CSS + 'px';
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      redraw();
    }

    function redraw() {
      ctx.clearRect(0, 0, canvas.width / dpr, canvas.height / dpr);
      ctx.strokeStyle = STROKE_COLOR;
      ctx.lineWidth = STROKE_WIDTH;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      for (var s = 0; s < strokes.length; s++) {
        var stroke = strokes[s];
        if (stroke.length < 1) continue;
        ctx.beginPath();
        ctx.moveTo(stroke[0].x, stroke[0].y);
        if (stroke.length === 1) {
          // single tap — draw a dot
          ctx.lineTo(stroke[0].x + 0.01, stroke[0].y);
        } else {
          for (var i = 1; i < stroke.length; i++) {
            ctx.lineTo(stroke[i].x, stroke[i].y);
          }
        }
        ctx.stroke();
      }
    }

    function pointFromEvent(e) {
      var rect = canvas.getBoundingClientRect();
      return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    function isEmpty() { return strokes.length === 0; }

    function setStatus() {
      statusEl.textContent = isEmpty() ? '' : 'Signature captured.';
    }

    function pushToFileInput() {
      if (isEmpty()) {
        try { fileInput.value = ''; } catch (_) { /* ignore */ }
        return;
      }
      canvas.toBlob(function (blob) {
        if (!blob) return;
        try {
          var file = new File([blob], 'signature.png', { type: 'image/png' });
          var dt = new DataTransfer();
          dt.items.add(file);
          fileInput.files = dt.files;
        } catch (_) { /* DataTransfer unsupported — fallback toggle is the answer */ }
      }, 'image/png');
    }

    // ---- Pointer wiring ---------------------------------------------------

    canvas.addEventListener('pointerdown', function (e) {
      if (e.button !== undefined && e.button !== 0) return; // primary only
      e.preventDefault();
      try { canvas.setPointerCapture(e.pointerId); } catch (_) { /* ignore */ }
      current = [pointFromEvent(e)];
      strokes.push(current);
      redraw();
    });

    canvas.addEventListener('pointermove', function (e) {
      if (!current) return;
      e.preventDefault();
      current.push(pointFromEvent(e));
      redraw();
    });

    function endStroke() {
      if (!current) return;
      current = null;
      setStatus();
      pushToFileInput();
    }
    canvas.addEventListener('pointerup', endStroke);
    canvas.addEventListener('pointercancel', endStroke);
    canvas.addEventListener('pointerleave', endStroke);

    // ---- Clear ------------------------------------------------------------

    clearBtn.addEventListener('click', function () {
      strokes = [];
      current = null;
      redraw();
      setStatus();
      pushToFileInput();
    });

    // ---- Fallback toggle --------------------------------------------------

    var fallbackOpen = false;
    fallbackToggle.addEventListener('click', function () {
      fallbackOpen = !fallbackOpen;
      if (fallbackOpen) {
        // Switch to file-upload mode: clear canvas, reveal file input.
        strokes = [];
        current = null;
        redraw();
        setStatus();
        try { fileInput.value = ''; } catch (_) { /* ignore */ }
        fileInput.classList.remove('media-fallback-hidden');
        fallbackToggle.textContent = 'Use the signature pad instead';
        wrap.hidden = true;
        toolbar.hidden = true;
        fileInput.focus();
      } else {
        fileInput.classList.add('media-fallback-hidden');
        try { fileInput.value = ''; } catch (_) { /* ignore */ }
        fallbackToggle.textContent = 'Upload a signature image instead';
        wrap.hidden = false;
        toolbar.hidden = false;
      }
    });

    // ---- Init -------------------------------------------------------------

    resize();
    if (typeof window.ResizeObserver === 'function') {
      var ro = new window.ResizeObserver(function () { resize(); });
      ro.observe(wrap);
    } else {
      window.addEventListener('resize', resize);
    }
  }

  function initAll() {
    var pads = document.querySelectorAll('[data-sig-pad]');
    for (var i = 0; i < pads.length; i++) init(pads[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
}());
