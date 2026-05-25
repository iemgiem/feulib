/* ============================================================================
   FEU LFMS — Selfie capture component (ACCESSIBILITY.md #5)

   Progressively enhances a file input into a getUserMedia capture widget.

   Markup contract:
     <div class="selfie-cap" data-selfie-cap>
       <input type="file" id="selfie" name="selfie" accept="image/*">
     </div>

   JS injects a <video> preview, Start/Capture/Retake controls, status text,
   and a "Upload a file instead" toggle above the file input. The captured
   frame is written to the file input via DataTransfer as a JPEG, so server
   handling is unchanged.

   A11y notes:
     - Status text is aria-live="polite" — announces camera-ready / captured.
     - Fallback toggle is always present (not hidden behind a "denied" state)
       so keyboard / no-camera users have a discoverable path.
     - If getUserMedia is not in the API surface at all, the canvas UI is not
       injected and the original file input stays visible as the only option.

   No dependencies. Vanilla JS, IIFE-scoped.
   ============================================================================ */
(function () {
  'use strict';

  function hasGetUserMedia() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  }

  function init(container) {
    var fileInput = container.querySelector('input[type="file"]');
    if (!fileInput) return;

    if (!hasGetUserMedia()) {
      // No API at all — leave the file input as the sole, visible control.
      return;
    }

    // ---- Build DOM --------------------------------------------------------

    var stage = document.createElement('div');
    stage.className = 'selfie-stage';

    var video = document.createElement('video');
    video.className = 'selfie-video';
    video.muted = true;
    video.playsInline = true;
    video.setAttribute('aria-label', 'Camera preview');

    var preview = document.createElement('img');
    preview.className = 'selfie-preview';
    preview.alt = 'Captured selfie preview';
    preview.hidden = true;

    stage.appendChild(video);
    stage.appendChild(preview);

    var startBtn = document.createElement('button');
    startBtn.type = 'button';
    startBtn.className = 'btn btn-secondary btn-sm';
    startBtn.textContent = 'Start camera';

    var captureBtn = document.createElement('button');
    captureBtn.type = 'button';
    captureBtn.className = 'btn btn-primary btn-sm';
    captureBtn.textContent = 'Take photo';
    captureBtn.hidden = true;

    var retakeBtn = document.createElement('button');
    retakeBtn.type = 'button';
    retakeBtn.className = 'btn btn-ghost btn-sm';
    retakeBtn.textContent = 'Retake';
    retakeBtn.hidden = true;

    var statusEl = document.createElement('p');
    statusEl.className = 'selfie-status';
    statusEl.setAttribute('aria-live', 'polite');
    statusEl.textContent = '';

    var toolbar = document.createElement('div');
    toolbar.className = 'selfie-toolbar';
    toolbar.appendChild(startBtn);
    toolbar.appendChild(captureBtn);
    toolbar.appendChild(retakeBtn);
    toolbar.appendChild(statusEl);

    var fallbackToggle = document.createElement('button');
    fallbackToggle.type = 'button';
    fallbackToggle.className = 'media-fallback-toggle';
    fallbackToggle.textContent = 'Upload a photo file instead';

    container.insertBefore(stage, fileInput);
    container.insertBefore(toolbar, fileInput);
    container.insertBefore(fallbackToggle, fileInput);

    fileInput.classList.add('media-fallback-hidden');

    var stream = null;
    var captureCanvas = document.createElement('canvas'); // offscreen

    function setStatus(msg) { statusEl.textContent = msg; }

    // DataTransfer assignment to .files does NOT fire 'change', so notify
    // explicitly — the release-verify gate listens for it.
    function notifyChange() {
      try {
        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (_) {
        var ev = document.createEvent('Event');
        ev.initEvent('change', true, true);
        fileInput.dispatchEvent(ev);
      }
    }

    function stopStream() {
      if (!stream) return;
      var tracks = stream.getTracks();
      for (var i = 0; i < tracks.length; i++) tracks[i].stop();
      stream = null;
    }

    function revokePreview() {
      if (preview.src && preview.src.indexOf('blob:') === 0) {
        try { URL.revokeObjectURL(preview.src); } catch (_) { /* ignore */ }
      }
      preview.removeAttribute('src');
    }

    // ---- Start camera -----------------------------------------------------

    startBtn.addEventListener('click', function () {
      setStatus('Requesting camera…');
      navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: false
      }).then(function (s) {
        stream = s;
        video.srcObject = s;
        return video.play();
      }).then(function () {
        startBtn.hidden = true;
        captureBtn.hidden = false;
        captureBtn.focus();
        setStatus('Camera ready. Press "Take photo" when ready.');
      }).catch(function (err) {
        var msg = (err && err.message) ? err.message : 'Permission denied or no camera available.';
        setStatus('Camera unavailable: ' + msg + ' Use the file upload option below.');
        stopStream();
      });
    });

    // ---- Capture ----------------------------------------------------------

    captureBtn.addEventListener('click', function () {
      var w = video.videoWidth;
      var h = video.videoHeight;
      if (!w || !h) {
        setStatus('Camera is not ready yet — try again in a moment.');
        return;
      }
      captureCanvas.width = w;
      captureCanvas.height = h;
      var ctx = captureCanvas.getContext('2d');
      ctx.drawImage(video, 0, 0, w, h);
      captureCanvas.toBlob(function (blob) {
        if (!blob) {
          setStatus('Capture failed — please try again or use file upload.');
          return;
        }
        try {
          var file = new File([blob], 'selfie.jpg', { type: 'image/jpeg' });
          var dt = new DataTransfer();
          dt.items.add(file);
          fileInput.files = dt.files;
          notifyChange();
        } catch (_) {
          setStatus('Your browser blocks this capture method — use file upload.');
          return;
        }
        revokePreview();
        preview.src = URL.createObjectURL(blob);
        preview.hidden = false;
        video.hidden = true;
        stopStream();
        captureBtn.hidden = true;
        retakeBtn.hidden = false;
        retakeBtn.focus();
        setStatus('Photo captured.');
      }, 'image/jpeg', 0.92);
    });

    // ---- Retake -----------------------------------------------------------

    retakeBtn.addEventListener('click', function () {
      try { fileInput.value = ''; } catch (_) { /* ignore */ }
      notifyChange();
      revokePreview();
      preview.hidden = true;
      video.hidden = false;
      retakeBtn.hidden = true;
      startBtn.hidden = false;
      startBtn.focus();
      setStatus('');
    });

    // ---- Fallback toggle --------------------------------------------------

    var fallbackOpen = false;
    fallbackToggle.addEventListener('click', function () {
      fallbackOpen = !fallbackOpen;
      if (fallbackOpen) {
        stopStream();
        revokePreview();
        try { fileInput.value = ''; } catch (_) { /* ignore */ }
        notifyChange();
        preview.hidden = true;
        video.hidden = false;
        startBtn.hidden = false;
        captureBtn.hidden = true;
        retakeBtn.hidden = true;
        setStatus('');
        fileInput.classList.remove('media-fallback-hidden');
        stage.hidden = true;
        toolbar.hidden = true;
        fallbackToggle.textContent = 'Use the camera instead';
        fileInput.focus();
      } else {
        fileInput.classList.add('media-fallback-hidden');
        try { fileInput.value = ''; } catch (_) { /* ignore */ }
        notifyChange();
        stage.hidden = false;
        toolbar.hidden = false;
        fallbackToggle.textContent = 'Upload a photo file instead';
      }
    });

    // Stop the camera if the user navigates away mid-flow.
    window.addEventListener('pagehide', stopStream);
  }

  function initAll() {
    var caps = document.querySelectorAll('[data-selfie-cap]');
    for (var i = 0; i < caps.length; i++) init(caps[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
}());
