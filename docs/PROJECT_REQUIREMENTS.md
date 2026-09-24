# Project Requirements — Online Marketplace & Customer Retention Platform

**Working codename:** SokoLink *(placeholder — see OQ-01)*
**Document status:** Phase 0 draft, awaiting approval
**Last updated:** 2026-09-21

---

## 1. Purpose

Build a working multi-seller online marketplace where customers buy products online and receive them
either by **Click & Collect** (prepared by the seller, collected in-store) or **Home Delivery**
(carried by a delivery agent). The platform additionally runs a **customer-retention engine** that
schedules consumption-aware reorder reminders and makes repeat purchase a one-click action.

This is not a prototype. Every feature listed as in scope must be backed by real PHP logic, real
MySQL persistence, and real server-side authorisation.

---

## 2. Scope

### 2.1 In scope (v1)

| Area | Included |
|---|---|
| Accounts | Customer, Seller, Delivery Agent, Support, Admin; registration, login, password reset, RBAC |
| Catalogue | Categories, products, light attributes (unit / pack size), images, search, filters |
| Multi-tenancy | Many sellers, many stores per seller, seller-scoped data isolation |
| Cart & checkout | Server-authoritative pricing, stock validation, pickup or delivery selection |
| Orders | Parent order + per-seller sub-orders, enforced state machine, full status history |
| Pickup | Store selection, readiness notification, secure collection-code verification |
| Delivery | Address capture, fee calculation, task creation, agent assignment, proof of delivery, failures |
| Payments | Gateway abstraction + sandbox gateway + cash on collection/delivery; idempotent webhooks |
| Retention | Consumption-aware reorder reminders, consent, preferences, opt-out, delivery logs, retries |
| Support | Tickets, threaded messages, scoped order lookup, escalation |
| Admin | Seller approval, moderation, monitoring, settings, reports, audit log |
| Ops | CLI cron tasks, structured logs, `.env` config, backup/restore docs |

### 2.2 Explicitly out of scope (v1) — candidates for v2

- Real money movement through M-Pesa / Airtel Money / Mixx by Yas / HaloPesa. v1 ships the
  **abstraction and a sandbox driver only**. No claim of live integration will be made anywhere in
  the UI, the docs, or the reports.
- Seller payouts, settlement, commission invoicing, tax/VAT filing.
- Live SMS / WhatsApp sending. Channel interfaces are built; drivers are stubs that record
  `skipped_no_provider` rather than pretending to send.
- Real-time GPS tracking of delivery agents, route optimisation, native mobile apps.
- Multi-currency and full i18n (strings are centralised so it stays possible).
- Product variant matrices (colour x size x material) with independent SKUs.
- Recommendation ML, A/B testing, loyalty points.

---

## 3. Stakeholders and primary goals

| Stakeholder | Primary goal | Success signal |
|---|---|---|
| Customer | Buy reliably, collect or receive conveniently, reorder without effort | Repeat-purchase rate; reminder-to-order conversion |
| Seller | Sell to online demand without building a shop; control stock | Order acceptance rate; time-to-ready |
| Delivery agent | Clear task list, minimum friction, proof of completion | Delivery success rate; failure reasons captured |
| Support staff | Resolve order problems fast with only the data they need | First-response and resolution time |
| Admin | Keep the marketplace trustworthy and observable | Seller approval SLA; dispute rate; audit completeness |

---

## 4. Functional requirements

Requirement IDs are stable and are referenced by the roadmap, the test plan, and commit messages.

### 4.1 Authentication and accounts (FR-AUTH)

| ID | Requirement | Priority |
|---|---|---|
| FR-AUTH-01 | Customers self-register with email + password; email verification required before ordering | Must |
| FR-AUTH-02 | Passwords hashed with `password_hash()`, verified with `password_verify()`, rehash on algorithm change | Must |
| FR-AUTH-03 | Login issues a session; session ID regenerated on privilege change; idle and absolute timeouts | Must |
| FR-AUTH-04 | Logout destroys the server-side session and clears the cookie | Must |
| FR-AUTH-05 | Password reset by single-use, time-limited, hashed token sent by email; invalidated on use | Must |
| FR-AUTH-06 | Sellers register with store details; account is `pending_approval` and cannot trade until an admin approves | Must |
| FR-AUTH-07 | Delivery agent, Support and Admin accounts are created or invited by an admin, never self-registered | Must |
| FR-AUTH-08 | Account statuses `active`, `pending_approval`, `suspended`, `closed`, checked on every request | Must |
| FR-AUTH-09 | Login throttling with progressive lockout per account and per IP | Must |
| FR-AUTH-10 | Optional "remember me" via a rotating selector/validator token pair | Should |
| FR-AUTH-11 | A user may hold multiple roles (a seller who also buys) | Should |

### 4.2 Catalogue and search (FR-CAT)

| ID | Requirement | Priority |
|---|---|---|
| FR-CAT-01 | Nested product categories (max depth 3) managed by admin | Must |
| FR-CAT-02 | Sellers create, edit and archive their own products only | Must |
| FR-CAT-03 | Products carry name, slug, description, category, price, compare-at price, unit, pack size, SKU, status | Must |
| FR-CAT-04 | Multiple images per product with one primary; uploads validated by MIME sniffing, extension allow-list, size cap, re-encode | Must |
| FR-CAT-05 | Public product listing with pagination and sorting (relevance, price, newest, rating) | Must |
| FR-CAT-06 | Keyword search over name, description, brand and category using a FULLTEXT index with a LIKE fallback | Must |
| FR-CAT-07 | Filters: category, price range, seller, store, in-stock-only, fulfilment method, rating | Must |
| FR-CAT-08 | Product detail shows price, per-store availability, seller identity, rating, reviews, fulfilment options | Must |
| FR-CAT-09 | Public seller/store profile with hours, location, pickup instructions and product list | Must |
| FR-CAT-10 | Admin can unpublish or flag any product with a reason (moderation) | Must |
| FR-CAT-11 | Products flagged `is_consumable` with a `typical_consumption_days` hint feed the reorder engine | Must |

### 4.3 Inventory (FR-INV)

| ID | Requirement | Priority |
|---|---|---|
| FR-INV-01 | Stock is held **per product per store**, never globally per product | Must |
| FR-INV-02 | Tracked quantities `qty_on_hand` and `qty_reserved`; `qty_available` is derived, never stored twice | Must |
| FR-INV-03 | Every stock change writes an immutable `stock_movements` row (reason, actor, reference) | Must |
| FR-INV-04 | Order placement reserves stock in a transaction with row locking and cannot oversell under concurrency | Must |
| FR-INV-05 | Reservation is released on cancellation, seller rejection, or payment-window expiry | Must |
| FR-INV-06 | Reservation converts to a decrement of `qty_on_hand` at fulfilment (collected or delivered) | Must |
| FR-INV-07 | Sellers can adjust stock manually with a reason; adjustments are audited | Must |
| FR-INV-08 | A per-store low-stock threshold raises a seller notification | Should |

### 4.4 Cart and checkout (FR-CART)

| ID | Requirement | Priority |
|---|---|---|
| FR-CART-01 | Guests get a cookie-bound cart that merges into the user cart on login | Must |
| FR-CART-02 | Cart lines reference product + store; quantity updates revalidate availability | Must |
| FR-CART-03 | All monetary amounts are recomputed server-side from the database at checkout; client totals are ignored | Must |
| FR-CART-04 | A cart spanning multiple sellers is split into one sub-order per seller | Must |
| FR-CART-05 | Fulfilment method is chosen **per sub-order**, so one seller can be pickup while another is delivery | Must |
| FR-CART-06 | Pickup requires a store that stocks every line of that sub-order | Must |
| FR-CART-07 | Delivery requires a saved or newly entered address inside a served delivery zone | Must |
| FR-CART-08 | Delivery fee is derived server-side from zone, weight and subtotal rules, never from the client | Must |
| FR-CART-09 | Checkout is protected against double submission by an idempotency key plus POST-redirect-GET | Must |
| FR-CART-10 | Price and availability are re-verified at order creation; changes are surfaced, not silently applied | Must |

### 4.5 Orders (FR-ORD)

| ID | Requirement | Priority |
|---|---|---|
| FR-ORD-01 | Parent order records customer, payment and grand total; sub-orders record per-seller fulfilment | Must |
| FR-ORD-02 | Order lines snapshot name, SKU, unit price, tax and quantity at purchase time | Must |
| FR-ORD-03 | A documented state machine governs sub-order status; invalid transitions are rejected server-side | Must |
| FR-ORD-04 | Every transition appends to `order_status_history` with actor, timestamp and reason | Must |
| FR-ORD-05 | Sellers may reject an order with a mandatory reason before preparation begins | Must |
| FR-ORD-06 | Customers may cancel only while `pending_payment`, `awaiting_seller` or `confirmed` | Must |
| FR-ORD-07 | Cancellation or rejection releases stock and triggers refund handling when already paid | Must |
| FR-ORD-08 | Customers see live status, full history and an itemised receipt per order | Must |
| FR-ORD-09 | Order numbers are human-readable and unguessable, e.g. `SL-2026-9F3K2A` | Must |

### 4.6 Pickup / Click and Collect (FR-PICK)

| ID | Requirement | Priority |
|---|---|---|
| FR-PICK-01 | Each pickup sub-order stores the chosen store, pickup window and seller instructions | Must |
| FR-PICK-02 | Reaching `ready_for_pickup` generates a collection code, stored hashed, shown once to the customer | Must |
| FR-PICK-03 | Collection is confirmed only by staff of the owning seller/store by entering or scanning that code | Must |
| FR-PICK-04 | A QR payload encodes the sub-order reference plus code; verification is entirely server-side | Must |
| FR-PICK-05 | Code entry is rate-limited; repeated failures alert the seller and are audited | Must |
| FR-PICK-06 | Uncollected orders past a configurable window become `collection_overdue` and notify both parties | Should |

### 4.7 Delivery (FR-DEL)

| ID | Requirement | Priority |
|---|---|---|
| FR-DEL-01 | Admin-managed delivery zones with fee rules; addresses are matched to a zone | Must |
| FR-DEL-02 | Reaching `ready_for_dispatch` creates a delivery task linked to the sub-order | Must |
| FR-DEL-03 | Tasks are offered to agents (admin-assigned in v1, optional open pool); agents accept or decline with a reason | Must |
| FR-DEL-04 | An agent can read only tasks assigned to them, and only the fields needed to deliver | Must |
| FR-DEL-05 | Agents progress `assigned` to `picked_up` to `out_for_delivery` to `delivered` | Must |
| FR-DEL-06 | Delivery is confirmed by a recipient code plus optional note; agents cannot self-confirm without it | Must |
| FR-DEL-07 | Failed attempts are recorded with a reason code; after N attempts the task returns to the seller | Must |
| FR-DEL-08 | Every delivery event is appended to `delivery_status_history` | Must |
| FR-DEL-09 | Agents see their own delivery history and simple performance counts | Should |

### 4.8 Payments (FR-PAY)

| ID | Requirement | Priority |
|---|---|---|
| FR-PAY-01 | A `PaymentGatewayInterface` isolates order logic from any provider | Must |
| FR-PAY-02 | v1 ships a sandbox gateway, clearly labelled in the UI, plus cash on collection/delivery | Must |
| FR-PAY-03 | Payment intents and transactions persist provider reference, status, amount, currency and raw payload | Must |
| FR-PAY-04 | Webhook endpoints verify authenticity (signature or shared secret plus source checks) before acting | Must |
| FR-PAY-05 | Callback processing is idempotent; a repeated provider reference never credits an order twice | Must |
| FR-PAY-06 | A client-side success message is never accepted as proof of payment | Must |
| FR-PAY-07 | No raw card, PIN or mobile-money credentials are ever stored or logged | Must |
| FR-PAY-08 | Refunds are recorded with status, amount, reason and initiating actor | Must |
| FR-PAY-09 | Unpaid orders expire after a configurable window, releasing reserved stock | Must |

### 4.9 Retention and notifications (FR-CRM)

| ID | Requirement | Priority |
|---|---|---|
| FR-CRM-01 | A single `NotificationService` handles outbound messages through pluggable channels (email live; SMS/WhatsApp interfaces only) | Must |
| FR-CRM-02 | Notifications are queued in the database and dispatched by a CLI worker, never inline in a web request | Must |
| FR-CRM-03 | Every attempt logs channel, status, provider response and attempt count | Must |
| FR-CRM-04 | Transient failures retry with exponential backoff up to a cap; permanent failures stop immediately | Must |
| FR-CRM-05 | Notifications split into transactional (always sent) and marketing (consent required) | Must |
| FR-CRM-06 | Customers manage per-channel, per-category preferences; marketing consent is timestamped and versioned | Must |
| FR-CRM-07 | Every marketing message carries a token-based unsubscribe link that works without login | Must |
| FR-CRM-08 | Reorder reminders are scheduled by a consumption-aware estimator, not a fixed day count | Must |
| FR-CRM-09 | Estimator inputs: seller `typical_consumption_days`, pack size x quantity bought, and the customer's own observed repeat interval for that product; plus suppression rules (already repurchased, product unavailable, order not completed, recent reminder, frequency cap) | Must |
| FR-CRM-10 | Duplicate reminders are structurally impossible via a unique key on customer + product + cycle | Must |
| FR-CRM-11 | Reorder from any past order pre-fills the cart, revalidating price and stock and reporting differences | Must |
| FR-CRM-12 | Admin configures global reminder defaults, quiet hours and per-customer frequency caps | Must |
| FR-CRM-13 | Support staff can see notification delivery status for a customer without seeing message secrets | Should |

### 4.10 Reviews (FR-REV)

| ID | Requirement | Priority |
|---|---|---|
| FR-REV-01 | Only a customer with a completed sub-order containing that product may review it | Must |
| FR-REV-02 | One review per customer per product per order, with an edit window then lock | Must |
| FR-REV-03 | Reviews are moderated `pending` / `published` / `rejected`; sellers may reply once | Should |
| FR-REV-04 | The product rating aggregate is recomputed on publish and unpublish | Must |

### 4.11 Support (FR-SUP)

| ID | Requirement | Priority |
|---|---|---|
| FR-SUP-01 | Customers open tickets, optionally linked to an order, with a category and priority | Must |
| FR-SUP-02 | Threaded messages with internal-note flags; internal notes never render to customers | Must |
| FR-SUP-03 | Lifecycle `open` to `in_progress` to `waiting_customer` to `resolved` to `closed`, plus `escalated` | Must |
| FR-SUP-04 | Support order lookup is scoped to the ticket's customer and excludes payment credentials | Must |
| FR-SUP-05 | Escalation assigns to an admin and records the reason | Must |
| FR-SUP-06 | All support views of customer data are written to the audit log | Must |

### 4.12 Administration (FR-ADM)

| ID | Requirement | Priority |
|---|---|---|
| FR-ADM-01 | User list with role, status and filters; suspend or reactivate with a mandatory reason | Must |
| FR-ADM-02 | Seller approval queue with field review, approve/reject plus reason, and applicant notification | Must |
| FR-ADM-03 | Store and delivery-zone management | Must |
| FR-ADM-04 | Category CRUD and product moderation | Must |
| FR-ADM-05 | Cross-marketplace order, delivery and payment monitoring with filters | Must |
| FR-ADM-06 | Dispute queue fed by escalated tickets | Must |
| FR-ADM-07 | Platform notification settings: channels, quiet hours, reminder defaults, sender identity | Must |
| FR-ADM-08 | Reports: sales by period/seller/category, fulfilment mix, reminder conversion, failed deliveries | Must |
| FR-ADM-09 | Append-only audit log searchable by actor, action, entity and date, with no UI delete path | Must |
| FR-ADM-10 | Typed key/value system settings with change auditing | Must |

---

## 5. Non-functional requirements

### 5.1 Security (NFR-SEC)

| ID | Requirement |
|---|---|
| NFR-SEC-01 | 100% of SQL through PDO prepared statements; no string-interpolated SQL anywhere |
| NFR-SEC-02 | All output escaped at render through a single helper (`htmlspecialchars`, `ENT_QUOTES`, `ENT_SUBSTITUTE`, UTF-8) |
| NFR-SEC-03 | Synchroniser-token CSRF protection on every state-changing request, compared with `hash_equals()` |
| NFR-SEC-04 | Session cookies `HttpOnly`, `SameSite=Lax`, `Secure` in production, custom name, strict mode, never in the URL |
| NFR-SEC-05 | Authorisation enforced by middleware plus per-resource ownership policies. Hiding a button is never the control |
| NFR-SEC-06 | Uploads stored outside executable paths, randomly renamed, MIME-sniffed, allow-listed, re-encoded; the upload directory denies PHP execution |
| NFR-SEC-07 | Secrets live only in `.env`, which is git-ignored and unreachable over HTTP |
| NFR-SEC-08 | Security headers: CSP, `X-Content-Type-Options`, `Referrer-Policy`, frame-ancestors |
| NFR-SEC-09 | Generic user-facing errors; detail goes to `storage/logs` only; `display_errors` off outside dev |
| NFR-SEC-10 | Rate limiting on login, password reset, collection-code entry, ticket creation and review submission |
| NFR-SEC-11 | Audit-log writes for every privileged or sensitive-data action |
| NFR-SEC-12 | IDOR tests exist for every dashboard route (seller to seller, agent to agent, customer to customer) |

### 5.2 Data integrity (NFR-DAT)

| ID | Requirement |
|---|---|
| NFR-DAT-01 | InnoDB everywhere; foreign keys with deliberate `ON DELETE` / `ON UPDATE` actions |
| NFR-DAT-02 | Money as `DECIMAL(12,2)`, never float; currency stored on the order |
| NFR-DAT-03 | Multi-step writes (order creation, stock reservation, payment confirmation, refund) run in transactions |
| NFR-DAT-04 | Stock reservation uses `SELECT ... FOR UPDATE` with deterministic lock ordering to avoid deadlock |
| NFR-DAT-05 | All timestamps stored UTC; PHP and the MySQL session pinned to UTC; display converts to `Africa/Dar_es_Salaam` |
| NFR-DAT-06 | Statuses are constrained enumerations mirrored by PHP constants, never loose free text |
| NFR-DAT-07 | Financial and audit records are append-only; corrections are new rows, not edits |
| NFR-DAT-08 | `utf8mb4` / `utf8mb4_unicode_ci` throughout — see A-16 |

### 5.3 Performance, usability, maintainability, operations

| ID | Requirement |
|---|---|
| NFR-PRF-01 | Catalogue pages respond under 500 ms on the seed dataset on a local XAMPP machine |
| NFR-PRF-02 | Indexes on every FK and on documented query paths; no unbounded `SELECT *` on list pages |
| NFR-PRF-03 | Pagination on every list, server-side, with a hard page-size cap |
| NFR-USA-01 | Responsive at 360 / 768 / 1024 / 1440 px with no horizontal scrolling |
| NFR-USA-02 | WCAG 2.1 AA: labelled inputs, visible focus, 4.5:1 text contrast, keyboard operability, live-region errors |
| NFR-USA-03 | Every screen defines loading, empty, success and error states. No dead links or decorative buttons |
| NFR-MNT-01 | PSR-12 style, PSR-4 autoloading, one class per file, typed properties and return types |
| NFR-MNT-02 | Business logic lives in services; views contain presentation only |
| NFR-MNT-03 | Requirement IDs referenced from non-obvious code and from the test plan |
| NFR-OPS-01 | All scheduled work runs from `bin/` CLI entry points under cron or Task Scheduler, never from page views |
| NFR-OPS-02 | Structured application, security and notification logs with rotation guidance |

---

## 6. Primary user journeys

Step-by-step flows live in `USER_FLOWS.md`. Summary:

1. **J1** Customer registers, verifies email, browses, orders.
2. **J2** Seller applies, admin approves, seller opens store, lists products, sets stock.
3. **J3** Customer searches, filters, opens a product, adds to cart, checks out for pickup.
4. **J4** Seller accepts, prepares, marks ready; customer is notified and collects with a code.
5. **J5** Customer checks out for delivery; seller dispatches; agent is assigned and delivers with a code.
6. **J6** Delivery fails, reason recorded, retry or return-to-seller, refund path.
7. **J7** Customer views history and reorders; cart is pre-filled and revalidated.
8. **J8** The reminder engine finds an eligible consumable, queues a reminder; customer reorders or opts out.
9. **J9** Customer opens a support ticket; support resolves or escalates; admin closes the dispute.
10. **J10** Admin monitors orders, approves sellers, reads reports and the audit log.

---

## 7. Assumptions

If you reject an assumption, say so and the affected documents change before Phase 1 starts.

| # | Assumption | Why | Impact if wrong |
|---|---|---|---|
| A-01 | The market is Tanzania: currency TZS, display timezone `Africa/Dar_es_Salaam`, phone format `+255...` | The named providers (M-Pesa TZ, Airtel Money, Mixx by Yas, HaloPesa) are Tanzanian | Currency formatting, phone validation, zones, seed data |
| A-02 | Storage is UTC, display is local | Prevents DST and locale bugs, keeps reminder scheduling sane | Pervasive but mechanical |
| A-03 | A cart may span sellers and is split into per-seller sub-orders at checkout | Sellers fulfil independently; one must not block another | Core schema and state machine |
| A-04 | Payment is captured at parent-order level; fulfilment is tracked at sub-order level | One customer payment, many fulfilments | Refunds become partial-refund aware |
| A-05 | v1 payment methods are the sandbox gateway plus cash on collection/delivery | No provider credentials available | Adding a real driver later is purely additive |
| A-06 | Email is the only live channel, via SMTP configured in `.env` | Nothing else is connected | SMS/WhatsApp stay interfaces and stubs |
| A-07 | Delivery agents are platform-employed and admin-assigned, not per-seller couriers | Simpler permission model | Assignment UI and agent scoping |
| A-08 | Products have simple attributes (unit, pack size) with no variant matrix | Avoids a combinatorial SKU model in v1 | Would add `product_variants` and move stock and price down a level |
| A-09 | Delivery fee is zone base plus optional weight and subtotal rules, with a configurable free threshold | Deterministic and testable, no mapping API needed | Distance pricing would need geocoding |
| A-10 | Stock is reserved at order placement, not at cart-add, and expires with unpaid orders | Prevents oversell without freezing inventory for browsers | Reservation timing and the expiry job |
| A-11 | The app is served from a `public/` web root with everything else above it | Standard hardening | XAMPP setup instructions, see OQ-05 |
| A-12 | Bootstrap 5 owns components and grid; Tailwind is utilities only, prefixed `tw-` | Both frameworks were mandated; a prefix makes coexistence deterministic | Styling convention across Phase 1 |
| A-13 | The supplied getdesign.md analysis is the visual direction; its two tracks map to public site (cinematic dark) and dashboards (light transactional) | It is already a two-track commerce system | See `DESIGN_SYSTEM.md` |
| A-14 | Composer is used for PHPMailer (SMTP) and PHPUnit (dev) only | Hand-rolled SMTP and hand-rolled test runners are worse engineering, not less complexity | If rejected, email falls back to `mail()` and tests become plain CLI scripts |
| A-15 | Git is initialised at the project root during Phase 1 setup | Required by the brief; the directory is currently not a repo | — |
| A-16 | The database engine is **MariaDB 10.4.32**, not MySQL 8.0 | Verified at Phase 1 start: this XAMPP install ships MariaDB. The brief says "MySQL 8.0 or compatible" | Collation must be `utf8mb4_unicode_ci` (`utf8mb4_0900_ai_ci` is MySQL 8 only); `SKIP LOCKED` is unavailable before MariaDB 10.6, so reservation uses deterministic lock ordering instead — which was already the plan. CHECK constraints, FULLTEXT on InnoDB, CTEs and window functions are all supported. Schema will be written to run on both |
| A-17 | `ext-intl` is **not installed** | Verified at Phase 1 start | Currency and number formatting are hand-rolled in `Core\Money` rather than adding a dependency for one string |

---

## 8. Open questions requiring your decision

Defaults are what I will proceed with if you approve without answering.

| # | Question | Options | Recommendation (default) |
|---|---|---|---|
| OQ-01 | Product name used in UI, emails and seed data | any | Placeholder **SokoLink**, renameable from one settings row |
| OQ-02 | Confirm market Tanzania, currency TZS, English-only v1 | TZS or other; EN or EN+SW | TZS + English, with a Swahili-ready string layer |
| OQ-03 | Who assigns delivery agents to tasks | admin-only / open pool / seller assigns | Admin-only, with an open-pool toggle per zone |
| OQ-04 | Does the platform take a commission per sale, and is it recorded in v1 | none / fixed % / per-category % | Record a commission field per sub-order but build no payouts. Cheap now, painful to retrofit |
| OQ-05 | XAMPP serving mode | vhost to `public/` / `localhost/e-commerce/` with a root rewrite | Support both; document the vhost as preferred, ship the rewrite so it works out of the box |
| OQ-06 | Is the design analysis approved as the visual language, including a black cinematic public site | yes / lighter variant / different reference | Yes, with the accessibility adaptation in `DESIGN_SYSTEM.md` section 7 |
| OQ-07 | Tailwind delivery: CDN Play (no build) vs CLI build (smaller, needs Node once) | CDN / CLI | CLI build with the output CSS committed, so running the app needs no Node. Tell me if any Node at all is unacceptable |
| OQ-08 | Approve Composer for PHPMailer and PHPUnit | yes / PHPMailer only / none | Yes |
| OQ-09 | Reorder reminder default when no data exists yet | seller hint / fixed 30 days / none until a second purchase | Seller hint x pack quantity; if absent, send nothing. Never guess |
| OQ-10 | Review moderation: pre-publish approval or publish-then-moderate | pre / post | Post-moderation with auto-hold on reported reviews |

---

## 9. Acceptance criteria for "the platform works"

Phase 0 is accepted when these documents are approved. The **project** is accepted when all of the
following are demonstrable on a clean install, with evidence recorded in the test report:

1. A clean MySQL import plus seed produces a working marketplace with test accounts for all five roles.
2. Workflows A to L from the brief execute end to end against real database state.
3. Two concurrent checkouts for the last unit of stock produce exactly one success and one clean,
   user-visible failure, proven by a scripted concurrency test.
4. Cross-role IDOR probes (seller reads another seller's order, agent reads an unassigned delivery,
   customer reads another customer's order) all return 403 or 404 and appear in the audit log.
5. A replayed payment webhook credits the order exactly once.
6. The reminder CLI task, run twice in a row, produces zero duplicate reminders and skips
   non-consenting customers.
7. An unsubscribe link works without login and suppresses future marketing messages.
8. Every page renders correctly at 360 / 768 / 1024 / 1440 px.

Anything not demonstrated will be reported as **pending**, never as passing.
