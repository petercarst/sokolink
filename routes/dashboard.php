<?php

declare(strict_types=1);

/**
 * Phase 1b - role dashboard routes.
 *
 * The customer group is protected as of Phase 4: `auth` + `role:customer`, with
 * the ownership filter (WHERE orders.user_id = :actor) inside the repository
 * behind it. The route prefix is organisation; the filter is the access
 * control, and both are present.
 *
 * The seller group is protected as of Phase 4b, with the same two-layer
 * pattern: `auth` + `role:seller` on the route, and `WHERE seller_id = :actor`
 * inside every query behind it.
 *
 * The delivery group is protected as of Phase 4c, on the same pattern:
 * `auth` + `role:delivery_agent` on the route, and
 * `WHERE agent_user_id = :actor` inside every query behind it.
 *
 * The support group is protected as of Phase 4d. It is the exception to the
 * two-layer pattern: the route gate applies, but there is no ownership filter
 * behind it, because support exists to see other people's records. The audit
 * trail is the control instead.
 *
 * The admin group is NOT protected yet and still reads sample data. Those
 * screens carry a banner saying so, and are gated when 4e wires them.
 *
 * Detail routes are named as CHILDREN of their list route
 * (seller.orders -> seller.orders.show) so the sidebar keeps the right section
 * highlighted while a detail page is open.
 */

use App\Controllers\Admin\AdminController;
use App\Controllers\Customer\CustomerController;
use App\Controllers\Delivery\DeliveryController;
use App\Controllers\Seller\SellerController;
use App\Controllers\Support\SupportController;
use App\Core\Router;

// ---- Customer ---------------------------------------------------------------
Router::group(['middleware' => ['auth', 'role:customer']], function (): void {
    Router::get('/customer',               [CustomerController::class, 'dashboard'],     'customer.dashboard');
    Router::get('/customer/orders',        [CustomerController::class, 'orders'],        'customer.orders');
    Router::get('/customer/orders/{ref}',  [CustomerController::class, 'order'],         'customer.orders.show');
    Router::get('/customer/history',       [CustomerController::class, 'history'],       'customer.history');
    Router::get('/customer/reorder',       [CustomerController::class, 'reorder'],       'customer.reorder');
    Router::post('/customer/reorder/add',  [CustomerController::class, 'reorderAdd'],    'customer.reorder.add');
    Router::get('/customer/payments',      [CustomerController::class, 'payments'],      'customer.payments');
    Router::get('/customer/profile',       [CustomerController::class, 'profile'],       'customer.profile');
    Router::get('/customer/addresses',     [CustomerController::class, 'addresses'],     'customer.addresses');
    Router::get('/customer/notifications', [CustomerController::class, 'notifications'], 'customer.notifications');
    Router::get('/customer/preferences',   [CustomerController::class, 'preferences'],   'customer.preferences');
    Router::post('/customer/preferences',  [CustomerController::class, 'savePreferences'], 'customer.preferences.save');
    Router::post('/customer/unsubscribe',  [CustomerController::class, 'unsubscribe'],     'customer.unsubscribe');
    Router::post('/customer/notifications/read', [CustomerController::class, 'markNotificationsRead'], 'customer.notifications.read');
    Router::get('/customer/reviews',       [CustomerController::class, 'reviews'],       'customer.reviews');
    Router::get('/customer/reviews/write', [CustomerController::class, 'writeReview'],   'customer.reviews.write');
    Router::get('/customer/support',       [CustomerController::class, 'tickets'],       'customer.tickets');
    Router::get('/customer/support/{ref}', [CustomerController::class, 'ticket'],        'customer.tickets.show');
    Router::post('/customer/support/open',  [CustomerController::class, 'openTicket'],    'customer.tickets.open');
    Router::post('/customer/support/reply', [CustomerController::class, 'replyToTicket'], 'customer.tickets.reply');
});

// ---- Seller -----------------------------------------------------------------
// The role gate gets an applicant as far as their dashboard, which is
// deliberate: somebody awaiting approval should be able to see the account they
// applied for and prepare a catalogue. What they cannot do is trade, and that
// is checked by `sellers.status` inside each service rather than here - a route
// gate cannot tell the difference between "has the role" and "is approved".
Router::group(['middleware' => ['auth', 'role:seller']], function (): void {
    Router::get('/seller',               [SellerController::class, 'dashboard'],    'seller.dashboard');

    Router::get('/seller/orders',        [SellerController::class, 'orders'],       'seller.orders');
    Router::get('/seller/orders/{ref}',  [SellerController::class, 'order'],        'seller.orders.show');
    Router::post('/seller/orders/accept',  [SellerController::class, 'accept'],     'seller.orders.accept');
    Router::post('/seller/orders/reject',  [SellerController::class, 'reject'],     'seller.orders.reject');
    Router::post('/seller/orders/prepare', [SellerController::class, 'prepare'],    'seller.orders.prepare');
    Router::post('/seller/orders/ready',   [SellerController::class, 'markReady'],  'seller.orders.ready');

    Router::get('/seller/pickup',        [SellerController::class, 'pickup'],       'seller.pickup');
    Router::get('/seller/pickup/verify', [SellerController::class, 'pickupVerify'], 'seller.pickup.verify');
    // Throttled as well as counted per order: the per-order attempt cap in
    // PickupService stops one order being guessed at, this stops one counter
    // working through several orders quickly.
    Router::post('/seller/pickup/verify', [SellerController::class, 'confirmCollection'], 'seller.pickup.confirm', ['throttle:20,300']);
    Router::get('/seller/dispatch',      [SellerController::class, 'dispatch'],     'seller.dispatch');

    Router::get('/seller/products',      [SellerController::class, 'products'],     'seller.products');
    Router::get('/seller/products/edit', [SellerController::class, 'productForm'],  'seller.products.form');
    Router::post('/seller/products',     [SellerController::class, 'saveProduct'],  'seller.products.save');
    Router::post('/seller/products/publish', [SellerController::class, 'publishProduct'], 'seller.products.publish');
    Router::post('/seller/products/archive', [SellerController::class, 'archiveProduct'], 'seller.products.archive');

    Router::get('/seller/inventory',     [SellerController::class, 'inventory'],    'seller.inventory');
    Router::post('/seller/inventory/receive', [SellerController::class, 'receiveStock'], 'seller.inventory.receive');
    Router::post('/seller/inventory/adjust',  [SellerController::class, 'adjustStock'],  'seller.inventory.adjust');

    Router::get('/seller/stores',        [SellerController::class, 'stores'],       'seller.stores');
    Router::get('/seller/stores/{slug}', [SellerController::class, 'storeEdit'],    'seller.stores.edit');
    Router::get('/seller/reminders',     [SellerController::class, 'reminders'],    'seller.reminders');
    Router::get('/seller/settings',      [SellerController::class, 'settings'],     'seller.settings');
    Router::post('/seller/settings/orders', [SellerController::class, 'saveOrderHandling'], 'seller.settings.orders');
    Router::get('/seller/reports',       [SellerController::class, 'reports'],      'seller.reports');
    Router::get('/seller/reviews',       [SellerController::class, 'reviews'],      'seller.reviews');
    Router::post('/seller/reviews/reply', [SellerController::class, 'replyToReview'], 'seller.reviews.reply');
});

// ---- Delivery agent ---------------------------------------------------------
// The agent id comes from the session, and every query behind these routes
// filters on it. The gate says "agents only"; the filter says "and only yours".
Router::group(['middleware' => ['auth', 'role:delivery_agent']], function (): void {
    Router::get('/delivery',                    [DeliveryController::class, 'dashboard'],  'delivery.dashboard');

    Router::get('/delivery/tasks',              [DeliveryController::class, 'tasks'],      'delivery.tasks');
    Router::get('/delivery/tasks/{ref}',        [DeliveryController::class, 'task'],       'delivery.tasks.show');
    Router::get('/delivery/tasks/{ref}/failed', [DeliveryController::class, 'failReport'], 'delivery.tasks.fail');

    Router::post('/delivery/tasks/picked-up',   [DeliveryController::class, 'pickedUp'],        'delivery.tasks.pickedup');
    Router::post('/delivery/tasks/out',         [DeliveryController::class, 'outForDelivery'],  'delivery.tasks.out');
    // Throttled as well as counted per task: the per-task attempt cap in
    // DeliveryService stops one delivery being guessed at, this stops one
    // handset working through several quickly.
    Router::post('/delivery/tasks/deliver',     [DeliveryController::class, 'confirmDelivery'], 'delivery.tasks.deliver', ['throttle:20,300']);
    Router::post('/delivery/tasks/failed',      [DeliveryController::class, 'reportFailure'],   'delivery.tasks.failed');
    Router::post('/delivery/tasks/retry',       [DeliveryController::class, 'retry'],           'delivery.tasks.retry');

    Router::get('/delivery/offers',             [DeliveryController::class, 'offers'],     'delivery.offers');
    Router::post('/delivery/offers/claim',      [DeliveryController::class, 'claim'],      'delivery.offers.claim');
    Router::post('/delivery/offers/decline',    [DeliveryController::class, 'decline'],    'delivery.offers.decline');

    Router::get('/delivery/history',            [DeliveryController::class, 'history'],    'delivery.history');
});

// ---- Support ----------------------------------------------------------------
// Support is the one area NOT scoped to a subset of rows - a desk that can
// only see its own tickets cannot answer the phone. The control here is the
// audit trail: every look at a customer's order is recorded against the ticket
// that justified it, and SupportService refuses a look with no justification.
Router::group(['middleware' => ['auth', 'role:support']], function (): void {
    Router::get('/support',               [SupportController::class, 'dashboard'],     'support.dashboard');

    Router::get('/support/tickets',       [SupportController::class, 'tickets'],       'support.tickets');
    Router::get('/support/tickets/{ref}', [SupportController::class, 'ticket'],        'support.tickets.show');
    Router::post('/support/tickets/reply',    [SupportController::class, 'reply'],    'support.tickets.reply');
    Router::post('/support/tickets/assign',   [SupportController::class, 'assign'],   'support.tickets.assign');
    Router::post('/support/tickets/resolve',  [SupportController::class, 'resolve'],  'support.tickets.resolve');
    Router::post('/support/tickets/escalate', [SupportController::class, 'escalate'], 'support.tickets.escalate');

    Router::get('/support/orders',        [SupportController::class, 'orders'],        'support.orders');
    Router::get('/support/orders/{ref}',  [SupportController::class, 'order'],         'support.orders.show');
    Router::post('/support/orders/reissue-code', [SupportController::class, 'reissueCode'], 'support.orders.reissue');
    Router::get('/support/notifications', [SupportController::class, 'notifications'], 'support.notifications');
    Router::get('/support/escalations',   [SupportController::class, 'escalations'],   'support.escalations');
});

// ---- Administrator ----------------------------------------------------------
Router::get('/admin',                [AdminController::class, 'dashboard'],     'admin.dashboard');
Router::get('/admin/users',          [AdminController::class, 'users'],         'admin.users');
Router::get('/admin/users/{id}',     [AdminController::class, 'user'],          'admin.users.show');
Router::get('/admin/approvals',      [AdminController::class, 'approvals'],     'admin.approvals');
Router::get('/admin/approvals/{id}', [AdminController::class, 'approval'],      'admin.approvals.show');
Router::get('/admin/stores',         [AdminController::class, 'stores'],        'admin.stores');
Router::get('/admin/categories',     [AdminController::class, 'categories'],    'admin.categories');
Router::get('/admin/products',       [AdminController::class, 'products'],      'admin.products');
Router::get('/admin/orders',         [AdminController::class, 'orders'],        'admin.orders');
Router::get('/admin/orders/{ref}',   [AdminController::class, 'order'],         'admin.orders.show');
Router::get('/admin/deliveries',     [AdminController::class, 'deliveries'],    'admin.deliveries');
Router::get('/admin/zones',          [AdminController::class, 'zones'],         'admin.zones');
Router::get('/admin/payments',       [AdminController::class, 'payments'],      'admin.payments');
Router::get('/admin/disputes',       [AdminController::class, 'disputes'],      'admin.disputes');
Router::get('/admin/notifications',  [AdminController::class, 'notifications'], 'admin.notifications');
Router::get('/admin/reports',        [AdminController::class, 'reports'],       'admin.reports');
Router::get('/admin/audit',          [AdminController::class, 'audit'],         'admin.audit');
Router::get('/admin/settings',       [AdminController::class, 'settings'],      'admin.settings');
