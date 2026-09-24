/**
 * Quantity steppers and the basket total preview.
 *
 * IMPORTANT - this is a DISPLAY PREVIEW ONLY.
 *
 * Nothing calculated here is ever sent to the server as an amount. The server
 * recomputes every subtotal, delivery fee and total from database prices at
 * checkout and discards whatever the browser thought (FR-CART-03, FR-CART-08).
 * This exists so the number under the customer's thumb changes the instant they
 * tap plus, which is a real usability win and not a security decision.
 *
 * It also submits the two basket forms on change, so choosing collection or a
 * different store does not need a separate button press. Those submissions go
 * to the server and come back re-priced; the preview above is only ever what
 * fills the gap while that happens.
 */

/** Currency formatting has to match Money::format() in PHP.
 *  TZS shows no decimals even though amounts are stored as DECIMAL(12,2). */
const ZERO_DECIMAL = ['TZS', 'UGX', 'RWF', 'KMF', 'JPY'];

function currencyConfig() {
  const root = document.documentElement;
  return {
    code: root.dataset.currency || 'TZS',
    symbol: root.dataset.currencySymbol || 'TSh',
  };
}

function formatMoney(value) {
  const { code, symbol } = currencyConfig();
  const decimals = ZERO_DECIMAL.includes(code) ? 0 : 2;

  const formatted = value.toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });

  return `${symbol} ${formatted}`;
}

function recalculateTotals() {
  const lines = document.querySelectorAll('[data-cart-line]');
  if (lines.length === 0) return;

  let itemsSubtotal = 0;
  lines.forEach((line) => {
    itemsSubtotal += parseFloat(line.dataset.lineTotal || '0');
  });

  const itemsEl = document.querySelector('[data-summary-items]');
  const totalEl = document.querySelector('[data-summary-total]');

  if (itemsEl) itemsEl.textContent = formatMoney(itemsSubtotal);

  if (totalEl) {
    // Delivery is not recomputed in the browser: it depends on zone rules the
    // client has no business knowing. The server owns it.
    const delivery = parseFloat(totalEl.dataset.delivery || '0');
    totalEl.textContent = formatMoney(itemsSubtotal + delivery);
  }
}

function initStepper(stepper) {
  const input = stepper.querySelector('[data-qty-input]');
  const decrease = stepper.querySelector('[data-qty-dec]');
  const increase = stepper.querySelector('[data-qty-inc]');
  const unitPrice = parseFloat(stepper.dataset.unitPrice || '0');

  if (!input) return;

  const line = stepper.closest('[data-cart-line]');
  const lineDisplay = line?.querySelector('[data-line-display]');

  const clamp = (value) => {
    const min = parseInt(input.min || '1', 10);
    const max = parseInt(input.max || '99', 10);
    return Math.max(min, Math.min(max, Number.isNaN(value) ? min : value));
  };

  const sync = () => {
    const qty = clamp(parseInt(input.value, 10));
    input.value = String(qty);

    decrease?.toggleAttribute('disabled', qty <= parseInt(input.min || '1', 10));
    increase?.toggleAttribute('disabled', qty >= parseInt(input.max || '99', 10));

    if (line && lineDisplay) {
      const lineTotal = qty * unitPrice;
      line.dataset.lineTotal = String(lineTotal);
      lineDisplay.textContent = formatMoney(lineTotal);
      recalculateTotals();
    }
  };

  decrease?.addEventListener('click', () => {
    input.value = String(clamp(parseInt(input.value, 10) - 1));
    sync();
  });

  increase?.addEventListener('click', () => {
    input.value = String(clamp(parseInt(input.value, 10) + 1));
    sync();
  });

  input.addEventListener('change', sync);
  sync();
}

/**
 * Submits the form a control belongs to as soon as it changes.
 *
 * Progressive enhancement, not a requirement: every one of these controls sits
 * in a real form with a real submit button, and the page works without any of
 * this. The button is hidden only once the script has confirmed it can do the
 * job instead - so a browser that never runs the script still shows it.
 */
function initAutoSubmit() {
  document.querySelectorAll('[data-cart-autosubmit]').forEach((control) => {
    control.addEventListener('change', () => {
      control.form?.requestSubmit();
    });
  });

  document.querySelectorAll('[data-cart-fallback]').forEach((button) => {
    button.hidden = true;
  });
}

/**
 * Stops a second submission of a form that creates something.
 *
 * The server is already idempotent - checkout carries a key and a replay
 * returns the first order rather than making another. This is about the
 * customer, not the data: a button that visibly stops responding is how they
 * know the first click worked.
 */
function initSubmitOnce() {
  document.querySelectorAll('form[data-submit-once]').forEach((form) => {
    form.addEventListener('submit', () => {
      form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = true;
        button.dataset.busyLabel = button.textContent.trim();
        button.textContent = 'Working...';
      });
    });
  });
}

export function initCart() {
  document.querySelectorAll('[data-qty-stepper]').forEach(initStepper);
  initAutoSubmit();
  initSubmitOnce();
}
