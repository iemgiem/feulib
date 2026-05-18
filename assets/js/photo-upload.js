/* ============================================================================
   FEU LFMS — Photo upload component
   Click-to-browse + drag/drop + thumbnail preview + client-side validation.

   Markup:
     <div class="photo-upload" data-max-bytes="4194304">
       <div class="photo-upload-zone" tabindex="0">
         <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
         <p class="photo-upload-prompt">...</p>
       </div>
       <div class="photo-upload-preview" hidden>
         <img alt="Preview">
         <button type="button" class="photo-upload-remove btn-link">Remove</button>
       </div>
       <p class="field-error-text" hidden></p>
     </div>

   Client-side validation matches lib/upload.php — same MIME whitelist,
   same size cap. The server still re-validates everything.
   ============================================================================ */
(function () {
  'use strict';

  var ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

  function attach(root) {
    var input   = root.querySelector('input[type="file"]');
    var zone    = root.querySelector('.photo-upload-zone');
    var preview = root.querySelector('.photo-upload-preview');
    var img     = preview ? preview.querySelector('img') : null;
    var remove  = root.querySelector('.photo-upload-remove');
    var error   = root.querySelector('.field-error-text');
    var maxBytes = parseInt(root.dataset.maxBytes || '4194304', 10);

    if (!input || !zone || !preview || !img) return;

    function showError(msg) {
      if (!error) return;
      error.textContent = msg;
      error.hidden = false;
    }

    function clearError() {
      if (!error) return;
      error.textContent = '';
      error.hidden = true;
    }

    function showPreview(file) {
      var reader = new FileReader();
      reader.onload = function (e) {
        img.src = e.target.result;
        preview.hidden = false;
        zone.hidden = true;
      };
      reader.readAsDataURL(file);
    }

    function reset() {
      input.value = '';
      preview.hidden = true;
      img.src = '';
      zone.hidden = false;
      clearError();
    }

    function handleFile(file) {
      if (ALLOWED.indexOf(file.type) === -1) {
        showError('Only JPEG, PNG, or WebP images are allowed.');
        input.value = '';
        return;
      }
      if (file.size > maxBytes) {
        var mb = (maxBytes / 1024 / 1024).toFixed(1);
        showError('File is too large. Maximum size is ' + mb + ' MB.');
        input.value = '';
        return;
      }
      clearError();
      showPreview(file);
    }

    // Click to browse
    zone.addEventListener('click', function () { input.click(); });
    zone.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        input.click();
      }
    });

    // File picker change
    input.addEventListener('change', function () {
      if (input.files && input.files[0]) {
        handleFile(input.files[0]);
      }
    });

    // Drag-and-drop
    zone.addEventListener('dragover', function (e) {
      e.preventDefault();
      zone.classList.add('dragover');
    });
    zone.addEventListener('dragleave', function () {
      zone.classList.remove('dragover');
    });
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      zone.classList.remove('dragover');
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
        // Assign to the input so the file is submitted with the form
        try { input.files = e.dataTransfer.files; } catch (err) { /* older Firefox: ignore */ }
        handleFile(e.dataTransfer.files[0]);
      }
    });

    // Remove
    if (remove) {
      remove.addEventListener('click', function (e) {
        e.preventDefault();
        reset();
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.photo-upload').forEach(attach);
  });
})();
