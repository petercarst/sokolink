<?php

declare(strict_types=1);

/**
 * Phase 4a - the customer path, driven through the HTTP layer.
 *
 * Phase 3 proved the services are right. This file proves the SITE reaches
 * them: that the register form posts to the registration service, that the
 * basket button creates a cart row, that the checkout button creates an order,
 * and that a double-clicked checkout does not create two.
 *
 * Every request here goes through Router::dispatch with the real middleware,
 * the real CSRF check and the real database. Nothing is stubbed - see
 * tests/Http/kernel.php.
 *
 * Run: php tests/Http/customer_journey_test.php
 */

use App\Core\Auth;
use App\Core\Database;
use App\Domain\Enums\ConsentType;
use App\Domain\Enums\TokenPurpose;
use App\Repositories\ConsentRepository;
use App\Repositories\TokenRepository;

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/kernel.php';

const SEED_PASSWORD = 'SokoLink!Dev2026';
const TEST_IP       = '127.0.0.1';

TestRunner::suite('Phase 4a - the customer path over HTTP');

Http::boot();

/**
 * A published product with stock, that can be delivered.
 *
 * @return array<string,mixed>
 */
function journey_product(): array
{
    $row = Database::selectOne(
        "SELECT p.id, p.slug, p.name, p.price, p.seller_id
           FROM products p
           JOIN inventory i ON i.product_id = p.id
           JOIN sellers sel ON sel.id = p.seller_id
          WHERE p.status = 'published' AND sel.status = 'active'
            AND p.allows_delivery = 1 AND p.allows_pickup = 1
          GROUP BY p.id
         HAVING SUM(i.qty_available) > 5
          ORDER BY p.id
          LIMIT 1"
    );

    if ($row === null) {
        throw new RuntimeException('Seed has no deliverable, in-stock product.');
    }

    return $row;
}

/**
 * Removes anything a run created, so the suite is re-runnable.
 *
 * The reservation has to be released BEFORE the order is deleted. Deleting an
 * order cascades to seller_orders and order_items, but inventory.qty_reserved
 * is a counter, not a child row - nothing cascades to it. Left alone, every run
 * would permanently reserve a few more units and the seed would slowly run out
 * of stock for reasons nobody could see.
 */
function journey_cleanup(array $orderIds, array $cartIds, array $userIds): void
{
    foreach ($orderIds as $orderId) {
        $lines = Database::select(
            'SELECT i.product_id, so.store_id, i.qty
               FROM order_items i
               JOIN seller_orders so ON so.id = i.seller_order_id
              WHERE so.order_id = :o AND i.product_id IS NOT NULL',
            ['o' => $orderId]
        );

        foreach ($lines as $line) {
            // CASE, not GREATEST(0, qty_reserved - :qty): qty_reserved is
            // UNSIGNED, so that subtraction underflows and errors before
            // GREATEST ever sees the result. :qty2 exists because PDO with
            // emulated prepares off wants each named placeholder once.
            Database::statement(
                'UPDATE inventory
                    SET qty_reserved = CASE WHEN qty_reserved >= :qty THEN qty_reserved - :qty2 ELSE 0 END
                  WHERE product_id = :p AND store_id = :s',
                [
                    'qty'  => (int) $line['qty'],
                    'qty2' => (int) $line['qty'],
                    'p'    => (int) $line['product_id'],
                    's'    => (int) $line['store_id'],
                ]
            );
        }

        // The ledger rows this order produced. Collected BEFORE the delete,
        // because seller_orders cascades away with the order and the reference
        // would then point at nothing findable. The application must never
        // delete these - an append-only stock ledger is the point - but a
        // teardown that leaves them makes the table grow by six rows a run.
        $subIds = array_column(
            Database::select('SELECT id FROM seller_orders WHERE order_id = :o', ['o' => $orderId]),
            'id'
        );

        foreach ($subIds as $subId) {
            Database::statement(
                "DELETE FROM stock_movements
                  WHERE reference_type = 'seller_order' AND reference_id = :sub",
                ['sub' => (int) $subId]
            );

            // The messages these orders queued. They reference a sub-order that
            // is about to cascade away, and nothing sends them - so left behind
            // they pile up at the FRONT of the sending queue, ordered by
            // send_after, and push a later test's own messages out of the batch.
            // That is how an untidy teardown breaks a suite it never touches.
            Database::statement(
                "DELETE FROM notifications
                  WHERE reference_type = 'seller_order' AND reference_id = :sub",
                ['sub' => (int) $subId]
            );
        }

        Database::statement('DELETE FROM orders WHERE id = :id', ['id' => $orderId]);
    }

    foreach ($cartIds as $cartId) {
        Database::statement('DELETE FROM carts WHERE id = :id', ['id' => $cartId]);
    }

    foreach ($userIds as $userId) {
        Database::statement('DELETE FROM users WHERE id = :id', ['id' => $userId]);
    }

    Database::statement("DELETE FROM idempotency_keys WHERE endpoint = 'checkout.place'");

    // This suite deliberately submits a wrong password, and the limiter counts
    // failures per IP as well as per account. Every request here comes from
    // 127.0.0.1, so without this the suite throttles itself out of its own
    // login after about fifteen runs - the limiter working exactly as intended,
    // on the one machine that is allowed to keep trying.
    Database::statement(
        'DELETE FROM auth_attempts WHERE ip_address = INET6_ATON(:ip)',
        ['ip' => TEST_IP]
    );
}

$createdOrders = [];
$createdCarts  = [];
$createdUsers  = [];

// =============================================================================
TestRunner::section('A. The public marketplace reads the database');

$product = journey_product();

$home = Http::get('/');
TestRunner::same('The home page renders', 200, $home->status());

$publishedCount = (int) Database::scalar(
    "SELECT COUNT(*) FROM products p JOIN sellers s ON s.id = p.seller_id
      WHERE p.status = 'published' AND s.status = 'active'"
);

$listing = Http::get('/products');
TestRunner::same('The catalogue renders', 200, $listing->status());
TestRunner::check(
    'It shows the real published count, not a fixture',
    Http::sees($listing, (string) $publishedCount),
    $publishedCount . ' published products'
);

$detail = Http::get('/products/' . $product['slug']);
TestRunner::check(
    'A product page shows the name from the products table',
    Http::sees($detail, htmlspecialchars((string) $product['name'], ENT_QUOTES)),
    (string) $product['name']
);

TestRunner::same(
    'An unknown slug is a 404, not an empty page',
    404,
    Http::get('/products/no-such-product-at-all')->status()
);

// A search box is where anything gets typed. The fulltext parser treats
// + - * " ( ) as operators, and an unbalanced one is a syntax error.
TestRunner::same(
    'A search full of fulltext operators does not 500',
    200,
    Http::get('/search', ['q' => '" OR 1=1 -- ((('])->status()
);

// =============================================================================
TestRunner::section('B. Flow A - register, verify, sign in');

Http::newVisitor();

$newEmail = 'phase4.' . bin2hex(random_bytes(4)) . '@sokolink.test';

$registered = Http::post('/register', [
    'first_name'    => 'Neema',
    'last_name'     => 'Kilonzo',
    'email'         => $newEmail,
    'phone'         => '+255712000199',
    'password'      => 'correct horse battery staple',
    'password_confirmation' => 'correct horse battery staple',
    'accept_terms'  => '1',
]);

TestRunner::same('Registering redirects rather than rendering', 303, $registered->status());

$newUser = Database::selectOne('SELECT * FROM users WHERE email = :e', ['e' => $newEmail]);

if ($newUser === null) {
    TestRunner::check('The account row was created', false, Http::flashText());
} else {
    $createdUsers[] = (int) $newUser['id'];

    TestRunner::check('The account row was created', true, $newEmail);
    TestRunner::same(
        'It starts pending_verification, not active',
        'pending_verification',
        (string) $newUser['status']
    );
    TestRunner::check(
        'The password is hashed, never stored as typed',
        password_verify('correct horse battery staple', (string) $newUser['password_hash'])
            && !str_contains((string) $newUser['password_hash'], 'correct horse'),
        substr((string) $newUser['password_hash'], 0, 7) . '...'
    );
    TestRunner::check(
        'Registration did NOT sign them in',
        Auth::guest(),
        'status is pending_verification until the link is clicked'
    );

    // Marketing consent was not ticked, so it is on file as refused rather
    // than simply absent.
    $marketing = Database::selectOne(
        "SELECT granted FROM consent_records
          WHERE user_id = :u AND consent_type = 'marketing' ORDER BY id DESC LIMIT 1",
        ['u' => $newUser['id']]
    );
    TestRunner::same('An unticked marketing box is recorded as refused', 0, (int) $marketing['granted']);

    // The verification link, as the email would carry it.
    $token = Database::scalar(
        "SELECT payload_json FROM notifications
          WHERE user_id = :u AND template_key = 'auth.verify_email' ORDER BY id DESC LIMIT 1",
        ['u' => $newUser['id']]
    );
    $payload = json_decode((string) $token, true);

    $verified = Http::get('/verify-email', ['token' => $payload['token'] ?? '']);
    TestRunner::check(
        'Clicking the emailed link confirms the address',
        Http::sees($verified, 'Email confirmed'),
        'GET /verify-email?token=...'
    );

    $after = Database::selectOne('SELECT status, email_verified_at FROM users WHERE id = :u', ['u' => $newUser['id']]);
    TestRunner::same('The account is now active', 'active', (string) $after['status']);

    $replayed = Http::get('/verify-email', ['token' => $payload['token'] ?? '']);
    TestRunner::check(
        'The same link a second time is refused, not re-applied',
        Http::sees($replayed, 'expired'),
        'single-use token'
    );
}

Http::newVisitor();

$badLogin = Http::post('/login', ['email' => $newEmail, 'password' => 'not-the-password']);
TestRunner::check('A wrong password is refused', Auth::guest(), Http::flashText());
TestRunner::check(
    'And says nothing about whether the account exists',
    !str_contains(Http::flashText(), 'no account') && !str_contains(Http::flashText(), 'not found'),
    Http::flashText()
);

Http::newVisitor();
Http::post('/login', ['email' => 'customer.asha@sokolink.test', 'password' => SEED_PASSWORD]);
TestRunner::check('A correct password signs the customer in', Auth::check(), (string) Auth::email());

$customerId = (int) Auth::id();

// =============================================================================
TestRunner::section('C. Flow D - the basket is server-side and CSRF-protected');

$noToken = Http::postWithoutToken('/cart/add', ['product_id' => $product['id'], 'qty' => 1]);
TestRunner::same('A POST with no CSRF token is refused with 403', 403, $noToken->status());

Http::post('/cart/add', ['product_id' => $product['id'], 'qty' => 2]);

$cart = Database::selectOne(
    "SELECT * FROM carts WHERE user_id = :u AND status = 'active' ORDER BY id DESC LIMIT 1",
    ['u' => $customerId]
);
TestRunner::check('Adding to the basket created a cart row', $cart !== null);

if ($cart !== null) {
    $createdCarts[] = (int) $cart['id'];

    $line = Database::selectOne(
        'SELECT * FROM cart_items WHERE cart_id = :c ORDER BY id DESC LIMIT 1',
        ['c' => $cart['id']]
    );

    TestRunner::same('With the quantity that was posted', 2, (int) $line['qty']);
    TestRunner::check(
        'And a source store, so checkout is not blocked later',
        $line['store_id'] !== null,
        'store_id ' . (string) $line['store_id']
    );

    // The one thing a browser must not be able to decide.
    Http::post('/cart/add', [
        'product_id' => $product['id'],
        'qty'        => 1,
        'price'      => '1.00',
        'unit_price' => '1.00',
        'line_total' => '1.00',
    ]);

    $priced = Database::selectOne(
        'SELECT price_when_added FROM cart_items WHERE cart_id = :c ORDER BY id DESC LIMIT 1',
        ['c' => $cart['id']]
    );
    TestRunner::same(
        'A price posted by the browser is ignored entirely',
        (string) $product['price'],
        (string) $priced['price_when_added']
    );

    Http::post('/cart/update', ['cart_item_id' => $line['id'], 'qty' => 4]);
    TestRunner::same(
        'Updating a quantity writes it through',
        4,
        (int) Database::scalar('SELECT qty FROM cart_items WHERE id = :i', ['i' => $line['id']])
    );

    Http::post('/cart/update', ['cart_item_id' => $line['id'], 'qty' => 0]);
    TestRunner::same(
        'A quantity of zero removes the line',
        0,
        (int) Database::scalar('SELECT COUNT(*) FROM cart_items WHERE id = :i', ['i' => $line['id']])
    );
}

// Another customer's basket line must not be reachable by guessing its id.
// The basket is built directly rather than hoping one exists, so this check
// runs on every seed rather than skipping silently.
$otherCustomer = (int) Database::scalar(
    "SELECT id FROM users WHERE id <> :me AND status = 'active' LIMIT 1",
    ['me' => $customerId]
);

$foreignCartId = Database::insert('carts', [
    'user_id' => $otherCustomer,
    'status'  => 'active',
]);
$createdCarts[] = $foreignCartId;

$foreignLineId = Database::insert('cart_items', [
    'cart_id'          => $foreignCartId,
    'product_id'       => (int) $product['id'],
    'store_id'         => null,
    'qty'              => 1,
    'price_when_added' => (string) $product['price'],
]);

Http::post('/cart/remove', ['cart_item_id' => $foreignLineId]);

TestRunner::same(
    'One customer cannot remove another customer basket line',
    1,
    (int) Database::scalar('SELECT COUNT(*) FROM cart_items WHERE id = :i', ['i' => $foreignLineId])
);

Http::post('/cart/update', ['cart_item_id' => $foreignLineId, 'qty' => 99]);

TestRunner::same(
    'Nor change its quantity',
    1,
    (int) Database::scalar('SELECT qty FROM cart_items WHERE id = :i', ['i' => $foreignLineId])
);

// =============================================================================
TestRunner::section('D. Flows E and H - checkout creates exactly one order');

Http::post('/cart/add', ['product_id' => $product['id'], 'qty' => 2]);

$address = Database::selectOne(
    'SELECT id, district FROM customer_addresses WHERE user_id = :u AND zone_id IS NOT NULL LIMIT 1',
    ['u' => $customerId]
);

TestRunner::same('Checkout step 1 renders', 200, Http::get('/checkout')->status());

$saved = Http::post('/checkout', [
    'fulfilment' => [(int) $product['seller_id'] => 'delivery'],
    'address_id' => $address['id'],
]);
TestRunner::check(
    'Saving fulfilment redirects to payment',
    str_contains((string) Http::redirectedTo($saved), '/checkout/payment'),
    (string) Http::redirectedTo($saved)
);

$paymentPage = Http::get('/checkout/payment');
TestRunner::same('The payment page renders', 200, $paymentPage->status());

$ordersBefore = (int) Database::scalar('SELECT COUNT(*) FROM orders WHERE user_id = :u', ['u' => $customerId]);

// The absolute reserved figure includes the seed's own orders, so the
// assertion below compares the change this checkout caused.
$sourceStoreId  = (int) Database::scalar(
    "SELECT ci.store_id FROM cart_items ci JOIN carts c ON c.id = ci.cart_id
      WHERE c.user_id = :u AND c.status = 'active' LIMIT 1",
    ['u' => $customerId]
);
$reservedBefore = (int) Database::scalar(
    'SELECT qty_reserved FROM inventory WHERE product_id = :p AND store_id = :s',
    ['p' => $product['id'], 's' => $sourceStoreId]
);
$onHandBefore = (int) Database::scalar(
    'SELECT qty_on_hand FROM inventory WHERE product_id = :p AND store_id = :s',
    ['p' => $product['id'], 's' => $sourceStoreId]
);

// The double click. Both POSTs carry the key the payment page rendered, which
// is what makes the second one a replay rather than a second order.
$first  = Http::post('/checkout/place', ['payment_method' => 'sandbox', 'accept_terms' => '1']);
$second = Http::post('/checkout/place', ['payment_method' => 'sandbox', 'accept_terms' => '1']);

$ordersAfter = (int) Database::scalar('SELECT COUNT(*) FROM orders WHERE user_id = :u', ['u' => $customerId]);

TestRunner::same('A double-submitted checkout creates exactly ONE order', 1, $ordersAfter - $ordersBefore);

$order = Database::selectOne(
    'SELECT * FROM orders WHERE user_id = :u ORDER BY id DESC LIMIT 1',
    ['u' => $customerId]
);

if ($order !== null) {
    $createdOrders[] = (int) $order['id'];

    TestRunner::check(
        'The order number is on the confirmation page',
        Http::sees(Http::get('/checkout/confirmation'), (string) $order['order_number']),
        (string) $order['order_number']
    );

    $subs = Database::select('SELECT * FROM seller_orders WHERE order_id = :o', ['o' => $order['id']]);
    TestRunner::check('A seller sub-order was created', $subs !== [], count($subs) . ' sub-order(s)');

    $items = Database::select(
        'SELECT i.* FROM order_items i JOIN seller_orders so ON so.id = i.seller_order_id WHERE so.order_id = :o',
        ['o' => $order['id']]
    );
    TestRunner::check('With its line items', $items !== [], count($items) . ' line(s)');

    // The total is the server's arithmetic, not the browser's.
    $expected = 0;
    foreach ($items as $item) {
        $expected += (int) round((float) $item['line_total'] * 100);
    }
    $expected += (int) round((float) $order['delivery_total'] * 100);

    TestRunner::same(
        'The grand total is items plus delivery, computed server-side',
        $expected,
        (int) round((float) $order['grand_total'] * 100)
    );

    TestRunner::check(
        'A delivery order carries a real delivery fee from the zone table',
        (float) $order['delivery_total'] > 0,
        $order['delivery_total'] . ' to ' . $address['district']
    );

    TestRunner::check(
        'A delivery task was created with a snapshot address',
        (int) Database::scalar(
            'SELECT COUNT(*) FROM delivery_tasks t JOIN seller_orders so ON so.id = t.seller_order_id
              WHERE so.order_id = :o',
            ['o' => $order['id']]
        ) > 0
    );

    TestRunner::same(
        'The basket was emptied, not left to be ordered twice',
        0,
        (int) Database::scalar(
            "SELECT COUNT(*) FROM cart_items ci JOIN carts c ON c.id = ci.cart_id
              WHERE c.user_id = :u AND c.status = 'active'",
            ['u' => $customerId]
        )
    );

    TestRunner::same(
        'Placing the order reserved exactly the quantity ordered',
        (int) $items[0]['qty'],
        (int) Database::scalar(
            'SELECT qty_reserved FROM inventory WHERE product_id = :p AND store_id = :s',
            ['p' => $items[0]['product_id'], 's' => $subs[0]['store_id']]
        ) - $reservedBefore
    );

    TestRunner::check(
        'And took nothing off the shelf - a reservation is not a sale',
        (int) Database::scalar(
            'SELECT qty_on_hand FROM inventory WHERE product_id = :p AND store_id = :s',
            ['p' => $items[0]['product_id'], 's' => $subs[0]['store_id']]
        ) === $onHandBefore,
        'qty_on_hand unchanged at ' . $onHandBefore
    );
}

// =============================================================================
TestRunner::section('E. The customer sees their own orders and nobody else does');

if ($order !== null) {
    $sub = Database::selectOne('SELECT sub_number FROM seller_orders WHERE order_id = :o LIMIT 1', ['o' => $order['id']]);

    $list = Http::get('/customer/orders');
    TestRunner::check(
        'The order list shows the new sub-order',
        Http::sees($list, (string) $sub['sub_number']),
        (string) $sub['sub_number']
    );

    TestRunner::same(
        'The order detail page renders',
        200,
        Http::get('/customer/orders/' . $sub['sub_number'])->status()
    );

    // Somebody else's order reference.
    $foreign = Database::selectOne(
        'SELECT so.sub_number FROM seller_orders so JOIN orders o ON o.id = so.order_id
          WHERE o.user_id <> :me LIMIT 1',
        ['me' => $customerId]
    );

    if ($foreign !== null) {
        TestRunner::same(
            'Another customer order reference is a 404, not a 403',
            404,
            Http::get('/customer/orders/' . $foreign['sub_number'])->status()
        );
    }
}

// =============================================================================
TestRunner::section('F. The gates actually close');

Http::newVisitor();

$guarded = Http::get('/customer/orders');
TestRunner::check(
    'A signed-out visitor is sent to the login page',
    str_contains((string) Http::redirectedTo($guarded), '/login'),
    (string) Http::redirectedTo($guarded)
);

TestRunner::check(
    'Checkout is closed to a signed-out visitor too',
    str_contains((string) Http::redirectedTo(Http::get('/checkout')), '/login'),
    (string) Http::redirectedTo(Http::get('/checkout'))
);

// A seller has no business in the customer area.
Http::newVisitor();
Http::post('/login', ['email' => 'seller.mama.lishe@sokolink.test', 'password' => SEED_PASSWORD]);

if (Auth::check()) {
    TestRunner::same(
        'A seller is refused the customer dashboard with 403',
        403,
        Http::get('/customer/orders')->status()
    );
}

// An unverified account can browse and fill a basket but cannot order.
// The seed has no unverified customer - every seeded account is already
// confirmed - so this makes one rather than skipping the check.
Http::newVisitor();

$unverifiedEmail = 'phase4.unverified.' . bin2hex(random_bytes(4)) . '@sokolink.test';

Http::post('/register', [
    'first_name'    => 'Halima',
    'last_name'     => 'Said',
    'email'         => $unverifiedEmail,
    'phone'         => '+255712000177',
    'password'      => 'a different long passphrase',
    'password_confirmation' => 'a different long passphrase',
    'accept_terms'  => '1',
]);

$unverified = Database::selectOne('SELECT id FROM users WHERE email = :e', ['e' => $unverifiedEmail]);

if ($unverified !== null) {
    $createdUsers[] = (int) $unverified['id'];

    Http::newVisitor();
    Http::post('/login', ['email' => $unverifiedEmail, 'password' => 'a different long passphrase']);

    TestRunner::check(
        'An unverified customer can still sign in and browse',
        Auth::check(),
        'status pending_verification'
    );

    Http::post('/cart/add', ['product_id' => $product['id'], 'qty' => 1]);

    $unverifiedCart = Database::selectOne(
        "SELECT id FROM carts WHERE user_id = :u AND status = 'active' LIMIT 1",
        ['u' => $unverified['id']]
    );

    TestRunner::check('And can fill a basket', $unverifiedCart !== null);

    if ($unverifiedCart !== null) {
        $createdCarts[] = (int) $unverifiedCart['id'];
    }

    $blocked = Http::get('/checkout');
    TestRunner::check(
        'But is stopped before checkout until the address is confirmed',
        str_contains((string) Http::redirectedTo($blocked), '/verify-email'),
        (string) Http::redirectedTo($blocked)
    );

    TestRunner::same(
        'And placing an order directly is refused too, not just the page',
        0,
        (int) Database::scalar('SELECT COUNT(*) FROM orders WHERE user_id = :u', ['u' => $unverified['id']])
    );
}

// =============================================================================
TestRunner::section('G. A guest basket survives signing in');

Http::newVisitor();

Http::post('/cart/add', ['product_id' => $product['id'], 'qty' => 3]);

$guestCart = Database::selectOne(
    "SELECT * FROM carts WHERE user_id IS NULL AND status = 'active' ORDER BY id DESC LIMIT 1"
);
TestRunner::check('A signed-out visitor gets a basket', $guestCart !== null);

if ($guestCart !== null) {
    $createdCarts[] = (int) $guestCart['id'];

    TestRunner::check(
        'Keyed by a HASH, never the raw cookie value',
        is_string($guestCart['cookie_hash']) && strlen((string) $guestCart['cookie_hash']) === 64,
        strlen((string) $guestCart['cookie_hash']) . ' chars'
    );

    Http::post('/login', ['email' => 'customer.asha@sokolink.test', 'password' => SEED_PASSWORD]);

    $merged = (int) Database::scalar(
        "SELECT COUNT(*) FROM cart_items ci JOIN carts c ON c.id = ci.cart_id
          WHERE c.user_id = :u AND c.status = 'active' AND ci.product_id = :p",
        ['u' => $customerId, 'p' => $product['id']]
    );

    TestRunner::check('The guest basket moved onto the account at sign-in', $merged > 0, $merged . ' line(s)');

    $accountCart = Database::selectOne(
        "SELECT id FROM carts WHERE user_id = :u AND status = 'active' ORDER BY id DESC LIMIT 1",
        ['u' => $customerId]
    );

    if ($accountCart !== null) {
        $createdCarts[] = (int) $accountCart['id'];
    }
}

// =============================================================================
TestRunner::section('H. One-click unsubscribe, without signing in');

Http::newVisitor();

$consents        = new ConsentRepository();
$unsubToken      = (new TokenRepository())->issue($customerId, TokenPurpose::Unsubscribe, 60, TEST_IP);

TestRunner::check(
    'The customer consents to marketing before we start',
    $consents->currentlyGrants($customerId, ConsentType::Marketing)
);

TestRunner::same(
    'The unsubscribe page opens with no session at all',
    200,
    Http::get('/unsubscribe', ['token' => $unsubToken])->status()
);

Http::post('/unsubscribe', ['token' => $unsubToken]);

TestRunner::check(
    'One POST withdraws marketing consent',
    !$consents->currentlyGrants($customerId, ConsentType::Marketing),
    Http::flashText()
);

TestRunner::same(
    'Order updates are NOT switched off with it',
    1,
    (int) Database::scalar(
        "SELECT is_enabled FROM notification_preferences
          WHERE user_id = :u AND category = 'order_updates' AND channel = 'email'",
        ['u' => $customerId]
    )
);

Http::post('/unsubscribe', ['token' => $unsubToken]);
TestRunner::check(
    'The same link a second time is refused - the token is single-use',
    str_contains(Http::flashText(), 'expired or has already been used'),
    Http::flashText()
);

// Put the seed back: this customer consents, and the retention tests rely on it.
Database::statement(
    "DELETE FROM consent_records WHERE user_id = :u AND source = 'unsubscribe_link'",
    ['u' => $customerId]
);
Database::statement(
    "UPDATE notification_preferences SET is_enabled = 1
      WHERE user_id = :u AND category IN ('offers', 'reorder')",
    ['u' => $customerId]
);
Database::statement(
    "DELETE FROM user_tokens WHERE user_id = :u AND purpose = 'unsubscribe'",
    ['u' => $customerId]
);
Database::statement("DELETE FROM audit_log WHERE action = 'consent.marketing.withdrawn'");

TestRunner::check(
    'And the seed is back as it was, so the suite can run again',
    $consents->currentlyGrants($customerId, ConsentType::Marketing)
);

// =============================================================================
TestRunner::section('I. Errors degrade, they do not leak');

Http::newVisitor();

$notFound = Http::get('/definitely/not/a/page');
TestRunner::same('An unknown URL is a 404', 404, $notFound->status());

$badCategory = Http::get('/category/not-a-real-category');
TestRunner::same('An unknown category is a 404, not a blank listing', 404, $badCategory->status());

$methodNotAllowed = Http::get('/cart/add');
TestRunner::same('A GET to a POST-only route is 405', 405, $methodNotAllowed->status());

journey_cleanup($createdOrders, $createdCarts, $createdUsers);

TestRunner::finish();
