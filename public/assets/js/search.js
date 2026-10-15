/**
 * Live search - a dropdown of matching products under the search box,
 * updated as the customer types.
 *
 * Progressive enhancement: the box is still a plain GET form, so Enter (or no
 * JavaScript at all) goes to the full results page exactly as before.
 * Results are built with textContent, never innerHTML, because product names
 * come from sellers.
 */

const MIN_CHARS = 2;
const DELAY_MS = 200;

function attach(input) {
  const form = input.closest('form');
  const url = input.dataset.liveSearch;
  if (!form || !url) return;

  form.classList.add('sl-live-search');

  const list = document.createElement('div');
  list.className = 'sl-live-results';
  list.id = `${input.id}-results`;
  list.setAttribute('role', 'listbox');
  list.hidden = true;
  form.appendChild(list);

  input.setAttribute('autocomplete', 'off');
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-controls', list.id);
  input.setAttribute('aria-expanded', 'false');

  let timer = 0;
  let controller = null;
  let active = -1;

  const options = () => Array.from(list.querySelectorAll('.sl-live-item'));

  const close = () => {
    list.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    active = -1;
  };

  const open = () => {
    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  };

  const highlight = (index) => {
    const items = options();
    if (items.length === 0) return;
    active = (index + items.length) % items.length;
    items.forEach((item, i) => item.classList.toggle('is-active', i === active));
    items[active].scrollIntoView({ block: 'nearest' });
  };

  const message = (text) => {
    const p = document.createElement('p');
    p.className = 'sl-live-empty';
    p.textContent = text;
    list.replaceChildren(p);
    open();
  };

  const render = (data) => {
    if (data.items.length === 0) {
      message(`No products match "${data.query}"`);
      return;
    }

    const rows = data.items.map((item) => {
      const a = document.createElement('a');
      a.className = 'sl-live-item';
      a.href = item.url;
      a.setAttribute('role', 'option');

      const text = document.createElement('span');
      text.className = 'sl-live-text';
      const name = document.createElement('span');
      name.className = 'sl-live-name';
      name.textContent = item.name;
      const seller = document.createElement('span');
      seller.className = 'sl-live-seller';
      seller.textContent = item.seller;
      text.append(name, seller);

      const price = document.createElement('span');
      price.className = 'sl-live-price';
      price.textContent = item.price;

      a.append(text, price);
      return a;
    });

    const all = document.createElement('a');
    all.className = 'sl-live-item sl-live-all';
    all.href = data.all_url;
    all.setAttribute('role', 'option');
    all.textContent = `See all ${data.total} result${data.total === 1 ? '' : 's'}`;

    list.replaceChildren(...rows, all);
    active = -1;
    open();
  };

  const lookup = async (query) => {
    controller?.abort();
    controller = new AbortController();

    try {
      const response = await fetch(`${url}?q=${encodeURIComponent(query)}`, {
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      });
      if (!response.ok) throw new Error(String(response.status));
      const data = await response.json();
      // A slower, older response must not overwrite what the box now says.
      if (data.query === input.value.trim()) render(data);
    } catch (error) {
      if (error.name !== 'AbortError') message('Search is unavailable right now. Press Enter to search.');
    }
  };

  input.addEventListener('input', () => {
    window.clearTimeout(timer);
    const query = input.value.trim();

    if (query.length < MIN_CHARS) {
      controller?.abort();
      close();
      return;
    }

    timer = window.setTimeout(() => lookup(query), DELAY_MS);
  });

  input.addEventListener('keydown', (event) => {
    if (list.hidden) return;

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      highlight(active + 1);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      highlight(active - 1);
    } else if (event.key === 'Enter' && active >= 0) {
      event.preventDefault();
      options()[active].click();
    } else if (event.key === 'Escape') {
      close();
    }
  });

  input.addEventListener('focus', () => {
    if (list.childElementCount > 0 && input.value.trim().length >= MIN_CHARS) open();
  });

  document.addEventListener('click', (event) => {
    if (!form.contains(event.target)) close();
  });
}

export function initLiveSearch() {
  document.querySelectorAll('input[data-live-search]').forEach(attach);
}
