# Backend Modules

**Status:** Phase 3 complete, awaiting approval
**Last updated:** 2026-09-22
**Test suite:** `php tests/run.php` — 513 assertions, 10 suites

What each service does, what it refuses to do, and how to check. The layering
rules are in [SYSTEM_ARCHITECTURE.md](SYSTEM_ARCHITECTURE.md) §1; this document
is the module-by-module reference the roadmap asks for.

---

## How the layers fit together

```
HTTP request
   ↓
Router            named routes, {param} patterns, middleware per route or group
   ↓
Middleware        auth · guest · role:x · can:x · verified · csrf · throttle:n,s
   ↓
Controller        parses input, calls ONE service, picks a view. No SQL, no rules.
   ↓
Service           business rules, transactions, authorisation of the ACTION
   ↓
Repository        the only layer that writes SQL. Scoping lives in WHERE clauses.
   ↓
Database          constraints that hold when the code above is wrong
```

Four rules, and they are testable rather than aspirational:

1. **A controller never writes SQL.** Every query is in `app/Repositories/`.
2. **A service never emits HTML.** Services return arrays and throw exceptions
   with messages written for a person.
3. **A repository never reads the request.** An owner id is a method parameter,
   and it comes from `Auth`, which reads the session.
4. **A scoping condition is in the SQL.** Rows a caller may not see are never
   fetched, rather than fetched and filtered afterwards.

### Where authorisation actually happens

Three layers, doing three different jobs. All three are needed:

| Layer | Question it answers | Example |
|---|---|---|
| Middleware | May this account reach this area at all? | `role:seller` on the seller dashboard |
| Service | May this actor take this action on this row? | `OrderService::requireOwnedBy()` |
| Repository | Which rows exist for this actor? | `WHERE so.seller_id = :seller` |

The middleware is the coarse gate. The service decides whether an action is
legal. The repository makes sure a wrong id returns nothing — the same answer as
an id that does not exist, so nothing leaks through the difference.

---

## Core (`app/Core/`)

| Class | Responsibility | The part that matters |
|---|---|---|
| `Database` | The single PDO connection | Sets `STRICT_ALL_TABLES` and `time_zone='+00:00'` on every connect; `transaction()` nests with SAVEPOINTs; `requireTransaction()` refuses to let stock work run outside one |
| `Auth` | Who the actor is | Roles and permissions are read from the database **per request**, never cached in the session, so a suspension takes effect on the next request |
| `Validator` | Server-side input rules | `validated()` returns only fields that had rules, so an extra posted field cannot reach an INSERT |
| `Token` | Secrets and their storage form | Generates codes from an alphabet with no 0/O or 1/I/L; hashes with SHA-256; compares with `hash_equals` |
| `RateLimiter` | Throttling | Backed by `auth_attempts`, not the session — a session is what an attacker does not reuse |
| `Audit` | The append-only trail | Redacts anything password-, token- or code-shaped before writing |
| `Csrf` | Synchroniser tokens | Verified by middleware on **every** POST, not opted into per route |
| `Config` | Settings from `.env` | `set()` for tests and CLI only; never writes back |

### Middleware

| Key | Effect |
|---|---|
| `auth` | Requires a session. Remembers the intended URL **in the session**, not the query string, so it cannot be used as an open redirect |
| `guest` | Login/register pages; sends a signed-in user to their dashboard |
| `role:seller` or `role:support,admin` | Coarse area gate |
| `can:order.transition.accept` | Permission gate. Preferred — permissions are data, roles are not |
| `verified` | Requires a confirmed email. Applied to actions that commit the platform, not to browsing |
| `csrf` | Automatic on every POST |
| `throttle:10,60` | Session-backed, for expensive-not-sensitive routes |

---

## Domain (`app/Domain/`)

**35 PHP enums** mirroring every meaningful ENUM column, generated from the
schema so they agreed on the day they were written, and asserted equal by
`tests/Integration/domain_test.php` so they keep agreeing.

### `OrderStateMachine`

The transition rules as a table, not as scattered conditionals. Three checks on
every move:

1. Is the target reachable from the current status?
2. Does the fulfilment method allow it? (`ready_for_pickup` is meaningless on a
   delivery order.)
3. Is this actor allowed to make it?

`admin_override` is a separate actor type from `admin` on purpose: an override
is recorded as one, so a forced transition is visible in the history rather than
looking like a normal step.

The machine also answers `releasesStock()`, `consumesStock()`,
`requiresRefund()` and `requiresReason()`, so `OrderService` asks the rules
rather than restating them — adding a status in one place cannot leave stock
handling behind in another.

---

## Services

### `AuthService` — signing in

| Method | Notes |
|---|---|
| `attempt($email, $password, $ip)` | Returns the user row, throws on any failure |
| `logout()` | |
| `changePassword($current, $new)` | Requires the current password |
| `assertPasswordAcceptable($password, $email)` | Length over composition |

**Refuses:** a wrong password, an unknown address, a suspended account and a
closed account — all with the *same* message, so the form cannot be used to
discover which addresses have accounts. Also refuses everything once five
failures have been recorded for that email in fifteen minutes, including the
correct password.

**Deliberate details.** The throttle is checked *before* the bcrypt comparison,
because that comparison is the work an attacker wants us to do. An unknown email
still runs a dummy `password_verify` so a missing account and a wrong password
take about the same time. `pending_approval` accounts *can* sign in — a seller
waiting on a decision should see where it has got to; what they cannot do is
trade, and that is gated on `sellers.status`.

### `RegistrationService` — creating accounts

`registerCustomer()`, `registerSeller()`, `verifyEmail()`, `resendVerification()`.

Both registrations run in one transaction: a user row with no role, or a seller
application with no user, is the kind of thing discovered weeks later by
somebody who cannot log in and cannot be helped.

**Consent is recorded at the moment it is given**, with its source, and a refusal
is a `granted = 0` row rather than a missing row. "They never agreed" is then a
fact on file rather than the absence of one.

### `PasswordResetService`

`request()` returns the token when one was issued and `null` otherwise — and the
caller must not branch on that for anything the user sees. The page says "if
that address has an account, we have sent a link" either way.

**Refuses:** a reset for a suspended or closed account (letting somebody set a
new password on a suspended account would undo the suspension), more than three
requests in ten minutes, and a replayed link.

### `CatalogService` / `ProductRepository`

Public reads only ever return `products.status = 'published'` from
`sellers.status = 'active'`. That condition is a constant in the repository,
used by every public query, so "can a suspended seller's product still be
bought?" has one answer in one place.

Sorting goes through an allow-list, because `ORDER BY` cannot be parameterised.
Search strips fulltext boolean operators before building the query — a customer
searching for `" OR 1=1 --` is not attacking anything, but an unbalanced quote
is a **syntax error** from the fulltext parser, which would be a 500 on a search
box.

### `InventoryService` — the oversell guard

| Method | Notes |
|---|---|
| `reserveAll($lines, $reference)` | All lines or none. Must be inside a transaction |
| `releaseAll()` | Never throws — it runs while an order is already failing |
| `consumeAll()` | At handover only |
| `receiveStock()` / `adjustStock()` | Seller-scoped, both ids checked |

`reserveAll()` is where the brief's concurrency requirement is honoured:

1. Lock every line with `SELECT … FOR UPDATE`, **sorted by product id** — two
   checkouts containing the same two products in different orders would
   otherwise deadlock.
2. Reserve each line with the quantity guard repeated inside the `UPDATE`'s
   `WHERE`, requiring exactly one affected row.
3. Any failure throws, so the caller's transaction rolls the rest back.

**Refuses:** running outside a transaction; an adjustment that would take stock
below what is already promised to orders (it says how many are promised rather
than silently clamping); a seller touching another seller's product or store.

### `CartService` — the basket

Two things it will not do, both deliberate:

1. **It never accepts a price.** Adding an item reads the price from the product
   row; quantity is the only number the customer controls.
2. **It never reserves stock.** A basket is an intention. Reserving on "add to
   basket" would let one browser hold a shop's entire stock indefinitely.

`summary()` returns problems rather than throwing, so a basket with one sold-out
line still renders with that line flagged, and all the problems are reported
together rather than one per round trip.

### `PricingService` — every figure the customer is charged

Integer minor units internally, `DECIMAL`-shaped strings out. Adding three
floats and comparing the result to a fourth is how a basket ends up a shilling
short of its own lines.

Delivery fees come from `delivery_zones`: free over the zone's threshold, plus a
surcharge over its weight threshold, otherwise the base fee. **An address in no
known zone throws** rather than getting a guessed fee — a delivery promised into
an area with no agents is worse than no delivery offer.

Commission is computed with `intdiv` on scaled integers and recorded per
sub-order from the first order. Payouts are not built (OQ-04); computing the
number later from a rate that has since changed would give the wrong answer for
every historical order with no way to tell.

### `CheckoutService` — basket to order

One transaction, in this order: check the idempotency key → re-read every price
from the product rows → reserve stock under the row lock → write `orders`,
`seller_orders`, `order_items`, the pickup or delivery row and the first history
entry → mark the basket converted.

The outcomes are "a complete order with its stock held" and "nothing happened".
There is no state in between.

**Idempotency.** The key is hashed with the user id and the endpoint, so the
same key from two customers is two different keys. The fast path is a lookup;
the *guarantee* is the `UNIQUE` index on `idempotency_keys.key_hash` — if two
requests race past the lookup, the second INSERT fails and its whole
transaction, order included, rolls back.

**Refuses:** an empty basket, a delisted product (by name), a store that does
not offer the chosen method, a delivery with no address, an invented fulfilment
method, and stock that went between the basket page and the Pay button.

### `OrderService` — the order lifecycle

`transition()` is the only way a status changes. Nothing writes
`seller_orders.status` directly. That single funnel guarantees, everywhere:

- the state machine allows the move;
- the actor may make it;
- the `UPDATE` is guarded on the current status, so two dashboards cannot both
  act on the same order — the second gets zero rows and is told;
- a history row is written;
- stock is released or consumed as the new status requires;
- the customer is told, for the statuses they need to hear about.

A rejection or cancellation moves on to `refund_pending` **only if the order was
actually paid**. Cash on collection is not money we hold, so it is not money we
owe back.

### `PickupService` — click and collect

The brief: "do not allow arbitrary users to mark orders as collected." Four
things enforce it, and none is a hidden button:

1. The code is generated once, when the seller marks the order ready, and the
   plaintext is returned **exactly once** — to be sent to the customer.
2. Only a SHA-256 hash is stored. Nobody — seller, support or administrator —
   can read a code back; they can verify one that is presented, or issue a new
   one, and reissues are counted and audited.
3. Verification is scoped to the seller who owns the order, and throttled to
   five attempts.
4. The state machine allows `collected` only from `ready_for_pickup`, only for
   a seller actor.

**The throttle counts outside the transaction, on purpose.** Counting inside
would be undone by the very rollback a wrong code causes — the limiter would
reset itself on every failure and guard nothing. This was a real defect found by
the test that drives five wrong codes and then the right one.

### `DeliveryService` — home delivery

`agent_user_id` is the scoping key for the whole role. An agent who guesses
another agent's task id gets "not found", and the task never appears in their
list.

What an agent's query returns: a name, a phone number, an address, the store to
collect from. Not an email address, not an order history, not what was paid. The
`SELECT` in `DeliveryRepository::forAgent()` **is** the access control.

Failed attempts carry a reason from a fixed list, so "why do deliveries fail in
this zone?" is a query. Once the zone's attempt allowance is used, the parcel
goes back to the seller and a refund is queued — rather than being retried
forever with nobody told.

### `PaymentService` and `app/Services/Payment/`

> **No real payment provider is connected.** Two drivers ship: `SandboxGateway`,
> which simulates locally and says so on every screen it touches, and
> `CashOnFulfilmentGateway`, which is real because the money changes hands
> between two people. Mobile money appears in the checkout as **unavailable with
> the reason stated** — not hidden, and not a button that does nothing.

Four rules:

1. **A browser never confirms a payment.** `confirmPayment()` takes a payload
   and a signature. There is no method that takes "success".
2. **A replayed callback cannot credit an order twice.** The `UNIQUE (gateway,
   gateway_reference)` index enforces it; the duplicate-key error is caught and
   reported as `already_processed`, because the caller is a gateway retrying and
   it should stop.
3. **The amount is checked against the order.** A verified callback for the
   wrong amount is recorded `flagged_for_review` and marks nothing paid.
4. **An unverified callback is recorded, never acted on**, with
   `signature_verified = 0` — so a forged callback arriving is visible
   afterwards.

Signatures are compared with `hash_equals`. A provider payload is stripped of
anything card-, CVV- or PIN-shaped before storage: a well-behaved provider never
sends one, but we do not control what arrives.

`expireUnpaidOrders()` releases the stock. An abandoned checkout that keeps its
reservation takes units off the shelf for an order nobody will pay for.

### `Retention\ConsumptionEstimator` — when might they need this again?

The brief: "do not automatically assume that a customer has run out merely
because a fixed number of days has passed." So there is no fixed number of days.
There is ranked evidence:

| Rank | `basis` | Source |
|---|---|---|
| 1 | `observed_interval` | The **median** of this customer's own repeat gaps for this product. Needs two fulfilled purchases |
| 2 | `seller_hint` | `products.typical_consumption_days`, **scaled by quantity** — three bottles last three times as long |
| 3 | `category_default` | `categories.default_consumption_days`, scaled |
| 4 | `none` | Schedule **nothing** |

Median, not mean: one holiday when somebody bought a month early should not drag
every future estimate forward. Gaps under three days are discarded as top-ups
rather than treated as a daily habit; gaps over a year are not a routine.

The reminder is scheduled ~15% **before** the estimate runs out. One that
arrives the morning after somebody ran out is a reminder they no longer need.

### `Retention\ReminderService`

`schedule()` and `dispatch()`, both plain methods with no session, called from
`bin/console.php`. Running either twice changes nothing.

**Seven suppression rules, each writing a `skip_reason`:**

| Order | Reason | Meaning |
|---|---|---|
| 1 | `no_consent` / `consent_withdrawn` | The latest `consent_records` row. Two reasons because they are two different facts |
| 2 | `no_consent` | Consent held, but the reorder channel is switched off |
| 3 | `already_repurchased` | They bought it again |
| 4 | `unavailable` | Delisted, or sold out everywhere |
| 5 | `cooldown` | Too soon after the last reminder |
| 6 | `frequency_cap` | Enough messages this month |
| 7 | `quiet_hours` | In the customer's **local** time, not the server's |

An engine that quietly sends nothing and one that is working correctly look
identical without that column.

### `NotificationService`

**Email works.** The `log` driver writes the whole message to
`storage/logs/mail.log` and the log line says plainly that nothing was sent; the
`smtp` driver posts to a real server.

**SMS and WhatsApp are not connected.** Messages queued for those channels are
marked `skipped` with `skipped_no_provider`. They are not marked delivered,
`sent_at` stays null, and `channelStatus()` says so on the settings screen.

### `SupportService`

A customer's view of a thread excludes internal notes **in SQL**. Read that
again before moving the filter into a template: a template-level hide is one
refactor, one JSON endpoint or one "export this thread" feature away from
showing a customer what staff wrote about them.

An order lookup takes a **mandatory justification** which is written to the
audit trail before the data is returned — the difference between an access
policy and one somebody remembers to follow.

### `AdminService`

Approvals, moderation, suspensions, settings, audit reads. Every action records
before/after values: the privilege that needs the least explaining usually needs
the most record.

Two things an administrator still cannot do, enforced rather than discouraged:
read a collection or delivery code (nothing can), and edit an audit entry,
status history row or stock movement (no update path exists in any layer).

`SettingsRepository` refuses any key containing `password`, `secret`, `token`,
`api_key`, `private_key` or `credential`, so the admin screen cannot be used to
smuggle a credential into a table anyone with database access can read.

---

## Scheduled tasks (`bin/console.php`)

Everything time-based runs here and nothing runs from a browser. That is the
brief's requirement for reminders, and it applies to the rest too: an unpaid
order should expire whether or not anyone is looking at the site.

```bash
php bin/console.php list                      # what exists, and how often it should run
php bin/console.php <task> [--limit=N] [--quiet]
```

| Task | Suggested schedule | What it does |
|---|---|---|
| `expire-unpaid-orders` | every 5 minutes | Expires unpaid orders and **releases their stock** |
| `send-notifications` | every 2 minutes | Drains the message queue |
| `mark-overdue-collections` | hourly | Flags collections whose window closed |
| `send-reminders` | hourly during the day | Sends what is due, or records why not |
| `schedule-reminders` | daily, early | Estimates next-due dates |
| `prune-expired-tokens` | daily | Consumed tokens and old login attempts |
| `abandon-stale-carts` | weekly | Guest baskets only; account baskets are left |

Exit codes: `0` success, `1` the task failed (logged with a reference), `2`
unknown task. A failure is loud in the log and non-zero to the scheduler, and
prints no stack trace into whatever collects cron output.

**Every task is idempotent.** Cron will eventually run something twice, and the
second run must not send a second reminder — which is asserted by running
`schedule-reminders` twice and checking the second run schedules zero.

Scheduled work is attributed to the system: `Auth::actAs(null)`, so audit rows
written by a task have no human actor, which is the truth.

### Windows Task Scheduler

```
schtasks /create /tn "SokoLink notifications" /sc minute /mo 2 ^
  /tr "C:\xampp\php\php.exe C:\xampp\htdocs\e-commerce\bin\console.php send-notifications --quiet"
```

### cron

```cron
*/2 * * * *  php /var/www/sokolink/bin/console.php send-notifications --quiet
*/5 * * * *  php /var/www/sokolink/bin/console.php expire-unpaid-orders --quiet
0   * * * *  php /var/www/sokolink/bin/console.php mark-overdue-collections --quiet
0 8-19 * * * php /var/www/sokolink/bin/console.php send-reminders --quiet
30  5 * * *  php /var/www/sokolink/bin/console.php schedule-reminders --quiet
0   3 * * *  php /var/www/sokolink/bin/console.php prune-expired-tokens --quiet
0   4 * * 0  php /var/www/sokolink/bin/console.php abandon-stale-carts --quiet
```

---

## Error handling

| Exception | Meaning | What the user sees |
|---|---|---|
| `ValidationException` | Input they can fix | Per-field messages beside the fields |
| `DomainRuleException` | A business rule said no | The message, which is written for them |
| `HttpException` | 403, 404, 405, 429 | The matching error page |
| Anything else | A fault | A generic page with a reference; the detail goes to the log |

`DomainRuleException` carries a machine-readable `reason()` for the few places a
caller must branch, but the message is always the thing to show. A service that
returns `false` for "sold out" and `false` for "not your order" forces the
controller to invent the wording, and the wording is where the care goes.

---

## Testing

```bash
php tests/run.php          # every suite
php tests/run.php --quiet  # totals only
```

| Suite | Assertions | Covers |
|---|---|---|
| `tests/Unit/core_test.php` | 37 | Validator, Token, transactions, connection settings |
| `tests/Integration/domain_test.php` | 33 | Enum parity, the state machine's refusals |
| `tests/Integration/schema_contracts_test.php` | 43 | Phase 2 schema guarantees |
| `tests/Integration/auth_test.php` | 73 | Sign-in, throttling, registration, reset, RBAC |
| `tests/Integration/catalog_test.php` | 50 | Browsing, search, reservation, seller scoping |
| `tests/Integration/checkout_test.php` | 73 | Pricing, checkout, duplicates, lifecycle |
| `tests/Integration/fulfilment_test.php` | 72 | Codes, delivery scoping, payment callbacks |
| `tests/Integration/retention_test.php` | 62 | The estimator, suppression, messaging honesty |
| `tests/Integration/support_admin_test.php` | 63 | Internal notes, approvals, settings, the CLI |
| `tests/Concurrency/oversell_test.php` | 7 | Two live connections racing for the last unit |

Every test runs against the real `sokolink` database inside a transaction that
is rolled back, so the suite is re-runnable without a reimport. They are
integration tests on purpose: the guarantees are enforced by constraints and
transactions, and a mocked PDO would prove nothing about either.

**What is NOT covered yet.** No controller, route or view is exercised — the
frontend still runs on Phase 1 sample data and is wired up in Phase 4. There are
no browser tests, no load tests, and no test of a real payment provider, because
none is connected.
