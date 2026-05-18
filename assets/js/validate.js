/* ============================================================================
   FEU LFMS — Client-side form validator
   Mirrors lib/validate.php. UX layer only — never the gate. Server validates.
   No dependencies. Vanilla JS, IIFE-scoped.

   Activation:
     <form data-validate>
       <input data-rule="required|email|max:255" name="email" id="email">
       ...
     </form>

   Supported rules (pipe-separated, same as PHP):
     required, email, min:N, max:N, regex:/.../i, enum:a,b,c,
     date, integer, confirmed (matches <name>_confirm field)
   ============================================================================ */
(function () {
  'use strict';

  // ----- Rule implementations. Each returns null when valid, or an error message.

  const RULES = {
    email: function (v) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) ? null : 'Must be a valid email address.';
    },
    min: function (v, n) {
      return v.length >= +n ? null : 'Must be at least ' + n + ' characters.';
    },
    max: function (v, n) {
      return v.length <= +n ? null : 'Must be at most ' + n + ' characters.';
    },
    regex: function (v, pattern) {
      try {
        // Pattern may include flags after the trailing slash (/abc/i)
        const m = pattern.match(/^\/(.+)\/([a-z]*)$/);
        const re = m ? new RegExp(m[1], m[2]) : new RegExp(pattern);
        return re.test(v) ? null : 'Invalid format.';
      } catch (e) {
        return null; // bad regex — let the server reject
      }
    },
    enum: function (v, csv) {
      return csv.split(',').indexOf(v) !== -1 ? null : 'Invalid value.';
    },
    date: function (v) {
      return !isNaN(Date.parse(v)) ? null : 'Must be a valid date.';
    },
    integer: function (v) {
      return /^\d+$/.test(v) ? null : 'Must be a whole number.';
    }
  };

  function validateField(input, allInputs) {
    const rules = (input.dataset.rule || '').split('|').filter(Boolean);
    const value = (input.value || '').trim();
    const isRequired = rules.indexOf('required') !== -1;

    if (!isRequired && value === '') return null;
    if (isRequired && value === '')  return 'This field is required.';

    for (let i = 0; i < rules.length; i++) {
      const rule = rules[i];
      if (rule === 'required') continue;

      const idx = rule.indexOf(':');
      const name = idx === -1 ? rule : rule.slice(0, idx);
      const arg  = idx === -1 ? null : rule.slice(idx + 1);

      if (name === 'confirmed') {
        const confirmName = input.name + '_confirm';
        const confirmInput = allInputs.find(function (i) { return i.name === confirmName; });
        if (confirmInput && confirmInput.value !== value) {
          return 'Confirmation does not match.';
        }
        continue;
      }

      const check = RULES[name];
      if (check) {
        const err = check(value, arg);
        if (err) return err;
      }
    }
    return null;
  }

  function setFieldError(input, message) {
    const field = input.closest('.field');
    if (!field) return;
    if (message) {
      field.classList.add('field-error');
      let err = field.querySelector('.field-error-text');
      if (!err) {
        err = document.createElement('p');
        err.className = 'field-error-text';
        field.appendChild(err);
      }
      err.textContent = message;
      input.setAttribute('aria-invalid', 'true');
    } else {
      field.classList.remove('field-error');
      const err = field.querySelector('.field-error-text');
      if (err) err.remove();
      input.removeAttribute('aria-invalid');
    }
  }

  function attachForm(form) {
    const inputs = Array.from(form.querySelectorAll('[data-rule]'));

    inputs.forEach(function (input) {
      // Validate on blur — only after the user has typed at least once.
      input.addEventListener('blur', function () {
        if ((input.value || '').trim() === '' && !input.dataset.touched) return;
        input.dataset.touched = '1';
        setFieldError(input, validateField(input, inputs));
      });
      // Clear error on edit so the user gets immediate positive feedback while fixing.
      input.addEventListener('input', function () {
        const field = input.closest('.field');
        if (field && field.classList.contains('field-error')) {
          setFieldError(input, null);
        }
      });
    });

    form.addEventListener('submit', function (e) {
      let hasError = false;
      let firstBad = null;
      inputs.forEach(function (input) {
        const err = validateField(input, inputs);
        setFieldError(input, err);
        if (err) {
          hasError = true;
          if (!firstBad) firstBad = input;
        }
      });
      if (hasError) {
        e.preventDefault();
        if (firstBad) firstBad.focus();
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-validate]').forEach(attachForm);
  });
})();
