# Database Design

**Status:** Phase 2 complete, awaiting approval
**Last updated:** 2026-09-22
**Files:** `database/schema.sql` · `database/seed.sql` · `database/README.md`
**Verified against:** MariaDB 10.4.32 (XAMPP). Written to run on MySQL 8.0 as well — see §10.

**44 tables** covering the 28 modules required by the brief. Every table's columns, types, keys,
foreign keys and check constraints are in §12, **generated from the live database** so the document
cannot drift from what actually exists.

---

## Coverage of the 28 required modules

Every module named in the brief maps to at least one table. Nothing was folded into another
module's table to save a row, and nothing was skipped.

| # | Required module | Tables |
|---|---|---|
| 1 | Users and authentication | `users`, `user_tokens`, `auth_attempts` |
| 2 | Roles and permissions | `roles`, `permissions`, `role_permissions`, `user_roles` |
| 3 | Customer profiles | `customer_profiles` |
| 4 | Customer addresses | `customer_addresses` |
| 5 | Sellers | `sellers`, `seller_applications` |
| 6 | Stores and store locations | `stores`, `store_hours` |
| 7 | Product categories | `categories` |
| 8 | Products | `products` |
| 9 | Product images | `product_images` |
| 10 | Inventory and stock movements | `inventory`, `stock_movements` |
| 11 | Shopping carts | `carts` |
| 12 | Cart items | `cart_items` |
| 13 | Orders | `orders`, `seller_orders` |
| 14 | Order items | `order_items` |
| 15 | Order status history | `order_status_history` |
| 16 | Pickup information | `order_pickups` |
| 17 | Delivery assignments | `delivery_tasks`, `delivery_zones`, `zone_districts`, `delivery_agent_profiles`, `agent_zones` |
| 18 | Delivery status history | `delivery_events` |
| 19 | Payment transactions | `payment_transactions`, `payment_intents` |
| 20 | Refund records | `refunds` |
| 21 | Customer notifications | `notifications` |
| 22 | Notification preferences and consent | `notification_preferences`, `consent_records` |
| 23 | Reorder reminder schedules | `reorder_reminders` |
| 24 | Customer reviews | `reviews` |
| 25 | Customer support tickets | `support_tickets` |
| 26 | Support messages | `support_messages` |
| 27 | Audit logs | `audit_log` |
| 28 | System settings | `settings` |

### The tables the brief did not ask for, and why each exists

The brief allows additional tables "when justified by the approved architecture". Ten tables sit
outside a literal one-table-per-module reading of the list. Each earns its place:

| Table | Why it is not optional |
|---|---|
| `seller_orders` | The brief itself asks how a multi-seller order is handled and suggests sub-orders. This is that table — see §3 |
| `payment_intents` | An attempt to pay is not a transaction. Separating them is what lets an abandoned checkout expire without leaving a phantom payment record |
| `idempotency_keys` | Phase 4 requires that a repeated form submission cannot create a second order. A unique key on a hash is the only way to make that structural rather than hopeful |
| `consent_records` | Append-only consent history. A boolean on `users` cannot answer "what did they agree to, when, and through which channel?" |
| `delivery_zones`, `zone_districts` | Delivery fees and agent coverage need a geography. Without it, fees are a free-text field the client could influence |
| `delivery_agent_profiles`, `agent_zones` | An agent's vehicle, capacity and coverage are not user-account fields, and an agent works several zones |
| `seller_applications` | The record of why selling rights were granted and by whom — the question that only gets asked after something goes wrong |
| `store_hours` | Collection requires knowing when a store is open. Seven rows per store, not a text blob |

---

## 1. Conventions

| Concern | Decision |
|---|---|
| Engine | InnoDB, every table. Verified by test. |
| Charset | `utf8mb4` / `utf8mb4_unicode_ci`, every table. Verified by test. |
| Primary keys | `BIGINT UNSIGNED AUTO_INCREMENT` (`SMALLINT` for small lookup tables) |
| Money | `DECIMAL(12,2)`. **Never** float. Verified by test: zero non-decimal money columns. |
| Timestamps | `DATETIME`, always UTC. **No `TIMESTAMP` columns** — see §4. Verified by test. |
| Statuses | `ENUM`, mirrored by PHP enums in `app/Domain` (Phase 3) |
| Naming | tables plural snake_case; FKs `fk_<table>_<target>`; indexes `idx_…`; unique `uq_…`; checks `chk_…` |
| Deletion | `RESTRICT` for anything financial, `CASCADE` for owned detail rows, `SET NULL` where history must outlive the referent |

### Why ENUM rather than lookup tables for statuses

The brief requires explicit statuses rather than arbitrary text. `ENUM` gives database-level
enforcement, is self-documenting in the schema, and costs nothing at read time. The trade-off is
that adding a status needs an `ALTER TABLE`, which is a migration — acceptable, because adding an
order status is a deliberate design change that *should* be reviewed, not a configuration tweak.

Phase 3 mirrors each ENUM as a PHP enum in `app/Domain`, with a test asserting the two agree. That
test is what stops them drifting.

---

## 2. Four guarantees enforced by the storage layer

Application code gets refactored. A constraint does not get refactored away by accident. These four
rules therefore live in the schema, not in a service class.

| # | Rule | Mechanism | Proved by |
|---|---|---|---|
| 1 | Stock cannot be oversold | `inventory` CHECK + row lock + guarded `UPDATE` | `tests/Concurrency/oversell_test.php` |
| 2 | A replayed webhook cannot credit an order twice | `payment_transactions` UNIQUE `(gateway, gateway_reference)` | contract test §G |
| 3 | A reorder reminder cannot be sent twice per cycle | `reorder_reminders` UNIQUE `(user_id, product_id, cycle_key)` | contract test §F |
| 4 | A double-submitted form cannot create two orders | `idempotency_keys` UNIQUE `(key_hash)` | schema test |

---

## 3. The order model: parent + seller sub-orders

This is the single most consequential decision in the schema (assumption A-03).

```
orders                    the customer's order. ONE payment, ONE grand total.
  └── seller_orders       ONE PER SELLER. This is the row that has a
        │                 fulfilment status and gets accepted, prepared,
        │                 collected or delivered.
        ├── order_items         line items, snapshotted at sale time
        ├── order_status_history  append-only transition log
        ├── order_pickups       collection detail (hashed code)
        └── delivery_tasks      delivery detail (hashed code)
```

### The worked example that justifies it

Seed order `SL-2026-9F3K2A` is one basket from two sellers:

| Part | Seller | Method | Status |
|---|---|---|---|
| `SL-2026-9F3K2A-1` | Mama Lishe | pickup | `ready_for_pickup` |
| `SL-2026-9F3K2A-2` | Duka Kuu | delivery | `out_for_delivery` |

One customer, one payment of TSh 80,900, two independent fulfilments in genuinely different states.
A flat `orders` table would have to pick one status and lie about the other. If Duka Kuu rejected
their part, Mama Lishe's collection would be unaffected and only Duka Kuu's TSh 40,000 would be
refunded — which is exactly what seed order `SL-2026-3K9W1E` demonstrates.

**Money reconciles across the split.** The contract test asserts
`orders.grand_total = SUM(seller_orders.total)` and
`seller_orders.subtotal = SUM(order_items.line_total)` for every row. If those ever drift, a
customer's receipt and a seller's statement disagree, which is a much worse bug than it looks.

### Where each status lives

- **`orders.payment_status`** — one payment for the whole basket: `pending`, `pending_cod`,
  `processing`, `paid`, `failed`, `expired`, `partially_refunded`, `refunded`.
- **`seller_orders.status`** — 21 fulfilment states, the full lifecycle from `pending_payment`
  through the pickup branch (`ready_for_pickup` → `collected`) or the delivery branch
  (`ready_for_dispatch` → `assigned` → `picked_up` → `out_for_delivery` → `delivered`), plus the
  failure and refund states. The state machine in `SYSTEM_ARCHITECTURE.md` §6 governs the
  transitions; this column stores where a part has got to.

`partially_refunded` on the parent exists *because* of the split: refunding one seller's portion
leaves the parent neither fully paid nor fully refunded.

---

## 4. Timezone: UTC storage, local display

Every `DATETIME` is UTC. Three things enforce it:

1. `schema.sql` and `seed.sql` both `SET SESSION time_zone = '+00:00'`.
2. The application connection must do the same on every connect (§11).
3. `Core\Clock` converts to `Africa/Dar_es_Salaam` at render time, and nothing else formats a
   stored timestamp.

**There are no `TIMESTAMP` columns anywhere**, and the contract test asserts that. `TIMESTAMP`
silently converts to and from the session time zone; `DATETIME` stores exactly what it is given. A
mixture of the two is how "delivered at 3am" bugs get shipped.

**The one deliberate exception** is `store_hours`, which stores *local wall-clock* opening times.
"We open at 07:30" is a local concept — converting it to UTC would shift a shop's opening time when
the offset changed. The column is commented in the schema so nobody "fixes" it later.

---

## 5. Money

`DECIMAL(12,2)` throughout — up to 9,999,999,999.99, comfortably beyond any realistic TZS order.
Floats are never used: `0.1 + 0.2 != 0.3` is an amusing curiosity until it is a customer's refund.

TZS is quoted in whole shillings in daily use, so the UI shows no decimal places, but storage keeps
two. Display precision and storage precision are separate concerns and conflating them is how
rounding errors get baked in.

`currency` is stored on `orders` rather than assumed globally, so a second market does not require
a data migration.

**Commission** (`seller_orders.commission_amount`, `sellers.commission_percent`) is recorded from
day one. Payouts and settlement are **not** built in v1 (OQ-04). Recording the number now is cheap;
backfilling it across historical orders later is not.

---

## 6. Inventory and the oversell argument

Stock is held **per product per store** (`inventory`, unique on `(product_id, store_id)`), never as
one number per product. Three quantities:

| Column | Meaning |
|---|---|
| `qty_on_hand` | physically in that store |
| `qty_reserved` | promised to orders already placed |
| `qty_available` | **generated**: `qty_on_hand - qty_reserved`, indexed |

`qty_available` is derived rather than stored, because two numbers that must agree eventually will
not.

### Reservation, in one transaction

```sql
START TRANSACTION;

SELECT id, qty_on_hand, qty_reserved
  FROM inventory
 WHERE product_id IN (...) AND store_id = ?
 ORDER BY product_id            -- deterministic order: two concurrent
   FOR UPDATE;                  -- checkouts queue instead of deadlocking

UPDATE inventory
   SET qty_reserved = qty_reserved + :n
 WHERE id = :id
   AND (qty_on_hand - qty_reserved) >= :n;   -- the guard, re-evaluated
                                             -- against committed data
-- affected rows MUST be 1. If it is 0, the stock went while we were deciding:
-- roll back and tell the customer which line, by name.

INSERT INTO orders / seller_orders / order_items / stock_movements ...;
COMMIT;
```

### Three independent defences

1. **The row lock** stops two transactions both reading "1 available".
2. **The guard in the `UPDATE ... WHERE`** re-checks against committed data, so even if the lock
   were released early the second update matches zero rows.
3. **`CHECK (qty_reserved <= qty_on_hand)`** refuses the write outright if application code ever
   bypasses the guard.

### This is proved, not asserted

`tests/Concurrency/oversell_test.php` opens **two separate connections**, drives them into the
interleaving that breaks a naive design, and asserts the outcome. Result on this machine:

```
[PASS] T1 reads the row and takes the lock          1 available
[PASS] T2 is blocked by T1 row lock                 lock wait timeout as expected
[PASS] T1 guarded UPDATE reserves 1                 affected=1
[PASS] T2 now sees 0 available
[PASS] T2 guarded UPDATE matches 0 rows             affected=0 - order must be rejected
[PASS] CHECK constraint blocks a direct overwrite   chk_inventory_reserved
[PASS] Exactly one unit reserved in total           on_hand=1 reserved=1 available=0
```

One customer got the unit. The other was refused cleanly. The roadmap deferred this to Phase 5;
running it now means the schema is not signed off on a promise.

### Movements are immutable

Every stock change writes a `stock_movements` row with a reason, an actor and a reference.
Corrections are new rows, never edits — which is what makes "where did 12 units go?" answerable.

---

## 7. The retention engine

The brief is explicit that elapsed days alone must not be treated as evidence someone has run out.
The schema makes the reasoning **auditable** rather than hidden in code.

`reorder_reminders.basis` records *how* `next_due_at` was derived, in priority order:

| `basis` | Meaning |
|---|---|
| `observed_interval` | the customer's own median repeat gap for that product (needs 2+ purchases). Strongest signal — it beats any guess. |
| `seller_hint` | `products.typical_consumption_days`, scaled by how much was bought |
| `category_default` | `categories.default_consumption_days`, if an admin set one |
| `none` | nothing schedulable — **send nothing**. Silence beats guessing. |

`skip_reason` records *why* a reminder was not sent: `no_consent`, `consent_withdrawn`,
`already_repurchased`, `cooldown`, `frequency_cap`, `unavailable`, `insufficient_data`,
`quiet_hours`. Without that column, "why didn't this customer get a reminder?" is unanswerable and
the engine is a black box nobody trusts.

### Consent is an append-only history

`consent_records` is never updated in place. The current state is *the latest row*:

```sql
SELECT granted FROM consent_records
 WHERE user_id = :id AND consent_type = 'marketing'
 ORDER BY created_at DESC LIMIT 1;
```

Proving what someone agreed to and when needs the whole trail, not a single mutable flag. The seed
contains all three cases deliberately, and the contract test asserts each:

| Customer | State | Result |
|---|---|---|
| Asha | consented, still consents | eligible |
| Baraka | **never** consented (no row at all) | `no_consent` |
| Grace | consented, then withdrew | `consent_withdrawn` — the latest row wins |

Those three exist so that "we do not message people who said no" is a **testable assertion** rather
than a claim in a document.

### Duplicates are structurally impossible

`UNIQUE (user_id, product_id, cycle_key)`. Running the scheduler twice inserts nothing the second
time — the contract test performs exactly that insert and asserts it is rejected.

---

## 8. Access scoping

The permission model in `USER_ROLES_AND_PERMISSIONS.md` is only real if the schema can express it
cheaply. Each boundary is one indexed `WHERE` clause:

| Boundary | Clause | Index | Verified |
|---|---|---|---|
| Seller sees only their sub-orders | `seller_orders.seller_id = :actor` | `idx_sub_seller_status` | 4 of 8 rows |
| Agent sees only assigned tasks | `delivery_tasks.agent_user_id = :actor` | `idx_task_agent_status` | 2 of 4 rows, `EXPLAIN type=ref` |
| Customer sees only their orders | `orders.user_id = :actor` | `idx_orders_user_time` | 3 of 7 rows |
| Customer never sees internal notes | `support_messages.is_internal = 0` | `idx_msgs_ticket_internal_time` | support 3, customer 2 |

**The id comes from the session-derived actor in Phase 3, never from the request.** That is the
difference between a filter and an access control.

The internal-note case is worth stating plainly: the customer query *excludes those rows in SQL*.
They are never fetched and then hidden in a template, because a template-level hide is one refactor
away from leaking. The contract test asserts support sees 3 messages on ticket 2 and the customer
query returns 2.

---

## 9. What is deliberately NOT in the database

| Not stored | Why | Instead |
|---|---|---|
| Card numbers, CVV, PINs, mobile-money tokens | Never needed; storing them is pure liability | The provider's reference only. Test asserts **zero** columns matching card/cvv/pin/pan |
| Plain-text passwords | — | `password_hash()` output in `users.password_hash` |
| Plain-text collection & delivery codes | Staff verify a code the customer presents | SHA-256 in `order_pickups.code_hash`, `delivery_tasks.code_hash`. Nobody — seller, support or admin — can read one back; they can only verify or regenerate, and regenerating is audited |
| Plain-text reset / verification tokens | A leaked dump must not hand over working reset links | SHA-256 in `user_tokens.token_hash`, with a separate `selector` for lookup |
| Database, SMTP and webhook secrets | A compromised admin session would read them | `.env`, outside the web root and outside git |
| Raw session data | — | `storage/sessions`, restrictive permissions |

`settings` holds business rules an operator can change without a deploy. It holds no secrets, and
the admin UI says so on the page.

### Append-only tables

`audit_log`, `order_status_history`, `stock_movements`, `consent_records`, `delivery_events`.
None has an `updated_at` column — asserted by the contract test — and no application code path
updates or deletes them, including for administrators. An audit log that privileged users can
rewrite tells you nothing.

`audit_log.entity_type`/`entity_id` are deliberately **not** foreign keys: the log must survive the
deletion of whatever it refers to. That is the point of a log.

---

## 10. MariaDB 10.4 vs MySQL 8.0

XAMPP ships MariaDB, and the brief says "MySQL 8.0 or compatible" (assumption A-16). The schema is
written to run on both:

| Feature | Decision |
|---|---|
| Collation | `utf8mb4_unicode_ci` — `utf8mb4_0900_ai_ci` is MySQL-8-only |
| `CHECK` constraints | Used. Verified **enforced** on 10.4.32 (MariaDB 10.2+, MySQL 8.0.16+) |
| Generated columns | Used for `qty_available`, indexed. Verified working |
| InnoDB `FULLTEXT` | Used for product search. Verified working |
| `SELECT … FOR UPDATE` | Used. Verified working |
| `SKIP LOCKED` | **Not used** — MariaDB 10.6+ only. Reservation uses deterministic lock ordering, which was the design anyway |
| `TIMESTAMP` vs `DATETIME` | `DATETIME` only, for the reasons in §4 |

### Strict mode is not optional

This XAMPP build's default `sql_mode` is
`NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION` — **without strict mode**. Verified
behaviour: inserting `'TOOLONGVALUE'` into `VARCHAR(4)` silently stored `'TOOL'`. An invalid
`DECIMAL` silently becomes `0`.

For a system handling money that is unacceptable, so both SQL files set:

```sql
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
```

**The application connection must do the same on every connect.** See §11.

---

## 11. The connection settings Phase 3 must use

`Core\Database` (Phase 3.1) must issue exactly this, on every connection:

```php
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // never silent failure
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,                   // real server-side prepares
]);
$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$pdo->exec("SET SESSION time_zone='+00:00'");
```

`ATTR_EMULATE_PREPARES => false` matters: with emulation on, PDO interpolates values client-side,
which weakens the guarantee that a prepared statement is immune to injection. Both test scripts
already connect exactly this way, so the settings are proven rather than aspirational.

---

## 12. Full table reference

Generated from the live database on 2026-09-22, so it matches `schema.sql` exactly. 44 tables.

Seeded row counts are shown per table; they describe `database/seed.sql`, which is development data
only.

### Index

| Group | Tables |
|---|---|
| Identity and access control (7) | `users` · `roles` · `permissions` · `role_permissions` · `user_roles` · `user_tokens` · `auth_attempts` |
| Customer profile and addresses (2) | `customer_profiles` · `customer_addresses` |
| Sellers and stores (4) | `seller_applications` · `sellers` · `stores` · `store_hours` |
| Catalogue (3) | `categories` · `products` · `product_images` |
| Inventory (2) | `inventory` · `stock_movements` |
| Shopping cart (2) | `carts` · `cart_items` |
| Delivery zones and agents (4) | `delivery_zones` · `zone_districts` · `delivery_agent_profiles` · `agent_zones` |
| Orders (5) | `orders` · `seller_orders` · `order_items` · `order_status_history` · `order_pickups` |
| Delivery (2) | `delivery_tasks` · `delivery_events` |
| Payments (4) | `payment_intents` · `payment_transactions` · `refunds` · `idempotency_keys` |
| Notifications, consent and retention (4) | `notification_preferences` · `consent_records` · `notifications` · `reorder_reminders` |
| Reviews (1) | `reviews` |
| Support (2) | `support_tickets` · `support_messages` |
| Platform (2) | `audit_log` · `settings` |


### Identity and access control

#### `users`

> All people on the platform. Roles are attached via user_roles.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `email` | varchar(190) | no | — | unique |
| `password_hash` | varchar(255) | no | — | — |
| `first_name` | varchar(80) | no | — | — |
| `last_name` | varchar(80) | no | — | — |
| `phone` | varchar(32) | yes | — | — |
| `status` | enum(pending_verification,pending_approval,active,suspended,closed) | no | `pending_verification` | — |
| `status_reason` | varchar(500) | yes | — | — |
| `email_verified_at` | datetime | yes | — | — |
| `locale` | varchar(10) | no | `en` | — |
| `last_login_at` | datetime | yes | — | — |
| `last_login_ip` | varbinary(16) | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_users_email` (email) · INDEX `idx_users_status` (status) · INDEX `idx_users_created` (created_at)

**Referenced by:** `carts.user_id` (CASCADE) · `consent_records.actor_user_id` (SET NULL) · `consent_records.user_id` (CASCADE) · `customer_addresses.user_id` (CASCADE) · `customer_profiles.user_id` (CASCADE) · `delivery_agent_profiles.user_id` (CASCADE) · `delivery_events.actor_user_id` (SET NULL) · `delivery_tasks.agent_user_id` (SET NULL) · `idempotency_keys.user_id` (CASCADE) · `notifications.user_id` (CASCADE) · `notification_preferences.user_id` (CASCADE) · `orders.user_id` (RESTRICT) · `order_pickups.collected_by_user_id` (SET NULL) · `order_status_history.actor_user_id` (SET NULL) · `refunds.approved_by` (SET NULL) · `refunds.requested_by` (SET NULL) · `reorder_reminders.user_id` (CASCADE) · `reviews.user_id` (CASCADE) · `sellers.user_id` (RESTRICT) · `seller_applications.decided_by` (SET NULL) · `seller_applications.user_id` (CASCADE) · `settings.updated_by` (SET NULL) · `stock_movements.actor_user_id` (SET NULL) · `support_messages.author_user_id` (SET NULL) · `support_tickets.assigned_to` (SET NULL) · `support_tickets.escalated_to` (SET NULL) · `support_tickets.user_id` (CASCADE) · `user_roles.granted_by` (SET NULL) · `user_roles.user_id` (CASCADE) · `user_tokens.user_id` (CASCADE)

*Seeded rows: 14*

#### `roles`

> customer, seller, delivery_agent, support, admin.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | smallint(5) unsigned | no | — | auto-increment, **PK** |
| `role_key` | varchar(40) | no | — | unique |
| `name` | varchar(80) | no | — | — |
| `description` | varchar(255) | yes | — | — |

**Keys:** PRIMARY (id) · UNIQUE `uq_roles_key` (role_key)

**Referenced by:** `role_permissions.role_id` (CASCADE) · `user_roles.role_id` (RESTRICT)

*Seeded rows: 5*

#### `permissions`

> Granular capabilities, e.g. order.transition.accept.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | smallint(5) unsigned | no | — | auto-increment, **PK** |
| `permission_key` | varchar(80) | no | — | unique |
| `description` | varchar(255) | no | — | — |
| `area` | varchar(40) | no | — | — |

**Keys:** PRIMARY (id) · UNIQUE `uq_permissions_key` (permission_key) · INDEX `idx_permissions_area` (area)

**Referenced by:** `role_permissions.permission_id` (CASCADE)

*Seeded rows: 46*

#### `role_permissions`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `role_id` | smallint(5) unsigned | no | — | **PK** |
| `permission_id` | smallint(5) unsigned | no | — | **PK** |

**Keys:** PRIMARY (role_id, permission_id) · INDEX `idx_rp_permission` (permission_id)

**Foreign keys:** `permission_id` → `permissions.id` ON DELETE CASCADE · `role_id` → `roles.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 92*

#### `user_roles`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `user_id` | bigint(20) unsigned | no | — | **PK** |
| `role_id` | smallint(5) unsigned | no | — | **PK** |
| `granted_at` | datetime | no | `current_timestamp()` | — |
| `granted_by` | bigint(20) unsigned | yes | — | — |

**Keys:** PRIMARY (user_id, role_id) · INDEX `idx_ur_role` (role_id) · INDEX `fk_ur_granter` (granted_by)

**Foreign keys:** `granted_by` → `users.id` ON DELETE SET NULL · `role_id` → `roles.id` ON DELETE RESTRICT · `user_id` → `users.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 14*

#### `user_tokens`

> Single-use, time-limited tokens. Stored hashed.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `user_id` | bigint(20) unsigned | no | — | — |
| `purpose` | enum(email_verification,password_reset,remember_me,unsubscribe) | no | — | — |
| `selector` | char(24) | no | — | unique |
| `token_hash` | char(64) | no | — | — |
| `expires_at` | datetime | yes | — | — |
| `consumed_at` | datetime | yes | — | — |
| `created_ip` | varbinary(16) | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · UNIQUE `uq_tokens_selector` (selector) · INDEX `idx_tokens_user_purpose` (user_id, purpose) · INDEX `idx_tokens_expiry` (expires_at)

**Foreign keys:** `user_id` → `users.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 0*

#### `auth_attempts`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `email` | varchar(190) | yes | — | — |
| `ip_address` | varbinary(16) | no | — | — |
| `successful` | tinyint(1) | no | `0` | — |
| `attempted_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · INDEX `idx_attempts_email_time` (email, attempted_at) · INDEX `idx_attempts_ip_time` (ip_address, attempted_at)

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 0*

### Customer profile and addresses

#### `customer_profiles`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `user_id` | bigint(20) unsigned | no | — | **PK** |
| `default_address_id` | bigint(20) unsigned | yes | — | — |
| `orders_count` | int(10) unsigned | no | `0` | — |
| `lifetime_spend` | decimal(14,2) | no | `0.00` | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (user_id)

**Foreign keys:** `user_id` → `users.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 6*

#### `customer_addresses`

> Addresses are archived, never hard-deleted: an old order must keep its delivery address.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `user_id` | bigint(20) unsigned | no | — | — |
| `label` | varchar(60) | no | `Home` | — |
| `recipient` | varchar(160) | no | — | — |
| `phone` | varchar(32) | no | — | — |
| `region` | varchar(80) | no | — | — |
| `district` | varchar(80) | no | — | — |
| `ward` | varchar(80) | yes | — | — |
| `street` | varchar(190) | no | — | — |
| `landmark` | varchar(190) | yes | — | — |
| `instructions` | varchar(500) | yes | — | — |
| `zone_id` | bigint(20) unsigned | yes | — | — |
| `is_default` | tinyint(1) | no | `0` | — |
| `archived_at` | datetime | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · INDEX `idx_addr_user` (user_id, archived_at) · INDEX `idx_addr_zone` (zone_id)

**Foreign keys:** `user_id` → `users.id` ON DELETE CASCADE · `zone_id` → `delivery_zones.id` ON DELETE SET NULL

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 6*

### Sellers and stores

#### `seller_applications`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `user_id` | bigint(20) unsigned | no | — | — |
| `business_name` | varchar(160) | no | — | — |
| `business_type` | enum(sole_trader,partnership,company,cooperative) | no | — | — |
| `registration_number` | varchar(80) | yes | — | — |
| `contact_name` | varchar(160) | no | — | — |
| `contact_phone` | varchar(32) | no | — | — |
| `region` | varchar(80) | no | — | — |
| `district` | varchar(80) | no | — | — |
| `store_name` | varchar(160) | no | — | — |
| `street` | varchar(190) | no | — | — |
| `offers_pickup` | tinyint(1) | no | `1` | — |
| `offers_delivery` | tinyint(1) | no | `0` | — |
| `categories_text` | varchar(1000) | no | — | — |
| `status` | enum(pending_approval,approved,rejected) | no | `pending_approval` | — |
| `decision_reason` | varchar(1000) | yes | — | — |
| `decided_by` | bigint(20) unsigned | yes | — | — |
| `decided_at` | datetime | yes | — | — |
| `submitted_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · INDEX `idx_appl_status` (status, submitted_at) · INDEX `idx_appl_user` (user_id) · INDEX `idx_appl_decider` (decided_by)

**Foreign keys:** `decided_by` → `users.id` ON DELETE SET NULL · `user_id` → `users.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 3*

#### `sellers`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `user_id` | bigint(20) unsigned | no | — | unique |
| `slug` | varchar(160) | no | — | unique |
| `business_name` | varchar(160) | no | — | — |
| `registration_number` | varchar(80) | yes | — | — |
| `contact_email` | varchar(190) | no | — | — |
| `contact_phone` | varchar(32) | no | — | — |
| `status` | enum(pending_approval,active,paused,suspended) | no | `pending_approval` | — |
| `commission_percent` | decimal(5,2) | no | `5.00` | — |
| `prep_hours` | smallint(5) unsigned | no | `4` | — |
| `auto_accept` | tinyint(1) | no | `0` | — |
| `low_stock_threshold` | int(10) unsigned | no | `10` | — |
| `rating_avg` | decimal(3,2) | no | `0.00` | — |
| `rating_count` | int(10) unsigned | no | `0` | — |
| `approved_at` | datetime | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_sellers_slug` (slug) · UNIQUE `uq_sellers_user` (user_id) · INDEX `idx_sellers_status` (status)

**Foreign keys:** `user_id` → `users.id` ON DELETE RESTRICT

**Checks:** `chk_sellers_commission`: `commission_percent` >= 0 and `commission_percent` <= 100

**Referenced by:** `products.seller_id` (RESTRICT) · `seller_orders.seller_id` (RESTRICT) · `stores.seller_id` (RESTRICT)

*Seeded rows: 3*

#### `stores`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `seller_id` | bigint(20) unsigned | no | — | — |
| `slug` | varchar(160) | no | — | unique |
| `name` | varchar(160) | no | — | — |
| `region` | varchar(80) | no | — | — |
| `district` | varchar(80) | no | — | — |
| `street` | varchar(190) | no | — | — |
| `landmark` | varchar(190) | yes | — | — |
| `phone` | varchar(32) | no | — | — |
| `latitude` | decimal(10,7) | yes | — | — |
| `longitude` | decimal(10,7) | yes | — | — |
| `pickup_instructions` | varchar(1000) | no | — | — |
| `collection_window_hours` | smallint(5) unsigned | no | `72` | — |
| `offers_pickup` | tinyint(1) | no | `1` | — |
| `offers_delivery` | tinyint(1) | no | `0` | — |
| `status` | enum(draft,published,paused,suspended) | no | `draft` | — |
| `rating_avg` | decimal(3,2) | no | `0.00` | — |
| `rating_count` | int(10) unsigned | no | `0` | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_stores_slug` (slug) · INDEX `idx_stores_seller` (seller_id, status) · INDEX `idx_stores_location` (region, district)

**Foreign keys:** `seller_id` → `sellers.id` ON DELETE RESTRICT

**Referenced by:** `cart_items.store_id` (SET NULL) · `inventory.store_id` (CASCADE) · `order_pickups.store_id` (RESTRICT) · `seller_orders.store_id` (RESTRICT) · `stock_movements.store_id` (CASCADE) · `store_hours.store_id` (CASCADE)

*Seeded rows: 4*

#### `store_hours`

> Local wall-clock hours, NOT UTC. See DATABASE_DESIGN.md section 4.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `store_id` | bigint(20) unsigned | no | — | — |
| `day_of_week` | tinyint(3) unsigned | no | — | — |
| `opens_at` | time | yes | — | — |
| `closes_at` | time | yes | — | — |
| `is_closed` | tinyint(1) | no | `0` | — |

**Keys:** PRIMARY (id) · UNIQUE `uq_hours_store_day` (store_id, day_of_week)

**Foreign keys:** `store_id` → `stores.id` ON DELETE CASCADE

**Checks:** `chk_hours_day`: `day_of_week` between 0 and 6

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 28*

### Catalogue

#### `categories`

> Maximum depth 3 levels (depth 0,1,2) per FR-CAT-01.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `parent_id` | bigint(20) unsigned | yes | — | — |
| `slug` | varchar(160) | no | — | unique |
| `name` | varchar(120) | no | — | — |
| `icon` | varchar(40) | no | `basket` | — |
| `depth` | tinyint(3) unsigned | no | `0` | — |
| `sort_order` | smallint(5) unsigned | no | `0` | — |
| `default_consumption_days` | smallint(5) unsigned | yes | — | — |
| `is_active` | tinyint(1) | no | `1` | — |
| `created_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · UNIQUE `uq_categories_slug` (slug) · INDEX `idx_categories_parent` (parent_id, sort_order)

**Foreign keys:** `parent_id` → `categories.id` ON DELETE RESTRICT

**Checks:** `chk_categories_depth`: `depth` <= 2

**Referenced by:** `categories.parent_id` (RESTRICT) · `products.category_id` (RESTRICT)

*Seeded rows: 25*

#### `products`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `seller_id` | bigint(20) unsigned | no | — | — |
| `category_id` | bigint(20) unsigned | no | — | — |
| `slug` | varchar(190) | no | — | unique |
| `name` | varchar(190) | no | — | — |
| `brand` | varchar(120) | yes | — | — |
| `sku` | varchar(80) | no | — | — |
| `description` | text | no | — | — |
| `price` | decimal(12,2) | no | — | — |
| `compare_at_price` | decimal(12,2) | yes | — | — |
| `unit` | varchar(40) | no | — | — |
| `pack_size` | varchar(60) | no | — | — |
| `weight_grams` | int(10) unsigned | yes | — | — |
| `allows_pickup` | tinyint(1) | no | `1` | — |
| `allows_delivery` | tinyint(1) | no | `0` | — |
| `is_consumable` | tinyint(1) | no | `0` | — |
| `typical_consumption_days` | smallint(5) unsigned | yes | — | — |
| `status` | enum(draft,published,archived,suspended) | no | `draft` | — |
| `moderation_reason` | varchar(1000) | yes | — | — |
| `rating_avg` | decimal(3,2) | no | `0.00` | — |
| `rating_count` | int(10) unsigned | no | `0` | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_products_slug` (slug) · UNIQUE `uq_products_seller_sku` (seller_id, sku) · INDEX `idx_products_category_status` (category_id, status) · INDEX `idx_products_seller_status` (seller_id, status) · INDEX `idx_products_price` (price) · INDEX `idx_products_rating` (rating_avg) · INDEX `idx_products_consumable` (is_consumable, typical_consumption_days) · INDEX `ft_products_search` (name, brand, description)

**Foreign keys:** `category_id` → `categories.id` ON DELETE RESTRICT · `seller_id` → `sellers.id` ON DELETE RESTRICT

**Checks:** `chk_products_price`: `price` > 0 · `chk_products_compare`: `compare_at_price` is null or `compare_at_price` >= `price` · `chk_products_fulfilment`: `allows_pickup` = 1 or `allows_delivery` = 1

**Referenced by:** `cart_items.product_id` (CASCADE) · `inventory.product_id` (CASCADE) · `order_items.product_id` (SET NULL) · `product_images.product_id` (CASCADE) · `reorder_reminders.product_id` (CASCADE) · `reviews.product_id` (CASCADE) · `stock_movements.product_id` (CASCADE)

*Seeded rows: 18*

#### `product_images`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `product_id` | bigint(20) unsigned | no | — | — |
| `stored_path` | varchar(255) | no | — | unique |
| `original_name` | varchar(255) | yes | — | — |
| `mime_type` | varchar(80) | no | — | — |
| `byte_size` | int(10) unsigned | no | — | — |
| `width_px` | smallint(5) unsigned | yes | — | — |
| `height_px` | smallint(5) unsigned | yes | — | — |
| `alt_text` | varchar(255) | yes | — | — |
| `is_primary` | tinyint(1) | no | `0` | — |
| `sort_order` | tinyint(3) unsigned | no | `0` | — |
| `created_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · UNIQUE `uq_images_path` (stored_path) · INDEX `idx_images_product` (product_id, sort_order)

**Foreign keys:** `product_id` → `products.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 0*

### Inventory

#### `inventory`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `product_id` | bigint(20) unsigned | no | — | — |
| `store_id` | bigint(20) unsigned | no | — | — |
| `qty_on_hand` | int(10) unsigned | no | `0` | — |
| `qty_reserved` | int(10) unsigned | no | `0` | — |
| `qty_available` | int(11) | yes | — | generated |
| `low_stock_threshold` | int(10) unsigned | no | `0` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_inventory_product_store` (product_id, store_id) · INDEX `idx_inventory_store` (store_id) · INDEX `idx_inventory_available` (qty_available)

**Foreign keys:** `product_id` → `products.id` ON DELETE CASCADE · `store_id` → `stores.id` ON DELETE CASCADE

**Checks:** `chk_inventory_reserved`: `qty_reserved` <= `qty_on_hand`

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 23*

#### `stock_movements`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `product_id` | bigint(20) unsigned | no | — | — |
| `store_id` | bigint(20) unsigned | no | — | — |
| `movement_type` | enum(receipt,adjustment,reservation,release,fulfilment,return,transfer_in,transfer_out,loss) | no | — | — |
| `qty_delta` | int(11) | no | — | — |
| `qty_after` | int(11) | no | — | — |
| `reason_code` | varchar(40) | yes | — | — |
| `note` | varchar(500) | yes | — | — |
| `reference_type` | varchar(40) | yes | — | — |
| `reference_id` | bigint(20) unsigned | yes | — | — |
| `actor_user_id` | bigint(20) unsigned | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · INDEX `idx_moves_product_store_time` (product_id, store_id, created_at) · INDEX `idx_moves_reference` (reference_type, reference_id) · INDEX `idx_moves_actor` (actor_user_id) · INDEX `fk_moves_store` (store_id)

**Foreign keys:** `actor_user_id` → `users.id` ON DELETE SET NULL · `product_id` → `products.id` ON DELETE CASCADE · `store_id` → `stores.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 6*

### Shopping cart

#### `carts`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `user_id` | bigint(20) unsigned | yes | — | — |
| `cookie_hash` | char(64) | yes | — | unique |
| `status` | enum(active,merged,converted,abandoned) | no | `active` | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_carts_cookie` (cookie_hash) · INDEX `idx_carts_user_status` (user_id, status) · INDEX `idx_carts_updated` (updated_at)

**Foreign keys:** `user_id` → `users.id` ON DELETE CASCADE

**Referenced by:** `cart_items.cart_id` (CASCADE)

*Seeded rows: 0*

#### `cart_items`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `cart_id` | bigint(20) unsigned | no | — | — |
| `product_id` | bigint(20) unsigned | no | — | — |
| `store_id` | bigint(20) unsigned | yes | — | — |
| `qty` | int(10) unsigned | no | `1` | — |
| `price_when_added` | decimal(12,2) | no | — | — |
| `added_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_cart_line` (cart_id, product_id, store_id) · INDEX `idx_cartitems_product` (product_id) · INDEX `idx_cartitems_store` (store_id)

**Foreign keys:** `cart_id` → `carts.id` ON DELETE CASCADE · `product_id` → `products.id` ON DELETE CASCADE · `store_id` → `stores.id` ON DELETE SET NULL

**Checks:** `chk_cartitems_qty`: `qty` > 0

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 0*

### Delivery zones and agents

#### `delivery_zones`

> Fee rules applied SERVER-SIDE. A fee posted by a browser is discarded.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `name` | varchar(120) | no | — | unique |
| `region` | varchar(80) | no | — | — |
| `base_fee` | decimal(12,2) | no | — | — |
| `heavy_surcharge` | decimal(12,2) | no | `0.00` | — |
| `heavy_threshold_grams` | int(10) unsigned | no | `10000` | — |
| `free_threshold` | decimal(12,2) | yes | — | — |
| `max_attempts` | tinyint(3) unsigned | no | `3` | — |
| `assignment_mode` | enum(admin,pool) | no | `admin` | — |
| `is_active` | tinyint(1) | no | `1` | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_zones_name` (name) · INDEX `idx_zones_active` (is_active, region)

**Checks:** `chk_zones_fee`: `base_fee` >= 0

**Referenced by:** `agent_zones.zone_id` (CASCADE) · `customer_addresses.zone_id` (SET NULL) · `delivery_tasks.zone_id` (SET NULL) · `zone_districts.zone_id` (CASCADE)

*Seeded rows: 4*

#### `zone_districts`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `zone_id` | bigint(20) unsigned | no | — | — |
| `region` | varchar(80) | no | — | — |
| `district` | varchar(80) | no | — | — |

**Keys:** PRIMARY (id) · UNIQUE `uq_zone_district` (region, district) · INDEX `idx_zd_zone` (zone_id)

**Foreign keys:** `zone_id` → `delivery_zones.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 6*

#### `delivery_agent_profiles`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `user_id` | bigint(20) unsigned | no | — | **PK** |
| `vehicle_type` | enum(foot,bicycle,motorcycle,car,van) | no | `motorcycle` | — |
| `max_weight_grams` | int(10) unsigned | no | `25000` | — |
| `is_available` | tinyint(1) | no | `1` | — |
| `deliveries_completed` | int(10) unsigned | no | `0` | — |
| `deliveries_failed` | int(10) unsigned | no | `0` | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (user_id) · INDEX `idx_agents_available` (is_available)

**Foreign keys:** `user_id` → `users.id` ON DELETE CASCADE

**Referenced by:** `agent_zones.user_id` (CASCADE)

*Seeded rows: 2*

#### `agent_zones`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `user_id` | bigint(20) unsigned | no | — | **PK** |
| `zone_id` | bigint(20) unsigned | no | — | **PK** |

**Keys:** PRIMARY (user_id, zone_id) · INDEX `idx_az_zone` (zone_id)

**Foreign keys:** `user_id` → `delivery_agent_profiles.user_id` ON DELETE CASCADE · `zone_id` → `delivery_zones.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 3*

### Orders

#### `orders`

> Parent order: the customer, the payment, the grand total.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `order_number` | varchar(32) | no | — | unique |
| `user_id` | bigint(20) unsigned | no | — | — |
| `contact_email` | varchar(190) | no | — | — |
| `contact_phone` | varchar(32) | no | — | — |
| `contact_name` | varchar(160) | no | — | — |
| `payment_status` | enum(pending,pending_cod,processing,paid,failed,expired,partially_refunded,refunded) | no | `pending` | — |
| `payment_method` | enum(sandbox,cash,mpesa,airtel_money,mixx,halopesa) | no | `sandbox` | — |
| `currency` | char(3) | no | `TZS` | — |
| `items_subtotal` | decimal(12,2) | no | `0.00` | — |
| `delivery_total` | decimal(12,2) | no | `0.00` | — |
| `discount_total` | decimal(12,2) | no | `0.00` | — |
| `grand_total` | decimal(12,2) | no | `0.00` | — |
| `placed_at` | datetime | no | `current_timestamp()` | — |
| `paid_at` | datetime | yes | — | — |
| `expires_at` | datetime | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_orders_number` (order_number) · INDEX `idx_orders_user_time` (user_id, placed_at) · INDEX `idx_orders_payment_status` (payment_status) · INDEX `idx_orders_expiry` (expires_at)

**Foreign keys:** `user_id` → `users.id` ON DELETE RESTRICT

**Checks:** `chk_orders_totals`: `items_subtotal` >= 0 and `delivery_total` >= 0 and `discount_total` >= 0 and `grand_total` >= 0

**Referenced by:** `payment_intents.order_id` (CASCADE) · `payment_transactions.order_id` (RESTRICT) · `refunds.order_id` (RESTRICT) · `reorder_reminders.converted_order_id` (SET NULL) · `seller_orders.order_id` (CASCADE) · `support_tickets.order_id` (SET NULL)

*Seeded rows: 7*

#### `seller_orders`

> One per seller. This is the row that has a fulfilment status.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `order_id` | bigint(20) unsigned | no | — | — |
| `sub_number` | varchar(40) | no | — | unique |
| `seller_id` | bigint(20) unsigned | no | — | — |
| `store_id` | bigint(20) unsigned | no | — | — |
| `fulfilment_method` | enum(pickup,delivery) | no | — | — |
| `status` | enum(pending_payment,awaiting_seller,confirmed,preparing,ready_for_pickup,collected,colle... | no | `pending_payment` | — |
| `status_reason` | varchar(1000) | yes | — | — |
| `subtotal` | decimal(12,2) | no | `0.00` | — |
| `delivery_fee` | decimal(12,2) | no | `0.00` | — |
| `total` | decimal(12,2) | no | `0.00` | — |
| `commission_amount` | decimal(12,2) | no | `0.00` | — |
| `accepted_at` | datetime | yes | — | — |
| `ready_at` | datetime | yes | — | — |
| `completed_at` | datetime | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_sub_number` (sub_number) · INDEX `idx_sub_order` (order_id) · INDEX `idx_sub_seller_status` (seller_id, status) · INDEX `idx_sub_store_status` (store_id, status) · INDEX `idx_sub_status_time` (status, created_at)

**Foreign keys:** `order_id` → `orders.id` ON DELETE CASCADE · `seller_id` → `sellers.id` ON DELETE RESTRICT · `store_id` → `stores.id` ON DELETE RESTRICT

**Checks:** `chk_sub_totals`: `subtotal` >= 0 and `delivery_fee` >= 0 and `total` >= 0

**Referenced by:** `delivery_tasks.seller_order_id` (CASCADE) · `order_items.seller_order_id` (CASCADE) · `order_pickups.seller_order_id` (CASCADE) · `order_status_history.seller_order_id` (CASCADE) · `refunds.seller_order_id` (SET NULL) · `reorder_reminders.source_seller_order_id` (SET NULL) · `reviews.seller_order_id` (CASCADE)

*Seeded rows: 8*

#### `order_items`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `seller_order_id` | bigint(20) unsigned | no | — | — |
| `product_id` | bigint(20) unsigned | yes | — | — |
| `name_snapshot` | varchar(190) | no | — | — |
| `sku_snapshot` | varchar(80) | no | — | — |
| `pack_size_snapshot` | varchar(60) | no | — | — |
| `unit_price` | decimal(12,2) | no | — | — |
| `qty` | int(10) unsigned | no | — | — |
| `tax_amount` | decimal(12,2) | no | `0.00` | — |
| `line_total` | decimal(12,2) | no | — | — |

**Keys:** PRIMARY (id) · INDEX `idx_items_sub` (seller_order_id) · INDEX `idx_items_product` (product_id)

**Foreign keys:** `product_id` → `products.id` ON DELETE SET NULL · `seller_order_id` → `seller_orders.id` ON DELETE CASCADE

**Checks:** `chk_items_qty`: `qty` > 0 · `chk_items_price`: `unit_price` >= 0 and `line_total` >= 0

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 12*

#### `order_status_history`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `seller_order_id` | bigint(20) unsigned | no | — | — |
| `from_status` | varchar(40) | yes | — | — |
| `to_status` | varchar(40) | no | — | — |
| `actor_user_id` | bigint(20) unsigned | yes | — | — |
| `actor_type` | enum(customer,seller,agent,support,admin,admin_override,system) | no | — | — |
| `reason` | varchar(1000) | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · INDEX `idx_hist_sub_time` (seller_order_id, created_at) · INDEX `idx_hist_actor` (actor_user_id)

**Foreign keys:** `actor_user_id` → `users.id` ON DELETE SET NULL · `seller_order_id` → `seller_orders.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 48*

#### `order_pickups`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `seller_order_id` | bigint(20) unsigned | no | — | unique |
| `store_id` | bigint(20) unsigned | no | — | — |
| `code_hash` | char(64) | yes | — | — |
| `code_issued_at` | datetime | yes | — | — |
| `window_from` | datetime | yes | — | — |
| `window_to` | datetime | yes | — | — |
| `instructions_snapshot` | varchar(1000) | yes | — | — |
| `verify_attempts` | tinyint(3) unsigned | no | `0` | — |
| `last_attempt_at` | datetime | yes | — | — |
| `collected_at` | datetime | yes | — | — |
| `collected_by_user_id` | bigint(20) unsigned | yes | — | — |

**Keys:** PRIMARY (id) · UNIQUE `uq_pickup_sub` (seller_order_id) · INDEX `idx_pickup_store_window` (store_id, window_to) · INDEX `fk_pickup_by` (collected_by_user_id)

**Foreign keys:** `collected_by_user_id` → `users.id` ON DELETE SET NULL · `store_id` → `stores.id` ON DELETE RESTRICT · `seller_order_id` → `seller_orders.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 3*

### Delivery

#### `delivery_tasks`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `task_ref` | varchar(32) | no | — | unique |
| `seller_order_id` | bigint(20) unsigned | no | — | unique |
| `zone_id` | bigint(20) unsigned | yes | — | — |
| `agent_user_id` | bigint(20) unsigned | yes | — | — |
| `status` | enum(unassigned,offered,assigned,picked_up,out_for_delivery,delivered,failed,returned_to_... | no | `unassigned` | — |
| `recipient_name` | varchar(160) | no | — | — |
| `recipient_phone` | varchar(32) | no | — | — |
| `address_line` | varchar(400) | no | — | — |
| `landmark` | varchar(190) | yes | — | — |
| `instructions` | varchar(500) | yes | — | — |
| `fee` | decimal(12,2) | no | `0.00` | — |
| `cod_amount` | decimal(12,2) | yes | — | — |
| `code_hash` | char(64) | yes | — | — |
| `attempts` | tinyint(3) unsigned | no | `0` | — |
| `max_attempts` | tinyint(3) unsigned | no | `3` | — |
| `assigned_at` | datetime | yes | — | — |
| `delivered_at` | datetime | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_task_ref` (task_ref) · UNIQUE `uq_task_sub` (seller_order_id) · INDEX `idx_task_agent_status` (agent_user_id, status) · INDEX `idx_task_zone_status` (zone_id, status)

**Foreign keys:** `agent_user_id` → `users.id` ON DELETE SET NULL · `seller_order_id` → `seller_orders.id` ON DELETE CASCADE · `zone_id` → `delivery_zones.id` ON DELETE SET NULL

**Checks:** `chk_task_attempts`: `attempts` <= `max_attempts`

**Referenced by:** `delivery_events.task_id` (CASCADE)

*Seeded rows: 4*

#### `delivery_events`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `task_id` | bigint(20) unsigned | no | — | — |
| `event_type` | enum(status_change,attempt_failed,assigned,declined,note) | no | — | — |
| `from_status` | varchar(40) | yes | — | — |
| `to_status` | varchar(40) | yes | — | — |
| `reason_code` | enum(recipient_absent,wrong_address,refused,unreachable_phone,access_denied,unsafe_condit... | yes | — | — |
| `note` | varchar(1000) | yes | — | — |
| `contacted_recipient` | tinyint(1) | yes | — | — |
| `actor_user_id` | bigint(20) unsigned | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · INDEX `idx_devents_task_time` (task_id, created_at) · INDEX `idx_devents_reason` (reason_code) · INDEX `idx_devents_actor` (actor_user_id)

**Foreign keys:** `actor_user_id` → `users.id` ON DELETE SET NULL · `task_id` → `delivery_tasks.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 9*

### Payments

#### `payment_intents`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `order_id` | bigint(20) unsigned | no | — | — |
| `gateway` | varchar(40) | no | — | — |
| `gateway_intent_ref` | varchar(190) | yes | — | — |
| `amount` | decimal(12,2) | no | — | — |
| `currency` | char(3) | no | `TZS` | — |
| `status` | enum(created,processing,succeeded,failed,cancelled,expired) | no | `created` | — |
| `expires_at` | datetime | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · INDEX `idx_intents_order` (order_id) · INDEX `idx_intents_gateway_ref` (gateway, gateway_intent_ref)

**Foreign keys:** `order_id` → `orders.id` ON DELETE CASCADE

**Checks:** `chk_intents_amount`: `amount` >= 0

**Referenced by:** `payment_transactions.intent_id` (SET NULL)

*Seeded rows: 6*

#### `payment_transactions`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `transaction_ref` | varchar(32) | no | — | unique |
| `order_id` | bigint(20) unsigned | no | — | — |
| `intent_id` | bigint(20) unsigned | yes | — | — |
| `gateway` | varchar(40) | no | — | — |
| `gateway_reference` | varchar(190) | no | — | — |
| `direction` | enum(charge,refund) | no | `charge` | — |
| `amount` | decimal(12,2) | no | — | — |
| `currency` | char(3) | no | `TZS` | — |
| `status` | enum(pending,paid,failed,refunded,flagged_for_review) | no | — | — |
| `raw_payload` | text | yes | — | — |
| `signature_verified` | tinyint(1) | no | `0` | — |
| `processed_at` | datetime | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · UNIQUE `uq_txn_ref` (transaction_ref) · UNIQUE `uq_txn_gateway_reference` (gateway, gateway_reference) · INDEX `idx_txn_order` (order_id) · INDEX `idx_txn_status_time` (status, created_at) · INDEX `fk_txn_intent` (intent_id)

**Foreign keys:** `intent_id` → `payment_intents.id` ON DELETE SET NULL · `order_id` → `orders.id` ON DELETE RESTRICT

**Checks:** `chk_txn_amount`: `amount` >= 0

**Referenced by:** `refunds.transaction_id` (SET NULL)

*Seeded rows: 8*

#### `refunds`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `refund_ref` | varchar(32) | no | — | unique |
| `order_id` | bigint(20) unsigned | no | — | — |
| `seller_order_id` | bigint(20) unsigned | yes | — | — |
| `amount` | decimal(12,2) | no | — | — |
| `reason` | varchar(500) | no | — | — |
| `status` | enum(requested,approved,processing,refunded,failed,rejected) | no | `requested` | — |
| `requested_by` | bigint(20) unsigned | yes | — | — |
| `approved_by` | bigint(20) unsigned | yes | — | — |
| `transaction_id` | bigint(20) unsigned | yes | — | — |
| `requested_at` | datetime | no | `current_timestamp()` | — |
| `approved_at` | datetime | yes | — | — |
| `completed_at` | datetime | yes | — | — |

**Keys:** PRIMARY (id) · UNIQUE `uq_refund_ref` (refund_ref) · INDEX `idx_refunds_order` (order_id) · INDEX `idx_refunds_sub` (seller_order_id) · INDEX `idx_refunds_status` (status) · INDEX `idx_refunds_requester` (requested_by) · INDEX `idx_refunds_approver` (approved_by) · INDEX `idx_refunds_txn` (transaction_id)

**Foreign keys:** `approved_by` → `users.id` ON DELETE SET NULL · `order_id` → `orders.id` ON DELETE RESTRICT · `requested_by` → `users.id` ON DELETE SET NULL · `seller_order_id` → `seller_orders.id` ON DELETE SET NULL · `transaction_id` → `payment_transactions.id` ON DELETE SET NULL

**Checks:** `chk_refunds_amount`: `amount` > 0

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 1*

#### `idempotency_keys`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `key_hash` | char(64) | no | — | unique |
| `user_id` | bigint(20) unsigned | yes | — | — |
| `endpoint` | varchar(120) | no | — | — |
| `request_hash` | char(64) | yes | — | — |
| `response_type` | varchar(40) | yes | — | — |
| `response_ref` | varchar(64) | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `expires_at` | datetime | yes | — | — |

**Keys:** PRIMARY (id) · UNIQUE `uq_idem_key` (key_hash) · INDEX `idx_idem_expiry` (expires_at) · INDEX `idx_idem_user` (user_id)

**Foreign keys:** `user_id` → `users.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 0*

### Notifications, consent and retention

#### `notification_preferences`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `user_id` | bigint(20) unsigned | no | — | — |
| `category` | enum(order_updates,pickup_delivery,support,reorder,offers) | no | — | — |
| `channel` | enum(email,sms,whatsapp) | no | — | — |
| `is_enabled` | tinyint(1) | no | `1` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_prefs_user_cat_chan` (user_id, category, channel)

**Foreign keys:** `user_id` → `users.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 25*

#### `consent_records`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `user_id` | bigint(20) unsigned | no | — | — |
| `consent_type` | enum(marketing,terms,privacy) | no | — | — |
| `granted` | tinyint(1) | no | — | — |
| `version` | varchar(20) | no | `v1.0` | — |
| `source` | varchar(60) | no | — | — |
| `ip_address` | varbinary(16) | yes | — | — |
| `actor_user_id` | bigint(20) unsigned | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · INDEX `idx_consent_user_type_time` (user_id, consent_type, created_at) · INDEX `idx_consent_actor` (actor_user_id)

**Foreign keys:** `actor_user_id` → `users.id` ON DELETE SET NULL · `user_id` → `users.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 12*

#### `notifications`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `user_id` | bigint(20) unsigned | no | — | — |
| `channel` | enum(email,sms,whatsapp) | no | `email` | — |
| `category` | enum(order_updates,pickup_delivery,support,reorder,offers) | no | — | — |
| `is_marketing` | tinyint(1) | no | `0` | — |
| `template_key` | varchar(80) | no | — | — |
| `payload_json` | text | yes | — | — |
| `status` | enum(queued,sending,delivered,failed,skipped,cancelled) | no | `queued` | — |
| `skip_reason` | enum(no_consent,consent_withdrawn,already_repurchased,cooldown,frequency_cap,unavailable,... | yes | — | — |
| `attempts` | tinyint(3) unsigned | no | `0` | — |
| `max_attempts` | tinyint(3) unsigned | no | `3` | — |
| `provider_response` | varchar(500) | yes | — | — |
| `send_after` | datetime | no | `current_timestamp()` | — |
| `sent_at` | datetime | yes | — | — |
| `read_at` | datetime | yes | — | — |
| `reference_type` | varchar(40) | yes | — | — |
| `reference_id` | bigint(20) unsigned | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · INDEX `idx_notif_due` (status, send_after) · INDEX `idx_notif_user_time` (user_id, created_at) · INDEX `idx_notif_category` (category, status) · INDEX `idx_notif_reference` (reference_type, reference_id)

**Foreign keys:** `user_id` → `users.id` ON DELETE CASCADE

**Referenced by:** `reorder_reminders.notification_id` (SET NULL)

*Seeded rows: 10*

#### `reorder_reminders`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `user_id` | bigint(20) unsigned | no | — | — |
| `product_id` | bigint(20) unsigned | no | — | — |
| `source_seller_order_id` | bigint(20) unsigned | yes | — | — |
| `cycle_key` | varchar(64) | no | — | — |
| `basis` | enum(observed_interval,seller_hint,category_default,none) | no | — | — |
| `basis_detail` | varchar(255) | yes | — | — |
| `last_purchased_at` | datetime | no | — | — |
| `next_due_at` | datetime | yes | — | — |
| `state` | enum(scheduled,queued,sent,converted,snoozed,skipped,not_scheduled,closed) | no | `scheduled` | — |
| `skip_reason` | enum(no_consent,consent_withdrawn,already_repurchased,cooldown,frequency_cap,unavailable,... | yes | — | — |
| `notification_id` | bigint(20) unsigned | yes | — | — |
| `converted_order_id` | bigint(20) unsigned | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_reminder_cycle` (user_id, product_id, cycle_key) · INDEX `idx_reminder_due` (state, next_due_at) · INDEX `idx_reminder_product` (product_id) · INDEX `idx_reminder_source` (source_seller_order_id) · INDEX `idx_reminder_notification` (notification_id) · INDEX `idx_reminder_converted` (converted_order_id)

**Foreign keys:** `notification_id` → `notifications.id` ON DELETE SET NULL · `converted_order_id` → `orders.id` ON DELETE SET NULL · `product_id` → `products.id` ON DELETE CASCADE · `source_seller_order_id` → `seller_orders.id` ON DELETE SET NULL · `user_id` → `users.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 7*

### Reviews

#### `reviews`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `product_id` | bigint(20) unsigned | no | — | — |
| `user_id` | bigint(20) unsigned | no | — | — |
| `seller_order_id` | bigint(20) unsigned | no | — | — |
| `rating` | tinyint(3) unsigned | no | — | — |
| `title` | varchar(120) | no | — | — |
| `body` | varchar(1500) | no | — | — |
| `status` | enum(pending,published,rejected,hidden) | no | `published` | — |
| `moderation_reason` | varchar(500) | yes | — | — |
| `reported_count` | smallint(5) unsigned | no | `0` | — |
| `seller_reply` | varchar(800) | yes | — | — |
| `seller_replied_at` | datetime | yes | — | — |
| `edit_locked_at` | datetime | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_review_user_product_order` (user_id, product_id, seller_order_id) · INDEX `idx_reviews_product_status` (product_id, status, created_at) · INDEX `idx_reviews_user` (user_id) · INDEX `idx_reviews_sub` (seller_order_id)

**Foreign keys:** `product_id` → `products.id` ON DELETE CASCADE · `seller_order_id` → `seller_orders.id` ON DELETE CASCADE · `user_id` → `users.id` ON DELETE CASCADE

**Checks:** `chk_reviews_rating`: `rating` between 1 and 5

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 4*

### Support

#### `support_tickets`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `ticket_ref` | varchar(32) | no | — | unique |
| `user_id` | bigint(20) unsigned | no | — | — |
| `order_id` | bigint(20) unsigned | yes | — | — |
| `category` | enum(order,collection,delivery,payment,account,seller,other) | no | — | — |
| `subject` | varchar(190) | no | — | — |
| `priority` | enum(low,normal,high) | no | `normal` | — |
| `status` | enum(open,in_progress,waiting_customer,resolved,closed,escalated) | no | `open` | — |
| `assigned_to` | bigint(20) unsigned | yes | — | — |
| `escalated_to` | bigint(20) unsigned | yes | — | — |
| `escalation_reason` | varchar(1000) | yes | — | — |
| `resolved_at` | datetime | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (id) · UNIQUE `uq_ticket_ref` (ticket_ref) · INDEX `idx_tickets_user` (user_id, status) · INDEX `idx_tickets_status_time` (status, created_at) · INDEX `idx_tickets_assignee` (assigned_to, status) · INDEX `idx_tickets_order` (order_id) · INDEX `idx_tickets_escalatee` (escalated_to)

**Foreign keys:** `assigned_to` → `users.id` ON DELETE SET NULL · `escalated_to` → `users.id` ON DELETE SET NULL · `order_id` → `orders.id` ON DELETE SET NULL · `user_id` → `users.id` ON DELETE CASCADE

**Referenced by:** `support_messages.ticket_id` (CASCADE)

*Seeded rows: 5*

#### `support_messages`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `ticket_id` | bigint(20) unsigned | no | — | — |
| `author_user_id` | bigint(20) unsigned | yes | — | — |
| `author_role` | enum(customer,support,admin,seller,agent,system) | no | — | — |
| `body` | varchar(3000) | no | — | — |
| `is_internal` | tinyint(1) | no | `0` | — |
| `created_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · INDEX `idx_msgs_ticket_internal_time` (ticket_id, is_internal, created_at) · INDEX `idx_msgs_author` (author_user_id)

**Foreign keys:** `author_user_id` → `users.id` ON DELETE SET NULL · `ticket_id` → `support_tickets.id` ON DELETE CASCADE

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 11*

### Platform

#### `audit_log`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | — | auto-increment, **PK** |
| `actor_user_id` | bigint(20) unsigned | yes | — | — |
| `actor_role` | varchar(40) | yes | — | — |
| `action` | varchar(80) | no | — | — |
| `entity_type` | varchar(60) | yes | — | — |
| `entity_id` | varchar(64) | yes | — | — |
| `detail` | varchar(1000) | yes | — | — |
| `before_json` | text | yes | — | — |
| `after_json` | text | yes | — | — |
| `justification` | varchar(120) | yes | — | — |
| `ip_address` | varbinary(16) | yes | — | — |
| `user_agent` | varchar(255) | yes | — | — |
| `created_at` | datetime | no | `current_timestamp()` | — |

**Keys:** PRIMARY (id) · INDEX `idx_audit_actor_time` (actor_user_id, created_at) · INDEX `idx_audit_action_time` (action, created_at) · INDEX `idx_audit_entity` (entity_type, entity_id) · INDEX `idx_audit_time` (created_at)

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 9*

#### `settings`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `setting_key` | varchar(80) | no | — | **PK** |
| `setting_value` | varchar(500) | no | — | — |
| `value_type` | enum(string,int,decimal,bool,enum,json) | no | `string` | — |
| `setting_group` | varchar(40) | no | `General` | — |
| `description` | varchar(255) | yes | — | — |
| `updated_by` | bigint(20) unsigned | yes | — | — |
| `updated_at` | datetime | no | `current_timestamp()` | auto-updates |

**Keys:** PRIMARY (setting_key) · INDEX `idx_settings_group` (setting_group) · INDEX `idx_settings_updater` (updated_by)

**Foreign keys:** `updated_by` → `users.id` ON DELETE SET NULL

**Referenced by:** nothing — no table points at this one.

*Seeded rows: 14*
