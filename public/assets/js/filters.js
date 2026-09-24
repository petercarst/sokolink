/**
 * Catalogue filter conveniences.
 *
 * The filter panel is a plain GET form that already works without JavaScript:
 * you change a value, press "Apply filters", the page reloads with the results.
 * Everything here only removes a click. If this file fails to load, filtering
 * still works.
 */

/** A select marked data-auto-submit submits its form on change. */
function initAutoSubmitSelects() {
  document.querySelectorAll('select[data-auto-submit]').forEach((select) => {
    select.addEventListener('change', () => {
      select.form?.requestSubmit();
    });
  });
}

/**
 * Radio and checkbox filters submit on change; text and number inputs wait
 * until the user stops typing, so a price range does not reload the page on
 * every keystroke.
 */
function initFilterForms() {
  document.querySelectorAll('form[data-filter-form]').forEach((form) => {
    form.querySelectorAll('input[type="radio"], input[type="checkbox"], select').forEach((field) => {
      field.addEventListener('change', () => form.requestSubmit());
    });

    let debounce;
    form.querySelectorAll('input[type="number"], input[type="text"]').forEach((field) => {
      field.addEventListener('input', () => {
        window.clearTimeout(debounce);
        debounce = window.setTimeout(() => form.requestSubmit(), 700);
      });
    });

    // With auto-submit active the button is redundant, but it stays in the DOM
    // for keyboard users who prefer an explicit action and for the no-JS path.
    const note = form.querySelector('[data-filter-autonote]');
    if (note) note.hidden = false;
  });
}

export function initFilters() {
  initAutoSubmitSelects();
  initFilterForms();
}
