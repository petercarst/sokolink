# User Roles and Permissions

**Status:** Phase 0 draft, awaiting approval
**Last updated:** 2026-09-21

---

## 1. The model

Three layers, checked in this order on every protected request:

1. **Authentication** — is there a valid session bound to an existing user?
2. **Role + permission** — does that user hold a role that grants this permission?
3. **Ownership / scope** — does this *specific record* belong to them?

Layer 3 is the one that is usually forgotten, and it is the one that causes real breaches. A seller
holding `order.view` must still be blocked from sub-order #4412 if it belongs to another seller. In
this codebase that check lives in the service layer, next to the data, not in the controller and
never in the template.

### 1.1 Storage

```
users            id, email, password_hash, status, ...
roles            id, key, name                       -- customer, seller, delivery_agent, support, admin
permissions      id, key, description                -- product.create, order.transition.accept, ...
role_permissions role_id, permission_id
user_roles       user_id, role_id, granted_at, granted_by
```

Roles are many-to-many with users (**FR-AUTH-11**: a seller can also buy as a customer).
Permissions are attached to roles, not directly to users, so there is one place to audit what a role
can do.

### 1.2 How a check actually runs

```php
// In a service, never only in a controller or view.
$this->permissions->require($actor, 'order.transition.ready_for_pickup');
$this->permissions->requireOwnsSellerOrder($actor, $sellerOrderId);
$this->stateMachine->transition($sellerOrder, OrderStatus::READY_FOR_PICKUP, $actor);
```

Roles are re-read from the database on each request by `SessionGuard`, which also re-checks
`users.status`. The session stores a user id, nothing else that matters. **A role is never read from
a cookie, a hidden form field, or a query parameter** (**NFR-SEC-05**). Suspending an account takes
effect on the suspended user's very next request, not at their next login.

### 1.3 What the UI does

Navigation and buttons are hidden when the permission is absent — that is a usability courtesy.
Hiding is never the control. Every route that a hidden button would have reached is independently
guarded, and `tests/Security/` probes those routes directly with the wrong role to prove it.

---

## 2. Roles

| Key | Name | Created by | Self-register | Default status on creation |
|---|---|---|---|---|
| `customer` | Customer | self | yes | `active` after email verification |
| `seller` | Seller / Vendor | self, then approved | yes | `pending_approval` |
| `delivery_agent` | Delivery Agent | admin | no | `active` |
| `support` | Customer Support / CRM Staff | admin | no | `active` |
| `admin` | System Administrator | seeded, then admin | no | `active` |

A seller in `pending_approval` can log in and complete their store profile, and can do nothing else —
no product creation, no order visibility. This is deliberate: it lets them prepare while an admin
reviews, without any ability to trade (**FR-AUTH-06**).

---

## 3. Permission matrix

`Y` = allowed. `O` = allowed **only for records they own or are assigned**. `R` = read-only.
`-` = denied. Blank cells in the "system" column mean the action is performed by the application
itself, not a person.

### 3.1 Account and identity

| Permission | Customer | Seller | Agent | Support | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| `account.register` | Y | Y | - | - | - |
| `account.profile.view` / `.edit` | O | O | O | O | O |
| `account.password.change` | O | O | O | O | O |
| `user.list` | - | - | - | R | Y |
| `user.view.any` | - | - | - | R (limited fields) | Y |
| `user.create` | - | - | - | - | Y |
| `user.role.assign` | - | - | - | - | Y |
| `user.suspend` / `user.reactivate` | - | - | - | - | Y |
| `user.impersonate` | - | - | - | - | - (not built in v1) |

### 3.2 Seller and store

| Permission | Customer | Seller | Agent | Support | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| `seller.application.submit` | Y | - | - | - | - |
| `seller.application.review` | - | - | - | - | Y |
| `seller.approve` / `seller.reject` | - | - | - | - | Y |
| `store.create` | - | O (after approval) | - | - | Y |
| `store.edit` | - | O | - | - | Y |
| `store.view.public` | Y | Y | Y | Y | Y |
| `store.hours.manage` | - | O | - | - | Y |
| `store.pickup_instructions.manage` | - | O | - | - | Y |

### 3.3 Catalogue and inventory

| Permission | Customer | Seller | Agent | Support | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| `category.browse` | Y | Y | Y | Y | Y |
| `category.manage` | - | - | - | - | Y |
| `product.browse` (published only) | Y | Y | Y | Y | Y |
| `product.create` | - | O | - | - | - |
| `product.edit` / `product.archive` | - | O | - | - | Y (moderation) |
| `product.image.upload` | - | O | - | - | - |
| `product.moderate` (flag / unpublish) | - | - | - | - | Y |
| `inventory.view` | - | O | - | R (availability only) | Y |
| `inventory.adjust` | - | O | - | - | Y (audited) |
| `stock_movement.view` | - | O | - | - | Y |

### 3.4 Cart, checkout, orders

| Permission | Customer | Seller | Agent | Support | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| `cart.manage` | O | O (as customer) | - | - | - |
| `order.place` | O | O (as customer) | - | - | - |
| `order.view` | O | O (their sub-orders) | O (assigned task only) | R (ticket customer only) | Y |
| `order.view.financials` | O (own totals) | O (their sub-order totals) | - | R (totals, no credentials) | Y |
| `order.transition.accept` / `.reject` | - | O | - | - | Y (audited override) |
| `order.transition.prepare` | - | O | - | - | - |
| `order.transition.ready_pickup` | - | O | - | - | - |
| `order.transition.ready_dispatch` | - | O | - | - | - |
| `order.cancel` | O (early states) | - | - | O (on behalf, audited) | Y |
| `order.history.view` | O | O | O (own tasks) | R | Y |
| `order.monitor.all` | - | - | - | - | Y |

### 3.5 Pickup

| Permission | Customer | Seller | Agent | Support | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| `pickup.code.view` | O (own, shown once) | - | - | - | - |
| `pickup.code.verify` | - | O (own store) | - | - | Y (audited override) |
| `pickup.mark_collected` | - | O (code required) | - | - | Y (audited override) |
| `pickup.instructions.view` | O | O | - | R | Y |

A customer can never mark their own order collected, and a seller can never mark it collected without
a valid code. That pairing is what makes the collection record trustworthy (**FR-PICK-03**).

### 3.6 Delivery

| Permission | Customer | Seller | Agent | Support | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| `delivery.zone.manage` | - | - | - | - | Y |
| `delivery.task.view` | O (own order status) | O (their sub-order) | **O (assigned only)** | R | Y |
| `delivery.task.assign` | - | - | - | - | Y |
| `delivery.task.accept` / `.decline` | - | - | O | - | - |
| `delivery.status.update` | - | - | O | - | Y (audited) |
| `delivery.confirm` | - | - | O (recipient code) | - | Y (audited) |
| `delivery.failure.report` | - | - | O | - | Y |
| `delivery.monitor.all` | - | - | - | R | Y |

### 3.7 Payments

| Permission | Customer | Seller | Agent | Support | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| `payment.initiate` | O | - | - | - | - |
| `payment.transaction.view` | O (own) | O (their sub-order amounts) | - | R (status only) | Y |
| `payment.refund.request` | O | O | - | Y | Y |
| `payment.refund.approve` | - | - | - | - | Y |
| `payment.cod.confirm_received` | - | O (pickup) | O (delivery) | - | Y |
| `payment.credentials.view` | - | - | - | **never** | **never** |

Nobody has `payment.credentials.view`. The permission does not exist as a grantable capability
because the data is never stored (**FR-PAY-07**).

### 3.8 Notifications and retention

| Permission | Customer | Seller | Agent | Support | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| `notification.inbox.view` | O | O | O | - | Y |
| `notification.preferences.manage` | O | O | O | O (on request, audited) | Y |
| `notification.consent.grant` / `.withdraw` | O | - | - | O (on request, audited) | - |
| `notification.unsubscribe` (tokenised, no login) | Y | Y | Y | - | - |
| `notification.delivery_log.view` | O (own) | - | - | R (status, no body secrets) | Y |
| `notification.settings.platform` | - | - | - | - | Y |
| `reminder.settings.product` | - | O | - | - | Y |
| `reminder.schedule.view` | O (own) | - | - | R | Y |
| `reorder.create` | O | - | - | - | - |

### 3.9 Reviews

| Permission | Customer | Seller | Agent | Support | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| `review.create` | O (verified purchase only) | - | - | - | - |
| `review.edit` | O (within edit window) | - | - | - | - |
| `review.reply` | - | O (their product, once) | - | - | Y |
| `review.report` | Y | Y | - | Y | Y |
| `review.moderate` | - | - | - | - | Y |

### 3.10 Support

| Permission | Customer | Seller | Agent | Support | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| `ticket.create` | Y | Y | Y | Y | Y |
| `ticket.view` | O | O | O | Y | Y |
| `ticket.reply` | O | O | O | Y | Y |
| `ticket.internal_note` | - | - | - | Y | Y |
| `ticket.assign` | - | - | - | Y | Y |
| `ticket.status.change` | - | - | - | Y | Y |
| `ticket.escalate` | - | - | - | Y | Y |
| `dispute.resolve` | - | - | - | - | Y |

### 3.11 Platform administration

| Permission | Customer | Seller | Agent | Support | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| `report.sales.view` | - | O (own sales only) | - | - | Y |
| `report.platform.view` | - | - | - | R (operational subset) | Y |
| `audit.view` | - | - | - | - | Y |
| `settings.view` / `settings.edit` | - | - | - | - | Y |
| `system.maintenance` | - | - | - | - | Y |

---

## 4. Data visibility boundaries

The permission tables say what an action is. This section says what the data query is *allowed to
return*, which is the part that actually prevents leaks.

### 4.1 Seller

**Can see:** their own stores, products, inventory, sub-orders and the lines within them; the
customer's first name, the delivery-relevant address for their own delivery sub-orders, and a masked
phone number; their own sales figures and reviews.

**Cannot see:** another seller's anything; the customer's email address; the customer's full order
(only the portion they are fulfilling); the customer's payment instrument; platform-wide totals; the
customer's other orders, even from the same customer.

Every seller-scoped query is written as `... WHERE seller_orders.seller_id = :actor_seller_id` at the
repository level. The seller id comes from the session-derived actor, never from the request.

### 4.2 Delivery agent

**Can see:** delivery tasks where `delivery_tasks.agent_id = :actor_id`, and for those only:
recipient name, delivery address, delivery instructions, a masked contact number, package count,
pickup store address, COD amount to collect if applicable.

**Cannot see:** item names or prices unless COD requires a total (then the total only, not the
itemisation); the customer's email; the customer's other orders; any unassigned task's details beyond
what an offer screen needs (zone, distance band, fee); any other agent's history.

### 4.3 Support staff

**Can see:** tickets and their messages; for the customer attached to an open ticket — their orders,
statuses, totals, fulfilment details, and notification delivery statuses.

**Cannot see:** password hashes, reset tokens, collection codes or delivery codes in plaintext
(codes are hashed and can only be *regenerated*, which is itself audited), any payment credential,
or customers with no open ticket assigned to them. Support access to a customer record writes an
audit entry every time (**FR-SUP-06**).

### 4.4 Customer

**Can see:** their own profile, addresses, cart, orders, payments, notifications, reminders, reviews
and tickets.

**Cannot see:** any other customer's data; seller cost or margin data; internal support notes;
another customer's reviews in draft.

### 4.5 Admin

**Can see:** everything except plaintext secrets, which do not exist in storage. Admin actions on
another user's data are all audited, and the audit log itself has no delete path in the application.

---

## 5. Sensitive-action rules

| Action | Extra control beyond the permission |
|---|---|
| Suspend / reactivate an account | Mandatory reason, audit entry, notification to the affected user |
| Approve / reject a seller | Mandatory reason on reject, audit entry, notification |
| Admin override of an order transition | Flagged `actor_type = admin_override` in history, mandatory reason, audit entry |
| Regenerate a collection or delivery code | Audit entry, old code invalidated, customer notified |
| Refund approval | Audit entry, immutable refund record, payment transaction linked |
| Edit platform settings | Before/after values recorded in the audit log |
| Support viewing a customer order | Audit entry with ticket id as the justification reference |
| Bulk export of any customer data | Not built in v1. If requested, admin-only, audited, rate-limited |

---

## 6. Account status effects

| Status | Can log in | Effect |
|---|---|---|
| `active` | yes | Normal |
| `pending_approval` (seller) | yes | Store profile only. No products, no orders, no inventory. Public store hidden |
| `pending_verification` (customer) | yes | Can browse and build a cart. Cannot place an order |
| `suspended` | no | Session destroyed on next request. Existing orders continue to be fulfilled; the account cannot act. Products are hidden from the catalogue |
| `closed` | no | Personal data minimised per the retention policy; order records retained for accounting integrity |

Suspension does not cascade into deleting business records. An order that exists must keep existing —
a customer's receipt and a seller's sales history are financial facts, not profile fields.

---

## 7. Seed accounts for development

Created by `database/seed.sql`, clearly marked as demo data, with a single shared development
password documented in the setup guide and **not** reused anywhere real.

| Role | Email | Purpose |
|---|---|---|
| Admin | `admin@sokolink.test` | Full administration |
| Seller A | `seller.mama.lishe@sokolink.test` | Approved, 2 stores, consumable-heavy catalogue |
| Seller B | `seller.duka.kuu@sokolink.test` | Approved, 1 store, used for multi-seller cart tests |
| Seller C | `seller.pending@sokolink.test` | `pending_approval`, used for approval-flow tests |
| Delivery agent 1 | `agent.juma@sokolink.test` | Has assigned tasks |
| Delivery agent 2 | `agent.neema@sokolink.test` | Used for cross-agent IDOR tests |
| Support | `support@sokolink.test` | Has open tickets |
| Customer 1 | `customer.asha@sokolink.test` | Purchase history, consent granted, reminder-eligible |
| Customer 2 | `customer.baraka@sokolink.test` | Consent withdrawn, used for suppression tests |

Customer 2 exists specifically so that "we do not message people who said no" is a testable
assertion, not a claim.
