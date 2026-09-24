# Frontend Pages — Phase 1

**Status:** Phase 1 complete (1a + 1b), awaiting approval
**Last updated:** 2026-09-22
**Scope:** scaffolding, design system, styleguide, public marketplace, authentication, and all
five role dashboards. **85 page templates, 85 named routes, 86 distinct screens.**

---

## 1. How a page is built

```
routes/web.php            Public + auth routes
routes/dashboard.php      The five role dashboards
        |
app/Controllers/...       Reads the request, fetches a view model, picks a view
        |
app/Support/Mock*.php     PHASE 1 ONLY - stands in for the repositories
        |
app/Views/pages/...       The page body
        |
app/Views/layouts/...     public.php | auth.php | dashboard.php
        |
app/Views/partials/       nav, footer, flash, breadcrumbs, pagination, filters,
                          dash-sidebar, dash-topbar, dash-page-header, checkout-steps
app/Views/components/     data-table, product-card, badge, field, stat-row, detail-list, ...
```

Templates never fetch their own data. They receive a flat array and echo it through `e()`.

### Tracks

Each page declares `track` in its controller; the layout follows it end to end.

| Track | Canvas | Used by |
|---|---|---|
| `cinematic` | near-black | home, catalogue, category, product, store, search, about, sell-with-us, contact, error pages |
| `transactional` | cream | basket, checkout, legal pages, styleguide, **and every dashboard** |

Authentication uses `layouts/auth.php` — a split canvas, cinematic brand panel beside a
transactional form panel, the brand panel hidden below 1024px.

---

## 2. Page inventory

**85 templates:** web 16 · auth 6 · errors 3 · customer 14 · seller 15 · delivery 6 · support 7 ·
admin 18.

### 2.1 Public marketplace (cinematic)

| Page | Route | Purpose |
|---|---|---|
| Home | `/` | Hero, categories, how it works, featured, retention pitch, stores |
| Product listing | `/products` | Full catalogue, filters, sort, pagination |
| Category | `/category/{slug}` | Same listing, category locked |
| Product detail | `/products/{slug}` | Gallery, price, fulfilment, per-store stock, reviews |
| Store profile | `/store/{slug}` | Location, hours, pickup instructions, catalogue |
| Search | `/search?q=` | Results, with suggestions when empty |
| About · Sell with us · Contact | `/about` `/sell-with-us` `/contact` | Proposition, seller pitch, support form |
| Terms · Privacy | `/terms` `/privacy` | Draft, clearly marked unreviewed |
| Unsubscribe | `/unsubscribe?token=` | Stop marketing without logging in |

### 2.2 Basket and checkout (transactional)

`/cart` · `/checkout` · `/checkout/payment` · `/checkout/confirmation` — grouped by seller,
fulfilment chosen per seller, server-authoritative totals stated throughout.

### 2.3 Authentication

`/login` · `/register` · `/register/seller` · `/forgot-password` · `/reset-password` ·
`/verify-email`

### 2.4 Customer dashboard (14)

| Page | Route | What it demonstrates |
|---|---|---|
| Overview | `/customer` | Live orders, collection code, buy-again, message inbox |
| Active orders | `/customer/orders` | One row per **sub-order** — one order can appear twice |
| Order detail | `/customer/orders/{ref}` | Progress timeline, collection/delivery code, sibling parts |
| History | `/customer/history` | Completed, cancelled and refunded |
| Reorder | `/customer/reorder` | **Revalidation summary** before the basket is touched |
| Payments | `/customer/payments` | Transactions; no payment instrument, because none is stored |
| Profile | `/customer/profile` | Separate email-change and password-change flows |
| Addresses | `/customer/addresses` | Zone and fee per address; no-zone = collection only |
| Notifications | `/customer/notifications` | Service vs marketing visually distinguished |
| Preferences | `/customer/preferences` | Consent record, quiet hours, SMS/WhatsApp shown *not connected* |
| Reviews · Write review | `/customer/reviews` `/customer/reviews/write` | Verified-purchase gating |
| Support · Ticket | `/customer/support` `/customer/support/{ref}` | Thread with internal notes filtered out |

### 2.5 Seller dashboard (15)

Dashboard · Orders (5 stage tabs) · Order detail · Ready to collect · **Collection verification** ·
Ready to dispatch · Products · Product form · Inventory · Stores · Store edit · Reorder reminders ·
Settings · Sales reports · Reviews.

Highlights: the pick list, the mandatory rejection reason, per-store stock with
on-hand/reserved/available, and the counter screen where a collection code is verified against a
stored hash.

### 2.6 Delivery agent (6)

Overview · My deliveries · Task detail · Available jobs · History · Failed-attempt report.

Highlights: offers show zone, distance band and fee but **never the address** until accepted;
completion requires the recipient's code; a failure needs a reason code plus a written account.

### 2.7 Support desk (7)

Overview · Ticket queue (5 status tabs) · Ticket detail · Order lookup · Order detail ·
Notification monitor · Escalated.

Highlights: internal notes visible here and filtered from the customer view; the notification
monitor separates **failed** from **deliberately skipped** with the reason for each.

### 2.8 Administrator (18)

Overview · Users · User detail · Seller approvals · Approval detail · Stores · Categories ·
Product moderation · Order monitor · Order detail · Delivery monitor · Delivery zones · Payments
and refunds · Disputes · Notification settings · Reports · Audit log · System settings.

Highlights: mandatory reasons on suspension, rejection and status override; the reminder schedule
with a reason for every skip; an append-only audit log with no edit or delete path.

### 2.9 System

`/styleguide` · `/styleguide/error/{403,404,500}` · the real 403/404/500 pages ·
`POST /preview/submit` (the Phase 1 form sink).

---

## 3. Reviewing the states

| What to see | Where |
|---|---|
| Loading skeleton | `/products?state=loading` |
| Empty result / basket / search | `/products?state=empty` · `/cart?state=empty` · `/search?q=zzzzz` |
| Out of stock / low stock | `/products/jamaa-laundry-soap-bar-6pk` · `/products/bustani-bananas-bunch` |
| Price-changed warning | `/cart` (the tea line) |
| Collection code issued | `/customer/orders/SL-2026-9F3K2A-1` |
| Delivery code + COD | `/delivery/tasks/SL-2026-9F3K2A-2` |
| Seller rejection with reason | `/customer/orders/…` via `/admin/orders?status=rejected_seller` |
| Failed delivery + retry decision | `/admin/deliveries` |
| Internal support note | `/support/tickets/TKT-2026-0411` (and absent from `/customer/support/TKT-2026-0402`) |
| Reminder skip reasons | `/admin/notifications` |
| Seller stage tabs | `/seller/orders?status=incoming|pickup|dispatch|done|problem` |
| Role switching | the banner at the top of any dashboard |

---

## 4. Key components

`data-table` is the lever that made 60 dashboard screens feasible: columns are **described**, not
hand-written, so the mobile reflow, the accessible markup, and the rendering of a status, money or
timestamp all live in one file.

| Component | Notes |
|---|---|
| `data-table` | Declarative columns; types: text, code, badge, money, money_compact, datetime, date, relative, number, chips, avatar, bool, muted, actions |
| `stat-row` · `detail-list` · `tabs` | KPI tiles, label/value panels, bookmarkable tab strips |
| `field` | Wires `aria-describedby` and `aria-invalid`; one place to get forms right |
| `badge` | Status→tone map mirrored from the Phase 2 enums; label + dot, never colour alone |
| `product-card` · `store-card` · `rating` · `quantity` · `empty-state` · `skeleton-grid` · `icon` · `product-image` · `devnote` | As Phase 1a |

`filters` and `dash-sidebar` each take an `idPrefix` because they render twice per page (desktop
rail + mobile offcanvas) and duplicate ids break label association.

---

## 5. Mock data and the Phase 4 contract

| File | Replaced in Phase 4 by |
|---|---|
| `_mock/categories.php` | `CategoryRepository::tree()` |
| `_mock/stores.php` | `StoreRepository::findBySlug()`, `::listForSeller()` |
| `_mock/products.php` | `ProductRepository::listForCatalog()`, `::findBySlug()`, `::search()` |
| `_mock/reviews.php` | `ReviewRepository::publishedForProduct()` |
| `_mock/cart.php` | `CartRepository::currentFor()` + `CartPricingService::priceCart()` |
| `_mock/orders.php` | `OrderRepository::forCustomer()`, `::forSeller()`, `::findByRef()`, `::monitorAll()` |
| `_mock/support.php` | `TicketRepository::*`, `NotificationRepository::*` |
| `_mock/admin.php` | `UserRepository`, `SellerApplicationRepository`, `PaymentRepository`, `DeliveryZoneRepository`, `AuditRepository`, `SettingsRepository`, `ReminderRepository` |

`app/Support/MockCatalog.php` and `app/Support/MockDashboard.php` fake the query behaviour and are
deleted with them. **Phase 4 is not complete until `app/Views/_mock/`, `MockCatalog.php` and
`MockDashboard.php` are all gone** — a mechanical, checkable definition of "the mock data is gone".

### How scoping is expressed

`MockDashboard::sellerOrders($sellerId)`, `::agentTasks($agentId)`, `::customerOrders($customer)`.
In Phase 3 the id comes from the session-derived actor and the filter moves into the repository as
a `WHERE` clause — which is what turns it from a view convenience into an access control. The route
prefix is organisation, not security.

---

## 6. What is deliberately not real

Every form has a real method, a real action and a **genuinely verified CSRF token**, and posts to
`PreviewController`, which flashes a message naming the phase that implements it.

| Appears to do | Actually does | Implemented in |
|---|---|---|
| Log in / register / apply to sell / reset password | Verifies CSRF, names the phase | 3.2 |
| Add to basket, change quantity, place order | Same. The stepper updates the display only | 3.4, 3.5 |
| Accept / reject / prepare / mark ready | Same | 3.5 |
| Verify a collection code | Same | 3.6 |
| Accept a job, confirm delivery, report a failure | Same | 3.7 |
| Approve a refund, suspend a user, approve a seller | Same | 3.8, 3.12 |
| Save settings, reminders, preferences | Same | 3.9, 3.12 |

**Dashboards are not protected.** There is no authentication yet, so `/admin` opens for anyone. A
banner says so on every dashboard screen and carries the role switcher used for review. Phase 3.2
puts `RequireAuth` + `RequireRole` in front and the ownership checks inside the services.

Other deliberate gaps: the header basket count is fixed sample text; mobile money is shown as
**not selectable with the reason stated**; SMS and WhatsApp appear as *not connected* rather than
as toggles that silently do nothing.

---

## 7. Verification performed

Executed 2026-09-21/22 against Apache 2.4.58 / PHP 8.2.12 on this machine.

| Check | Method | Result |
|---|---|---|
| PHP syntax | `php -l` × 161 files | all clean |
| Route sweep | every static GET from the router (69) + 24 parameterised/state variants | **93/93** → 200, no PHP notices |
| Routes named | router introspection | 85/85 named, 0 unnamed |
| Error handling | unknown URL, unknown record, wrong method | 404 · 404 · 405 with `Allow: POST` |
| **Cross-role scoping** | another customer's order and ticket; another seller's order and store; another agent's task | **all 404** |
| Internal support notes | rendered support view vs customer view | present for support, **absent** for the customer |
| CSRF | valid / invalid / absent token | 303 · 403 · 403 |
| Security headers | response inspection | CSP `'self'`, nosniff, Referrer-Policy, X-Frame-Options, Permissions-Policy |
| Secrets not served | `.env`, `composer.json`, `routes/dashboard.php`, `app/Support/*`, `docs/*.md` | all 403 or 404 |
| HTML quality | 87 pages parsed | **87/87 clean** — no duplicate ids, no unlabelled controls, no empty controls, no `href="#"`, one h1 each, no heading skips |
| Responsive | Playwright, 80 pages × 360/768/1024/1440 = **320 renders** | no horizontal scrolling, no clipped content, no tap target under 24px, no console errors |
| Tailwind isolation | build inspection | 100% `tw-` prefixed, preflight absent, 4.7 KB |

### Bugs found by that verification and fixed

**Phase 1a (8):** CSS cascade order (utilities must load last — nav buttons stayed visible at 360px
and pushed every page 150px wide); duplicate SVG gradient ids; option-card radios rendering
13×122px; nav dropdown showing browser button chrome; all in-content links default blue
(`--bs-link-color-rgb`, not `--bs-link-color`); star ratings invisible on the dark track; CSRF
returning 500 because 419 is not a registered status; tap targets under 24px.

**Phase 1b (9):**

1. **Duplicate method name** — `CustomerController::addresses()` declared twice (page action and data helper); fatal error.
2. **`use` on an arrow function** — `fn (...) use (...)` is a parse error; `fn` captures automatically.
3. **Sidebar Log out button** showed browser chrome — `.sl-side-link` never reset `background`/`border`.
4. **Dashboard two-column layouts broke at 1024px** — a 264px pinned sidebar plus `1fr` (which has a min-content floor) overflowed. Moved to the `xl` breakpoint and `minmax(0, 1fr)`.
5. **Wide tables pushed the page sideways** — added a scroll container inside the card; applied to the component and to all 7 hand-written tables.
6. **Mobile table reflow could not wrap** — long values (timestamps, avatar cells) spilled off a 360px screen.
7. **Long badge labels were clipped** by `overflow-x: hidden` — "Internal - not visible to the customer" ran off the side.
8. **Channel checkboxes had no accessible name** — they sat in a `<div>`, not a `<label>`.
9. **Long button labels could not wrap** on a phone.

Two measurement bugs in my own audit were also corrected: SVG internals were counted as page
overflow, and `scrollWidth` is unreliable under `body { overflow-x: hidden }` — the audit now tests
whether the page *actually* scrolls and distinguishes content that is **scrollable** (reachable)
from content that is **clipped** (lost).

---

## 8. Known gaps

- The header basket count is fixed sample text, not derived from the mock cart.
- `nav-public.php` loads categories directly rather than via the controller; moves to a view composer in Phase 4.
- Product gallery thumbnails are decorative (one placeholder per product).
- Charts are CSS bar charts with an accessible table alongside — adequate for review, not a charting library.
- Per-role controllers are one file each rather than the ten listed in `SYSTEM_ARCHITECTURE.md` §4. Phase 1 controllers only choose a view model and a template; they split along those lines in Phase 3 when there is behaviour to separate. Method names already match the intended file names.
- Contrast was reasoned from token values, not measured with a contrast tool.
- No screen-reader testing — only structural checks (labels, roles, headings, live regions).
- No automated regression tests yet; all verification is scripted but external to the app. `tests/` arrives in Phase 3.
