/* ============================================================================
   FEU LFMS — Release verification gate (Task 13 / DESIGN_BRIEF.md §9)

   The gold "Confirm release" button stays disabled until the staff member has:
     1. ticked all three verification checkboxes, AND
     2. captured a signature (canvas, or the file-upload fallback), AND
     3. captured a selfie (webcam, or the file-upload fallback).

   This is a UX gate only. release.php re-validates all of it server-side, so a
   no-JS / bypassed client still cannot release without the attestation + files.
   The button therefore starts ENABLED in markup and is disabled here on load —
   if this script fails to run, the server remains the gate.

   Capture is observed via the 'change' event the file inputs emit (native for
   the upload fallback; dispatched by signature-pad.js / selfie-capture.js for
   the canvas/webcam paths, which assign .files via DataTransfer).

   No dependencies. Vanilla JS, IIFE-scoped.
   ============================================================================ */
(function () {
  'use strict';

  function init() {
    var trigger = document.querySelector('[data-release-trigger]');
    if (!trigger) return;

    var checks   = Array.prototype.slice.call(document.querySelectorAll('[data-release-check]'));
    var sigIn    = document.getElementById('signature');
    var selfieIn = document.getElementById('selfie');
    var hint     = document.querySelector('[data-release-hint]');
    if (!sigIn || !selfieIn || checks.length === 0) return;

    function hasFile(input) { return !!(input.files && input.files.length > 0); }
    function allChecked() {
      return checks.every(function (c) { return c.checked; });
    }

    function missing() {
      var m = [];
      if (!allChecked())      m.push('complete the checklist');
      if (!hasFile(sigIn))    m.push('capture a signature');
      if (!hasFile(selfieIn)) m.push('capture a selfie');
      return m;
    }

    function evaluate() {
      var m = missing();
      var ready = m.length === 0;
      trigger.disabled = !ready;
      if (hint) {
        hint.textContent = ready
          ? 'All checks complete — ready to release.'
          : 'To release, ' + m.join(', ') + '.';
      }
    }

    checks.forEach(function (c) { c.addEventListener('change', evaluate); });
    sigIn.addEventListener('change', evaluate);
    selfieIn.addEventListener('change', evaluate);

    evaluate();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
