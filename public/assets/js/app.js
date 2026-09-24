/**
 * SokoLink - application entry point.
 *
 * Vanilla ES modules, no build step, no framework. Everything here is
 * progressive enhancement: every page works with JavaScript disabled, and this
 * only makes it pleasanter.
 */

import { initCart } from './cart.js';
import { initFilters } from './filters.js';

/** Prevents a double-click on a submit button creating two of something.
 *  This is a courtesy only - the real protection is the server-side
 *  idempotency key plus POST-redirect-GET (FR-CART-09). */
function initSubmitGuard() {
  document.querySelectorAll('form[method="post"]').forEach((form) => {
    form.addEventListener('submit', () => {
      const buttons = form.querySelectorAll('button[type="submit"]');

      // Deferred so the button's value is still included in the submission.
      window.setTimeout(() => {
        buttons.forEach((button) => {
          button.disabled = true;
          button.setAttribute('aria-busy', 'true');
        });
      }, 0);

      // Re-enable if the user comes back via the back button, otherwise the
      // form looks permanently broken.
      window.addEventListener('pageshow', () => {
        buttons.forEach((button) => {
          button.disabled = false;
          button.removeAttribute('aria-busy');
        });
      }, { once: true });
    });
  });
}

/** Show/hide password, with the state announced rather than implied by an icon. */
function initPasswordToggles() {
  document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = document.getElementById(button.dataset.passwordToggle);
    if (!input) return;

    button.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.setAttribute('aria-pressed', String(show));
      button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });
}

/** Live character counter for textareas that have a maxlength. */
function initCharCounters() {
  document.querySelectorAll('textarea[maxlength][data-counter]').forEach((textarea) => {
    const output = document.getElementById(textarea.dataset.counter);
    if (!output) return;

    const update = () => {
      const left = textarea.maxLength - textarea.value.length;
      output.textContent = `${left} characters remaining`;
    };

    textarea.addEventListener('input', update);
    update();
  });
}

/**
 * Client-side validation feedback that MIRRORS the planned server rules.
 *
 * It never replaces them. The server validates everything again, because
 * anything the browser checks can be bypassed in about four seconds
 * (NFR-SEC-05, rule 9 of the general development rules).
 */
function initValidationFeedback() {
  document.querySelectorAll('form[data-validate]').forEach((form) => {
    form.setAttribute('novalidate', 'novalidate');

    form.addEventListener('submit', (event) => {
      let firstInvalid = null;

      form.querySelectorAll('input, select, textarea').forEach((field) => {
        const valid = field.checkValidity();
        field.classList.toggle('is-invalid', !valid);

        if (valid) {
          field.removeAttribute('aria-invalid');
        } else {
          field.setAttribute('aria-invalid', 'true');
          if (!firstInvalid) firstInvalid = field;
        }
      });

      if (firstInvalid) {
        event.preventDefault();
        firstInvalid.focus();
        firstInvalid.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
    });
  });
}

/** Selectable option cards keep their visual state in sync with the radio. */
function initOptionCards() {
  document.querySelectorAll('.sl-option input[type="radio"]').forEach((radio) => {
    radio.addEventListener('change', () => {
      document
        .querySelectorAll(`.sl-option input[name="${CSS.escape(radio.name)}"]`)
        .forEach((sibling) => {
          sibling.closest('.sl-option')?.classList.toggle('is-selected', sibling.checked);
        });
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initSubmitGuard();
  initPasswordToggles();
  initCharCounters();
  initValidationFeedback();
  initOptionCards();
  initCart();
  initFilters();
});
