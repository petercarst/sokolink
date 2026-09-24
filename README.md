# SokoLink — Online Marketplace & Customer Retention Platform

Multi-seller marketplace with **click & collect**, **home delivery**, and a
consumption-aware **reorder reminder** engine.

PHP 8.2 · MySQL/MariaDB · PDO · Bootstrap 5 + Tailwind utilities · no framework.

> **Current state: Phase 4 in progress — customer, seller and delivery are
> live.** A customer can register, confirm their email, sign in, browse a
> catalogue read from the database, fill a basket and check out. A seller can
> list a product, stock it, publish it, then accept an order, prepare it, mark it
> ready and verify the collection code at the counter. A delivery agent can
> carry a parcel from the store to the door and hand it over against the
> recipient's code. A customer can open a support request and follow it, and an
> agent can answer it, escalate it or close it — without ever being able to read
> a collection code or open an order without saying which ticket it was for.
> All of that is real and is stored. 779 assertions across 14 suites, including
> two live database connections racing for the last unit of stock, and 255 that
> drive the real router, middleware and CSRF check through an in-process HTTP
> client.
>
> **The admin dashboard is not wired yet.** It still renders sample data behind
> open routes, and a banner on every one of its pages says so.
> Some customer and seller sub-pages are in the same state and carry the same
> banner. See [`docs/INTEGRATION.md`](docs/INTEGRATION.md) for exactly what is
> connected.
>
> **No payment provider is connected.** The sandbox driver settles locally and
> says so on every screen; nothing is charged. Mobile money is shown as
> unavailable with the reason stated. SMS and WhatsApp messages are recorded as
> skipped, never as sent.

---

## Run it

Apache and MySQL must be running in XAMPP. Nothing else is required — no
`composer install`, no `npm install`.

```
http://localhost/e-commerce/
```

The root `.htaccess` forwards requests into `public/`, which is the only
web-reachable directory. An Apache vhost pointing `DocumentRoot` at `public/`
is the preferred setup and works identically; both are supported.

**Requirements:** PHP 8.2+ with `pdo_mysql`, `mbstring`, `gd`, `fileinfo`,
`openssl`. `intl` is *not* required — currency formatting is hand-rolled
because this XAMPP build does not ship it.

### Set up the database

Not needed to browse the Phase 1 interface, which still runs on sample data —
but needed to run the tests and required from Phase 3 onward.

```bash
mysql -u root -p < database/schema.sql          # 44 tables
mysql -u root -p sokolink < database/seed.sql   # development data only
```

Full instructions, test accounts, reset and backup procedures:
[database/README.md](database/README.md).

### Run the tests

```bash
php tests/run.php
```

779 assertions across 14 suites. They run against the real `sokolink` database
and clean up after themselves: three consecutive runs leave every row count,
every stock figure and the append-only ledgers exactly where they started, so
the suite is re-runnable without a reimport. The append-only tables are tidied
by high-water mark rather than by matching on content, because removing a row
by what it says would mean the trail could be edited.

Four of those suites are different in kind. The ones under `tests/Http/` drive
the site the way a browser does — real routes, real middleware, real CSRF check
— and then look at the rows that appeared. Nothing in any of them is stubbed.

They do not, however, exercise PHP's own session serializer, which is how a bug
that broke every login outside the test kernel stayed invisible behind a green
suite. `tests/Unit/core_test.php` now asserts that property directly.

### Run a scheduled task

```bash
php bin/console.php list
php bin/console.php send-notifications
```

Nothing time-based depends on a browser being open. See
[BACKEND_MODULES.md](docs/BACKEND_MODULES.md) for the schedule.

### Start here

| | |
|---|---|
| **The design system** | <http://localhost/e-commerce/styleguide> |
| The marketplace | <http://localhost/e-commerce/> |
| The basket (transactional track) | <http://localhost/e-commerce/cart> |
| Customer dashboard | <http://localhost/e-commerce/customer> |
| Seller dashboard | <http://localhost/e-commerce/seller> |
| Delivery agent | <http://localhost/e-commerce/delivery> |
| Support desk | <http://localhost/e-commerce/support> |
| Administrator | <http://localhost/e-commerce/admin> |

Every dashboard carries a role switcher in its banner, so you can move between
the five without logging in — there is no login yet.

`/styleguide` renders every token and component on one page, lists the
documented departures from the supplied design reference, and prints the live
route table.

---

## Layout

```
public/          the ONLY web-reachable directory (front controller, assets)
app/
  Core/          router, request, response, view, session, CSRF, config, logger
  Controllers/   parse input, call a service, pick a view. No SQL, no rules
  Support/       DashboardNav, plus MockCatalog/MockDashboard (deleted in Phase 4)
  Views/
    layouts/     public.php (both tracks), auth.php, dashboard.php
    partials/    nav, footer, flash, breadcrumbs, pagination, filters, dash-*
    components/  data-table, product-card, badge, field, stat-row, detail-list, ...
    pages/       one file per screen
    _mock/       PHASE 1 ONLY - sample data, deleted in Phase 4
routes/web.php   public + auth routes (all named)
routes/dashboard.php  the five role dashboards (all named)
storage/         logs, sessions, cache (never web-reachable)
database/        schema.sql, seed.sql, README.md, migrations/
bin/             CLI tasks for cron            (Phase 3)
docs/            all project documentation
```

---

## Documentation

Start at [docs/README.md](docs/README.md).

| Document | Answers |
|---|---|
| [PROJECT_REQUIREMENTS.md](docs/PROJECT_REQUIREMENTS.md) | Scope, 123 numbered requirements, assumptions, open questions |
| [SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) | Layers, folder structure, order lifecycle, security architecture |
| [USER_ROLES_AND_PERMISSIONS.md](docs/USER_ROLES_AND_PERMISSIONS.md) | Permission matrix and data-visibility boundaries |
| [USER_FLOWS.md](docs/USER_FLOWS.md) | Flows A–O with failure branches |
| [DEVELOPMENT_ROADMAP.md](docs/DEVELOPMENT_ROADMAP.md) | Phases, exit criteria, risks |
| [DESIGN_SYSTEM.md](docs/DESIGN_SYSTEM.md) | Tokens, two tracks, accessibility adaptations |
| [FRONTEND_PAGES.md](docs/FRONTEND_PAGES.md) | All 85 pages, their states, data contracts, and what was verified |
| [DATABASE_DESIGN.md](docs/DATABASE_DESIGN.md) | Why the schema is shaped this way, plus all 44 tables column by column |
| [ER_DIAGRAM.md](docs/ER_DIAGRAM.md) | Nine entity-relationship diagrams and the ON DELETE policy |
| [BACKEND_MODULES.md](docs/BACKEND_MODULES.md) | Every service: what it does, what it refuses, and how to check |
| [database/README.md](database/README.md) | Importing, resetting, backups, connection settings, test accounts |

---

## Working on it

Editing PHP, CSS or templates needs no build step. Only Tailwind does, and its
output is committed so running the app never requires Node.

```bash
npm install          # once, only if you will change Tailwind classes
npm run build:css    # after adding or removing tw-* classes
npm run watch:css    # while working
```

**Cascade order is load-bearing:** bootstrap → design-tokens → app.css →
**tailwind last**. Tailwind utilities and our `.sl-*` classes are both
single-class selectors, so source order decides the winner. Getting this wrong
made "hidden" buttons visible and broke the mobile layout once already.

One styling system per component: Bootstrap styles the component, Tailwind
(`tw-`) adjusts spacing around it, `app.css` holds anything reused more than
twice. `class="btn btn-primary tw-bg-black"` is a defect, not a style choice.

---

## Configuration

Copy `.env.example` to `.env` and edit. `.env` is git-ignored and must never be
committed. If `.env` is absent the app falls back to `.env.example`, which holds
no secrets — that is what lets a fresh clone boot.

Timestamps are stored **UTC** and displayed in `Africa/Dar_es_Salaam`. Money is
`DECIMAL(12,2)`, shown in TZS with no decimal places.

---

## Roadmap

| Phase | | Status |
|---|---|---|
| 0 | Requirements and architecture | complete |
| 1a | Scaffolding, design system, public marketplace, auth screens | complete |
| 1b | Five role dashboards (60 screens) | complete |
| 2 | Database schema and seed | complete |
| 3 | PHP backend, services, CLI tasks | **complete — awaiting review** |
| 4 | Integration — workflows A–L, mock data deleted | next |
| 5 | Testing, security review, QA | |
| 6 | Local deployment and documentation | |

No production-readiness claim is made, and none will be made without evidence.
