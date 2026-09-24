# Design System

**Status:** Phase 0 draft, awaiting approval
**Source:** `docs/reference/DESIGN.source.md` — the getdesign.md "Shopify" analysis you supplied
(`npx getdesign@latest add shopify`, from VoltAgent/awesome-design-md). Independent analysis of
publicly observable patterns; not affiliated with or endorsed by Shopify.
**Last updated:** 2026-09-21

---

## 1. Why this reference fits

The reference is not a generic palette — it is a **two-track commerce design system**, and this
project is a two-track commerce application. The mapping is direct:

| Reference track | Our surface | Character |
|---|---|---|
| **Cinematic** — near-black canvas, full-bleed photography, monumental thin display type, one CTA per band | **Public marketplace**: home, category, product, store profile, about, auth | Editorial, aspirational, product photography carries the page |
| **Transactional** — white / cream canvas, aloe and pistachio accents, dense pill vocabulary, Inter body | **All six dashboards** and cart/checkout | Dense, scannable, built for people doing a job |

The reference's own rule — *"when designing a new page, choose cinematic OR transactional, not both"* —
becomes our layout rule: `layouts/public.php` is cinematic, `layouts/dashboard.php` is transactional.
A page picks one layout. There is no third option and no blending.

**Checkout is deliberately transactional**, even though it is reached from the public site. Someone
entering an address and confirming a total needs density and clarity, not 96px display type.

---

## 2. Tokens

Implemented once in `public/assets/css/design-tokens.css` as CSS custom properties, then consumed by
Bootstrap variable overrides, the Tailwind config, and component CSS. Tokens are never hard-coded as
hex values in a template.

### 2.1 Colour

```css
:root {
  /* Ink and inverse */
  --c-ink:              #000000;
  --c-on-dark:          #ffffff;
  --c-primary:          #000000;
  --c-on-primary:       #ffffff;

  /* Canvases */
  --c-canvas-night:     #000000;
  --c-canvas-night-el:  #0a0a0a;
  --c-surface-dark-el:  #1e2c31;
  --c-canvas-light:     #ffffff;
  --c-canvas-cream:     #fbfbf5;

  /* Accents - LIGHT TRACK ONLY */
  --c-aloe-10:          #c1fbd4;
  --c-pistachio-10:     #d4f9e0;

  /* Shade ladder */
  --c-shade-30:         #d4d4d8;
  --c-shade-40:         #a1a1aa;
  --c-shade-50:         #71717a;
  --c-shade-60:         #52525b;
  --c-shade-70:         #3f3f46;

  /* Hairlines */
  --c-hairline-light:   #e4e4e7;
  --c-hairline-dark:    #1e2c31;

  /* Muted links on dark */
  --c-link-cool-1:      #9dabad;
  --c-link-cool-2:      #9797a2;
  --c-link-cool-3:      #bdbdca;
  --c-link-mint:        #99b3ad;
}
```

### 2.2 Typography

```css
:root {
  --f-display: "Inter Display", "Neue Haas Grotesk Display", Helvetica, Arial, sans-serif;
  --f-body:    "Inter Variable", Inter, Helvetica, Arial, sans-serif;
  --f-mono:    ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
html { font-feature-settings: "ss03"; }
```

| Token | Size | Weight | Line height | Tracking | Use |
|---|---|---|---|---|---|
| `display-xxl` | 96px | 330 | 1.0 | +2.4px | Cinematic hero headline |
| `display-xl` | 70px | 330 | 1.0 | 0 | Section opener, cinematic |
| `display-lg` | 55px | 330 | 1.16 | 0 | Page title, light track |
| `display-md` | 48px | 330 | 1.14 | 0 | Sub-section headline, price on a card |
| `heading-xl` | 28px | 500 | 1.28 | 0.42px | Card title, dashboard page title |
| `heading-lg` | 24px | 400 | 1.14 | 0.36px | Compact card title |
| `heading-md` | 20px | 500 | 1.4 | 0.3px | Section sub-heading |
| `heading-sm` | 18px | 500 | 1.25 | 0.72px | Eyebrow, mini-section label |
| `body-lg` | 18px | 550 | 1.56 | 0 | Marketing lead |
| `body-md` | 16px | 420 | 1.5 | 0 | **Default UI body**, pill-button labels |
| `body-strong` | 16px | 550 | 1.5 | 0 | Emphasised run |
| `caption` | 14px | 500 | 1.49 | 0.28px | Helper copy, table meta |
| `micro` | 13px | 500 | 1.5 | -0.13px | Fine print, timestamps |
| `eyebrow-cap` | 12px | 400 | 1.2 | 0.72px | All-caps eyebrow, badge text |
| `code` | 16px | 400 | 1.5 | 0 | Order numbers, collection codes, SKUs |

**Font substitution.** Neue Haas Grotesk Display is proprietary. The reference itself names
**Inter Display at light weights** as the open substitute, so the display tier uses Inter Display
(self-hosted variable woff2) and the body tier uses Inter Variable — both open-source. Self-hosting
keeps the app working offline on XAMPP and avoids a third-party request on every page load. The
`ss03` stylistic set is applied globally, as the reference requires.

Responsive display stair, per the reference: **96 → 70 → 55 → 48 → 36px** at mobile.

### 2.3 Spacing, radius, elevation

```css
:root {
  --s-xxs: 2px;  --s-xs: 4px;   --s-sm: 8px;   --s-md: 12px;
  --s-lg: 16px;  --s-xl: 24px;  --s-xxl: 32px; --s-huge: 64px;

  --r-xs: 4px; --r-sm: 5px; --r-md: 8px; --r-lg: 12px; --r-xl: 20px; --r-pill: 9999px;

  --e-1: 0 1px 2px rgba(255,255,255,.05), inset 0 1px 0 rgba(255,255,255,.04);
  --e-2: 0 0 0 1px rgba(255,255,255,.08), 0 1px 3px rgba(0,0,0,.3), 0 5px 10px rgba(0,0,0,.2);
  --e-3: 0 8px 8px rgba(0,0,0,.1), 0 4px 4px rgba(0,0,0,.1),
         0 2px 2px rgba(0,0,0,.1), 0 0 0 1px rgba(0,0,0,.1);
  --e-4: 0 25px 50px -12px rgba(0,0,0,.25);
}
```

Base unit 8px. Cinematic sections get 64–128px of vertical air; transactional sections tighten to
48px because users are scanning and acting, not reading an editorial spread. Elevation 1 and 2 are
dark-track only; 3 and 4 are light-track only.

---

## 3. Track rules

### 3.1 Cinematic (public marketplace)

- Canvas `--c-canvas-night`; cards `--c-canvas-night-el`; text `--c-on-dark`.
- Display type at weight **330**, never heavier. Thinness is the brand.
- Product photography is **full-bleed and escapes the container**. No scrim, no text over the image —
  type sits in clean negative space above or below it.
- One primary action per band: `button-outline-on-dark` (2px white stroke, pill).
- **No aloe, no pistachio.** Greens belong to the light track only.
- Shadows: nothing beyond the Elevation-1 inset top-edge sheen. The cinematic track wants flat black.

### 3.2 Transactional (dashboards, cart, checkout)

- Canvas `--c-canvas-cream` for the page, `--c-canvas-light` for cards.
- Display type reserved for page titles and prices; everything else is Inter.
- `button-primary-pill` (solid black) for primary actions, `button-outline-on-light` for secondary,
  `button-aloe-pill` for the single most affirmative action on a screen (Place order, Mark ready,
  Confirm delivery, Approve seller).
- Elevation 3 — the stacked tiny-shadow halo — on cards. It reads as paper, not as a drop shadow.
- Aloe and pistachio are **surface fills only, never text colours**.

---

## 4. Component mapping

The reference covers a marketing site. A marketplace needs more. Here is how every component we need
maps onto its vocabulary.

| Our component | Built from | Notes |
|---|---|---|
| Public nav | `nav-bar-dark` | Logo left, search centre, cart + account pills right; hamburger below 768px |
| Dashboard nav | `nav-bar-light` | Bootstrap navbar + offcanvas sidebar, cream canvas |
| Hero | `card-photo-frame` + `display-xxl` | Full-bleed merchant photography, one CTA |
| Product card (public) | `card-feature-cinematic` | Image is the content; name in `heading-lg`, price in `body-strong` |
| Product card (dashboard) | `card-pricing` geometry | `--r-lg`, hairline border, Elevation 3 |
| Cart / order summary | `card-pricing` | Totals right-aligned, `display-md` for the grand total |
| Featured / recommended tile | `card-pricing-featured` | Aloe fill, the reference's featured pattern |
| Category band | `card-pistachio-band` | Wide pistachio band, light track only |
| Buttons | the four pill variants | **Pill shape is non-negotiable.** New variants change fill, never shape |
| Form inputs | `text-input` | 44px minimum height at every breakpoint |
| Tags / chips | `pill-tag-mint`, `pill-tag-shade` | Category chips, filter chips |
| **Status badge** | extends `pill-tag-*` | See §5 — this is the gap in the reference |
| Data table | Bootstrap table + hairline dividers | `--c-hairline-light` rules, `caption` for meta, no zebra striping |
| Stat tile | `card-pricing` + `display-md` | Dashboard KPIs; number in display, label in `eyebrow-cap` |
| Timeline (order history) | hairline rail + `pill-tag-*` nodes | Vertical rail in `--c-shade-30` |
| Empty state | `card-pricing` centred | Icon, `heading-md`, one `button-primary-pill` |
| Loading skeleton | `--c-shade-30` blocks on light, `--c-canvas-night-el` on dark | Matches the real layout's shape |
| Modal | Bootstrap modal, Elevation 4 | Pill buttons in the footer |
| Footer | `footer-dark` / `footer-light` | Per track; muted cool-tone links on dark |
| Email template | light track only | Emails have no dark canvas — deliverability and client support |

---

## 5. Required extensions — a genuine gap in the reference

The reference is a marketing design system. It has **no semantic status colours**, and it explicitly
forbids a third canvas colour. But a marketplace has to show at a glance that an order was *rejected*
versus *delivered*, and "the only accents are two mints" cannot carry that.

**DS-EXT-01 — Semantic status palette.** I propose a minimal set, used **only** for status badges,
validation feedback and alerts, never as a canvas and never on the cinematic track:

```css
:root {
  --c-status-success-bg: #c1fbd4;  /* aloe-10, reused - no new hue */
  --c-status-success-fg: #0b3d20;
  --c-status-info-bg:    #d4f9e0;  /* pistachio-10, reused */
  --c-status-info-fg:    #10352a;
  --c-status-warn-bg:    #fdf0c4;  /* new - amber, minimum viable addition */
  --c-status-warn-fg:    #4a3508;
  --c-status-danger-bg:  #fbd5d5;  /* new - red, required for rejection/failure */
  --c-status-danger-fg:  #5a1414;
  --c-status-neutral-bg: #d4d4d8;  /* shade-30 */
  --c-status-neutral-fg: #3f3f46;  /* shade-70 */
}
```

Two new hues, both desaturated to sit beside the mints without shouting. Every pairing meets 4.5:1.
**This is a deliberate deviation from the reference and needs your explicit approval (OQ-06a).**
The alternative — signalling a failed delivery with only shape and text — is worse for users and
weaker for accessibility.

**Status-to-colour map:**

| Status group | Token |
|---|---|
| `collected`, `delivered`, `completed`, `paid`, `approved`, `published` | success |
| `preparing`, `ready_for_pickup`, `ready_for_dispatch`, `out_for_delivery`, `processing` | info |
| `pending_payment`, `awaiting_seller`, `collection_overdue`, `waiting_customer`, `pending_approval` | warn |
| `rejected_seller`, `cancelled_customer`, `delivery_failed`, `returned_to_seller`, `expired_unpaid`, `suspended` | danger |
| `draft`, `archived`, `closed`, `refunded` | neutral |

**DS-EXT-02 — Status is never colour alone.** Every badge carries a text label and a small glyph.
Colour is reinforcement, never the only signal (**NFR-USA-02**).

---

## 6. Bootstrap + Tailwind strategy

| Layer | Owns | Loaded |
|---|---|---|
| `bootstrap.min.css` | Grid, navbar, offcanvas, modal, dropdown, tabs, form controls, tables | 1st |
| `design-tokens.css` | Custom properties + Bootstrap `--bs-*` overrides (colours, radii, fonts) | 2nd |
| `app.css` | Component classes: `.sl-btn`, `.sl-card-cinematic`, `.sl-badge`, track classes | 3rd |
| `tailwind.build.css` | Utilities only, `prefix: 'tw-'`, `preflight: false` | **4th — last** |

**Utilities load last, and that ordering is load-bearing.** Tailwind utilities and
our `.sl-*` component classes are both single-class selectors, so specificity ties
and source order decides the winner. An earlier build had `app.css` last, which
meant `.sl-btn { display: inline-flex }` beat `.tw-hidden` — buttons marked
`tw-hidden sm:tw-inline-flex` stayed visible at 360px and pushed every page 150px
wider than the viewport. The responsive audit caught it; the fix was the cascade
order, not a pile of `!important`.

```js
// tailwind.config.js
module.exports = {
  prefix: 'tw-',
  corePlugins: { preflight: false },   // critical: do not fight Bootstrap Reboot
  content: ['./app/Views/**/*.php', './public/assets/js/**/*.js'],
  theme: { extend: { colors: { /* mapped to the CSS custom properties */ } } },
};
```

**The rule for developers:** one system per component. `class="btn btn-primary tw-bg-black"` is a
defect, not a style choice. Bootstrap styles the component; Tailwind adjusts spacing and one-off
layout around it; `app.css` holds anything reused more than twice.

`preflight: false` is the single most important setting here. Tailwind's reset would otherwise
override Bootstrap's Reboot and silently break button, form and table rendering in ways that look
like random CSS bugs.

See **OQ-07**: CLI build (smaller, needs Node once, output committed) versus CDN Play (no build step,
heavier, not for production). Recommendation is the CLI build.

---

## 7. Accessibility adaptations

The reference is a marketing aesthetic. Three of its choices need adjusting for an application people
use to spend money, and I am flagging each rather than applying it silently.

| Reference choice | Problem | Adaptation |
|---|---|---|
| Display weight **330** | At 28px and below, thin weights on black lose legibility, badly on low-quality screens and for low-vision users | Keep 330 at 48px and above. Below 48px, minimum weight 400. Body never goes below 420 |
| `shade-40` `#a1a1aa` as tertiary text on light | ~2.6:1 against white — fails WCAG AA for body text | On light, tertiary text uses `shade-50` (~4.8:1). `shade-40` is decorative or dark-track only |
| Muted cool-tone footer links on dark | `--c-link-cool-2` sits near the AA boundary | Keep the colours, add a persistent underline (the reference already asks for this) and lift hover to full white |
| Pure black on pure white | Maximum contrast can cause halation for some readers | Body text on the light track uses `#0a0a0a` at `body-md`; headings stay pure ink |
| No focus treatment specified | Keyboard users cannot see where they are | 2px offset focus ring: white on the dark track, ink on the light track. Never `outline: none` |
| `prefers-reduced-motion` | Not addressed | All transitions capped at 200ms and disabled under `prefers-reduced-motion: reduce` |

Touch targets follow the reference: pill buttons and form fields stay at least 44x44px at every
breakpoint, which it already meets through 12px vertical padding.

---

## 8. Responsive behaviour

| Name | Width | Changes |
|---|---|---|
| Wide | ≥1440px | Full cinematic hero, edge-bleeding photography; 4-up product grid; dashboard sidebar pinned |
| Desktop | 1024–1439px | Default container; 3-up grid; sidebar pinned |
| Tablet | 768–1023px | 2-up grid; photography crops; sidebar becomes an offcanvas |
| Mobile | <768px | 1-up; hamburger nav inheriting canvas polarity; `display-xxl` drops to 36–56px; tables become stacked cards |

Data tables do not scroll horizontally on mobile — they reflow into stacked label/value cards, because
a horizontally scrolling order table on a phone is unusable in practice.

---

## 9. Motion

Sparing, in keeping with the reference's editorial restraint.

| Element | Motion |
|---|---|
| Pill button hover | Background/border 150ms ease-out. No scale, no bounce |
| Card hover (public) | Image scale 1.02 over 200ms, container static |
| Modal / offcanvas | Bootstrap defaults, capped at 200ms |
| Toast | Fade + 8px translate, 150ms |
| Skeleton | Subtle shimmer, disabled under reduced motion |
| Page transition | None. Server-rendered pages should feel instant, not animated |

---

## 10. What needs your approval

| Ref | Decision | Recommendation |
|---|---|---|
| **OQ-06** | Approve this reference as the visual language, including a black cinematic public marketplace | Yes |
| **OQ-06a** | Approve **DS-EXT-01**, adding two desaturated hues (amber, red) for status semantics | Yes — the alternative is materially worse for users |
| **OQ-06b** | Approve the accessibility adaptations in §7, which deliberately depart from the reference | Yes |
| **OQ-07** | Tailwind CLI build vs CDN | CLI build, output committed so running the app needs no Node |
| **OQ-06c** | Photography: the cinematic track depends on strong merchant photography. Do you have imagery, or should Phase 1 use clearly-labelled placeholder photography? | Labelled placeholders in Phase 1, swapped later |
| **OQ-06d** | The reference reserves aloe for the light track. Two elements break that on the dark nav: the **logo mark** and the **basket count**. Both are wayfinding rather than decoration, and white would blend into the surrounding white text. | Keep aloe for these two only |

A `/styleguide` page rendering every token and component will be the first thing built in Phase 1, so
you can approve the look on one screen instead of across 85.
