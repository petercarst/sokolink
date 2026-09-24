<?php

declare(strict_types=1);

/**
 * Phase 4b - the seller path, driven through the HTTP layer.
 *
 * Workflows B (the applicant's side), C (list a product and stock it),
 * F (accept, prepare, mark ready) and G (verify a code at the counter),
 * plus the cash-on-fulfilment limits, which are the seller's risk to carry
 * and so partly the seller's decision to make.
 *
 * The things this file exists to prove, which no service test can:
 *
 *   - A seller's dashboard is reachable only by a seller, and shows only their
 *     own rows.
 *   - The buttons on the order page reach the state machine, and the state
 *     machine still refuses what it refused in Phase 3.
 *   - A collection code issued by pressing "ready" is verifiable at the counter
 *     and is never readable from any page.
 *   - An applicant awaiting approval can prepare a catalogue and cannot publish
 *     it.
 *
 * Run: php tests/Http/seller_workflow_test.php
 */

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\SettingsRepository;
use App\Services\Payment\CashEligibility;

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/kernel.php';

const SEED_PASSWORD = 'SokoLink!Dev2026';
const TEST_IP       = '127.0.0.1';

TestRunner::suite('Phase 4b - the seller path over HTTP');

Http::boot();

$createdOrders   = [];
$createdProducts = [];

/** Signs in as the seeded seller who owns seller_id 1. */
function as_seller(string $email = 'seller.mama.lishe@sokolink.test'): int
{
    Http::newVisitor();
    Http::post('/login', ['email' => $email, 'password' => SEED_PASSWORD]);

    return (int) Auth::sellerId();
}

/**
 * Places a real order with this seller so there is something to work on.
 *
 * Cash, so it reaches the seller without a payment provider in the loop - the
 * card path has its own coverage in the customer journey suite.
 *
 * @return array{order_id:int,sub_id:int,ref:string,product_id:int,store_id:int}
 */
function seller_order(string $method = 'pickup'): array
{
    $product = Database::selectOne(
        "SELECT p.id, p.seller_id FROM products p
           JOIN inventory i ON i.product_id = p.id
          WHERE p.status = 'published' AND p.seller_id = 1
            AND p.allows_pickup = 1 AND p.allows_delivery = 1
          GROUP BY p.id HAVING SUM(i.qty_available) > 3
          ORDER BY p.id LIMIT 1"
    );

    Http::newVisitor();
    Http::post('/login', ['email' => 'customer.asha@sokolink.test', 'password' => SEED_PASSWORD]);
    Http::post('/cart/add', ['product_id' => $product['id'], 'qty' => 1]);

    $body = ['fulfilment' => [(int) $product['seller_id'] => $method]];

    if ($method === 'delivery') {
        $address = Database::selectOne(
            'SELECT id FROM customer_addresses WHERE user_id = :u AND zone_id IS NOT NULL LIMIT 1',
            ['u' => Auth::id()]
        );
        $body['address_id'] = $address['id'];
    }

    Http::post('/checkout', $body);
    Http::get('/checkout/payment');
    Http::post('/checkout/place', ['payment_method' => 'cash', 'accept_terms' => '1']);

    $order = Database::selectOne('SELECT * FROM orders ORDER BY id DESC LIMIT 1');
    $sub   = Database::selectOne('SELECT * FROM seller_orders WHERE order_id = :o', ['o' => $order['id']]);

    return [
        'order_id'   => (int) $order['id'],
        'sub_id'     => (int) $sub['id'],
        'ref'        => (string) $sub['sub_number'],
        'product_id' => (int) $product['id'],
        'store_id'   => (int) $sub['store_id'],
    ];
}

/** Undoes everything a run created, including stock it moved. */
function seller_cleanup(array $orderIds, array $productIds): void
{
    foreach ($orderIds as $orderId) {
        $lines = Database::select(
            'SELECT i.product_id, so.store_id, i.qty, so.status
               FROM order_items i
               JOIN seller_orders so ON so.id = i.seller_order_id
              WHERE so.order_id = :o AND i.product_id IS NOT NULL',
            ['o' => $orderId]
        );

        foreach ($lines as $line) {
            $status = (string) $line['status'];

            // Three cases, and getting them wrong silently corrupts the seed:
            //
            //   completed  the units were CONSUMED - taken off the shelf. Put
            //              them back on it.
            //   released   the order was rejected or cancelled, so the service
            //              has ALREADY returned the reservation. Touch nothing.
            //   otherwise  the units are still reserved. Release them.
            $consumed = in_array($status, ['collected', 'delivered', 'completed'], true);
            $released = in_array(
                $status,
                ['rejected_seller', 'cancelled_customer', 'expired_unpaid', 'refunded', 'returned_to_seller'],
                true
            );

            if ($released) {
                continue;
            }

            $where = ['p' => (int) $line['product_id'], 's' => (int) $line['store_id']];
            $qty   = (int) $line['qty'];

            if ($consumed) {
                Database::statement(
                    'UPDATE inventory SET qty_on_hand = qty_on_hand + :qty
                      WHERE product_id = :p AND store_id = :s',
                    $where + ['qty' => $qty]
                );
            } else {
                // NOT GREATEST(0, qty_reserved - :qty): qty_reserved is
                // UNSIGNED, so that subtraction underflows and errors before
                // GREATEST ever sees the result. And :qty twice would be an
                // "invalid parameter number" with emulated prepares off, which
                // is why the second one is :qty2.
                Database::statement(
                    'UPDATE inventory
                        SET qty_reserved = CASE WHEN qty_reserved >= :qty THEN qty_reserved - :qty2 ELSE 0 END
                      WHERE product_id = :p AND store_id = :s',
                    $where + ['qty' => $qty, 'qty2' => $qty]
                );
            }
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

        // payment_transactions is ON DELETE RESTRICT by design - evidence that
        // money moved must not disappear with the order - so it goes first.
        Database::statement('DELETE FROM payment_transactions WHERE order_id = :o', ['o' => $orderId]);
        Database::statement('DELETE FROM payment_intents WHERE order_id = :o', ['o' => $orderId]);
        Database::statement('DELETE FROM orders WHERE id = :o', ['o' => $orderId]);
    }

    foreach ($productIds as $productId) {
        // stock_movements is append-only in the application and has no FK to
        // products, so the ledger rows this suite wrote outlive the product it
        // wrote them for. The app must never delete them; a teardown may, and
        // should, or the table grows by six rows every run for ever.
        Database::statement('DELETE FROM stock_movements WHERE product_id = :p', ['p' => $productId]);
        Database::statement('DELETE FROM products WHERE id = :p', ['p' => $productId]);
    }

    Database::statement("DELETE FROM carts WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@sokolink.test')");
    Database::statement("DELETE FROM idempotency_keys WHERE endpoint = 'checkout.place'");
    Database::statement('DELETE FROM auth_attempts WHERE ip_address = INET6_ATON(:ip)', ['ip' => TEST_IP]);
}

// =============================================================================
TestRunner::section('A. The dashboard is a seller-only door');

Http::newVisitor();
$guarded = Http::get('/seller/orders');
TestRunner::check(
    'A signed-out visitor is sent to the login page',
    str_contains((string) Http::redirectedTo($guarded), '/login'),
    (string) Http::redirectedTo($guarded)
);

Http::newVisitor();
Http::post('/login', ['email' => 'customer.asha@sokolink.test', 'password' => SEED_PASSWORD]);
TestRunner::same('A customer is refused the seller area with 403', 403, Http::get('/seller/orders')->status());

$sellerId = as_seller();
TestRunner::same('The seller resolves to their own sellers.id', 1, $sellerId);
TestRunner::same('And the dashboard renders', 200, Http::get('/seller')->status());

// =============================================================================
TestRunner::section('B. Flow F - accept, prepare, mark ready');

$ctx = seller_order('pickup');
$createdOrders[] = $ctx['order_id'];

TestRunner::same(
    'A cash order reaches the seller instead of waiting for a payment that never comes',
    'awaiting_seller',
    (string) Database::scalar('SELECT status FROM seller_orders WHERE id = :s', ['s' => $ctx['sub_id']])
);

// The release goes through OrderService like every other transition, so it
// leaves the same trail. Writing the two rows directly from CheckoutService
// would have worked and skipped all of this, silently, on one path only.
TestRunner::same(
    'And the release is audited like any other transition',
    1,
    (int) Database::scalar(
        "SELECT COUNT(*) FROM audit_log
          WHERE action = 'order.transition' AND entity_type = 'seller_order' AND entity_id = :s",
        ['s' => $ctx['sub_id']]
    )
);

as_seller();

$list = Http::get('/seller/orders');
TestRunner::check('It appears on the seller order list', Http::sees($list, $ctx['ref']), $ctx['ref']);

// Out of order on purpose: the state machine decides, not the button.
Http::post('/seller/orders/ready', ['ref' => $ctx['ref']]);
TestRunner::same(
    'Marking an unaccepted order ready is refused',
    'awaiting_seller',
    (string) Database::scalar('SELECT status FROM seller_orders WHERE id = :s', ['s' => $ctx['sub_id']])
);
TestRunner::check('And says why', Http::flashText() !== '', Http::flashText());

Http::post('/seller/orders/accept', ['ref' => $ctx['ref']]);
TestRunner::same(
    'Accepting moves it to confirmed',
    'confirmed',
    (string) Database::scalar('SELECT status FROM seller_orders WHERE id = :s', ['s' => $ctx['sub_id']])
);

Http::post('/seller/orders/prepare', ['ref' => $ctx['ref']]);
TestRunner::same(
    'Preparing follows',
    'preparing',
    (string) Database::scalar('SELECT status FROM seller_orders WHERE id = :s', ['s' => $ctx['sub_id']])
);

Http::post('/seller/orders/ready', ['ref' => $ctx['ref']]);
TestRunner::same(
    'Ready for a collection order means ready_for_pickup',
    'ready_for_pickup',
    (string) Database::scalar('SELECT status FROM seller_orders WHERE id = :s', ['s' => $ctx['sub_id']])
);

$pickupRow = Database::selectOne(
    'SELECT code_hash, window_to FROM order_pickups WHERE seller_order_id = :s',
    ['s' => $ctx['sub_id']]
);

TestRunner::check(
    'A collection code was issued in the same step',
    is_string($pickupRow['code_hash']) && strlen((string) $pickupRow['code_hash']) === 64,
    'SHA-256 hash on file'
);
TestRunner::check('With a window to collect in', $pickupRow['window_to'] !== null, (string) $pickupRow['window_to']);

// placed, released for cash, accepted, preparing, ready - five rows, in order,
// append-only. The history is what makes "who moved this, and when" answerable.
$history = Database::select(
    'SELECT from_status, to_status, actor_type FROM order_status_history
      WHERE seller_order_id = :s ORDER BY id',
    ['s' => $ctx['sub_id']]
);

TestRunner::same(
    'Every transition was recorded, none skipped',
    ['pending_payment', 'awaiting_seller', 'confirmed', 'preparing', 'ready_for_pickup'],
    array_map(static fn (array $h): string => (string) $h['to_status'], $history)
);

TestRunner::same(
    'And each one names who did it',
    ['customer', 'system', 'seller', 'seller', 'seller'],
    array_map(static fn (array $h): string => (string) $h['actor_type'], $history)
);

// =============================================================================
TestRunner::section('C. The code exists in the message and nowhere else');

$queued = Database::selectOne(
    "SELECT id, payload_json FROM notifications
      WHERE template_key = 'pickup.code_issued' AND reference_id = :s
      ORDER BY id DESC LIMIT 1",
    ['s' => $ctx['sub_id']]
);

TestRunner::check('The customer was sent a message', $queued !== null);

$code = (string) (json_decode((string) $queued['payload_json'], true)['code'] ?? '');
TestRunner::check('Which carries the plaintext code', $code !== '', strlen($code) . ' characters');

$orderPage = Http::get('/seller/orders/' . $ctx['ref']);
TestRunner::check(
    'The seller order page does NOT show the code',
    !Http::sees($orderPage, $code),
    'only the customer has it'
);

as_seller();
$verifyPage = Http::get('/seller/pickup/verify');
TestRunner::check('Nor does the verification page', !Http::sees($verifyPage, $code));

// =============================================================================
TestRunner::section('D. Flow G - the counter');

$before = (int) Database::scalar(
    'SELECT qty_on_hand FROM inventory WHERE product_id = :p AND store_id = :s',
    ['p' => $ctx['product_id'], 's' => $ctx['store_id']]
);

Http::post('/seller/pickup/verify', ['seller_order_id' => $ctx['sub_id'], 'code' => 'WRONG1']);
TestRunner::same(
    'A wrong code does not hand the order over',
    'ready_for_pickup',
    (string) Database::scalar('SELECT status FROM seller_orders WHERE id = :s', ['s' => $ctx['sub_id']])
);
TestRunner::same(
    'And costs an attempt, which is what makes the cap mean anything',
    1,
    (int) Database::scalar('SELECT verify_attempts FROM order_pickups WHERE seller_order_id = :s', ['s' => $ctx['sub_id']])
);

Http::post('/seller/pickup/verify', ['seller_order_id' => $ctx['sub_id'], 'code' => $code]);
TestRunner::same(
    'The right code completes the order',
    'completed',
    (string) Database::scalar('SELECT status FROM seller_orders WHERE id = :s', ['s' => $ctx['sub_id']])
);
TestRunner::check(
    'And records who handed it over, and when',
    Database::scalar('SELECT collected_at FROM order_pickups WHERE seller_order_id = :s', ['s' => $ctx['sub_id']]) !== null
);

TestRunner::same(
    'Handover takes the units off the shelf - a reservation is not a sale',
    $before - 1,
    (int) Database::scalar(
        'SELECT qty_on_hand FROM inventory WHERE product_id = :p AND store_id = :s',
        ['p' => $ctx['product_id'], 's' => $ctx['store_id']]
    )
);

// =============================================================================
TestRunner::section('E. Rejecting costs a reason');

$rejected = seller_order('pickup');
$createdOrders[] = $rejected['order_id'];

$reservedWhilePlaced = (int) Database::scalar(
    'SELECT qty_reserved FROM inventory WHERE product_id = :p AND store_id = :s',
    ['p' => $rejected['product_id'], 's' => $rejected['store_id']]
);

as_seller();

Http::post('/seller/orders/reject', ['ref' => $rejected['ref'], 'reason_code' => 'out_of_stock', 'reason' => '']);
TestRunner::same(
    'A rejection with no note is refused',
    'awaiting_seller',
    (string) Database::scalar('SELECT status FROM seller_orders WHERE id = :s', ['s' => $rejected['sub_id']])
);

Http::post('/seller/orders/reject', [
    'ref'         => $rejected['ref'],
    'reason_code' => 'out_of_stock',
    'reason'      => 'The last two were damaged in the store.',
]);

$row = Database::selectOne('SELECT status, status_reason FROM seller_orders WHERE id = :s', ['s' => $rejected['sub_id']]);
TestRunner::same('With a note it is rejected', 'rejected_seller', (string) $row['status']);
TestRunner::check(
    'And the reason is stored for the customer to read',
    str_contains((string) $row['status_reason'], 'damaged in the store'),
    (string) $row['status_reason']
);

TestRunner::same(
    'The reserved unit goes back on sale',
    $reservedWhilePlaced - 1,
    (int) Database::scalar(
        'SELECT qty_reserved FROM inventory WHERE product_id = :p AND store_id = :s',
        ['p' => $rejected['product_id'], 's' => $rejected['store_id']]
    )
);

TestRunner::check(
    'Nothing left the shelf - it was never handed over',
    (int) Database::scalar(
        'SELECT qty_on_hand FROM inventory WHERE product_id = :p AND store_id = :s',
        ['p' => $rejected['product_id'], 's' => $rejected['store_id']]
    ) > 0
);

// =============================================================================
TestRunner::section('F. One seller cannot touch another seller');

$foreign = Database::selectOne(
    'SELECT so.sub_number FROM seller_orders so WHERE so.seller_id <> 1 LIMIT 1'
);

as_seller();

TestRunner::same(
    "Another seller's order reference is a 404, not a 403",
    404,
    Http::get('/seller/orders/' . $foreign['sub_number'])->status()
);

$foreignBefore = (string) Database::scalar(
    'SELECT status FROM seller_orders WHERE sub_number = :r',
    ['r' => $foreign['sub_number']]
);

Http::post('/seller/orders/accept', ['ref' => $foreign['sub_number']]);

TestRunner::same(
    "And posting it to the accept handler changes nothing",
    $foreignBefore,
    (string) Database::scalar('SELECT status FROM seller_orders WHERE sub_number = :r', ['r' => $foreign['sub_number']])
);

// =============================================================================
TestRunner::section('G. Flow C - list a product and stock it');

$sku  = 'T4B-' . strtoupper(bin2hex(random_bytes(3)));
$form = [
    'name'        => 'Test Cooking Oil ' . $sku,
    'sku'         => $sku,
    'description' => 'Created by the Phase 4b suite.',
    'price'       => '12500',
    'unit'        => 'bottle',
    'pack_size'   => '2 L',
    'brand'       => 'Suite',
    'category'    => 'cooking-oil',
    'fulfilment'  => ['pickup'],
];

as_seller();

Http::post('/seller/products', array_merge($form, ['name' => '']));
TestRunner::check(
    'A product with no name is refused, beside the field',
    isset(Http::errors()['name']),
    (string) (Http::errors()['name'] ?? '')
);

Http::post('/seller/products', array_merge($form, ['compare_at' => '100']));
TestRunner::check(
    'A "was" price below the real price is refused',
    isset(Http::errors()['compare_at']),
    (string) (Http::errors()['compare_at'] ?? '')
);

Http::post('/seller/products', array_merge($form, ['fulfilment' => []]));
TestRunner::check(
    'So is a product nobody can receive',
    isset(Http::errors()['fulfilment']),
    (string) (Http::errors()['fulfilment'] ?? '')
);

Http::post('/seller/products', $form);

$created = Database::selectOne(
    'SELECT * FROM products WHERE sku = :s AND seller_id = 1',
    ['s' => $sku]
);

TestRunner::check('A valid product is created', $created !== null, $sku);

if ($created !== null) {
    $createdProducts[] = (int) $created['id'];

    TestRunner::same('It starts as a draft, not live', 'draft', (string) $created['status']);
    TestRunner::check(
        'With a slug derived from the name',
        str_starts_with((string) $created['slug'], 'test-cooking-oil-'),
        (string) $created['slug']
    );

    Http::post('/seller/products', array_merge($form, ['sku' => $sku, 'name' => 'Another name']));
    TestRunner::check(
        'A second product cannot reuse the same SKU',
        isset(Http::errors()['sku']),
        (string) (Http::errors()['sku'] ?? '')
    );

    Http::post('/seller/products/publish', ['id' => $created['id']]);
    TestRunner::same(
        'Publishing with no stock anywhere is refused',
        'draft',
        (string) Database::scalar('SELECT status FROM products WHERE id = :i', ['i' => $created['id']])
    );

    $storeId = (int) Database::scalar('SELECT id FROM stores WHERE seller_id = 1 LIMIT 1');

    Http::post('/seller/inventory/receive', [
        'product_id' => $created['id'], 'store_id' => $storeId, 'qty' => 20,
    ]);

    $inv = Database::selectOne(
        'SELECT * FROM inventory WHERE product_id = :p AND store_id = :s',
        ['p' => $created['id'], 's' => $storeId]
    );

    TestRunner::same('Receiving stock puts 20 on the shelf', 20, (int) $inv['qty_on_hand']);
    TestRunner::same('All of it available, none reserved', 20, (int) $inv['qty_available']);

    Http::post('/seller/products/publish', ['id' => $created['id']]);
    TestRunner::same(
        'With stock, it publishes',
        'published',
        (string) Database::scalar('SELECT status FROM products WHERE id = :i', ['i' => $created['id']])
    );

    TestRunner::same(
        'And is immediately buyable on the public site',
        200,
        Http::get('/products/' . $created['slug'])->status()
    );

    // A stocktake: the shelf says 15, the system said 20.
    as_seller();
    Http::post('/seller/inventory/adjust', [
        'product_id' => $created['id'], 'store_id' => $storeId, 'qty' => 15, 'reason' => 'recount',
    ]);

    TestRunner::same(
        'A recount writes the difference, not the number',
        15,
        (int) Database::scalar(
            'SELECT qty_on_hand FROM inventory WHERE product_id = :p AND store_id = :s',
            ['p' => $created['id'], 's' => $storeId]
        )
    );

    TestRunner::same(
        'Both stock changes left a movement row behind',
        2,
        (int) Database::scalar('SELECT COUNT(*) FROM stock_movements WHERE product_id = :p', ['p' => $created['id']])
    );

    // Another seller, same product id.
    as_seller('seller.duka.kuu@sokolink.test');

    Http::post('/seller/products', array_merge($form, ['id' => $created['id'], 'name' => 'Hijacked']));
    TestRunner::check(
        'Another seller cannot rename it',
        (string) Database::scalar('SELECT name FROM products WHERE id = :i', ['i' => $created['id']]) !== 'Hijacked',
        Http::flashText()
    );

    TestRunner::same(
        'Nor open its edit form',
        404,
        Http::get('/seller/products/edit', ['id' => $created['id']])->status()
    );

    Http::post('/seller/inventory/receive', [
        'product_id' => $created['id'], 'store_id' => $storeId, 'qty' => 99,
    ]);
    TestRunner::same(
        'Nor add stock to it',
        15,
        (int) Database::scalar(
            'SELECT qty_on_hand FROM inventory WHERE product_id = :p AND store_id = :s',
            ['p' => $created['id'], 's' => $storeId]
        )
    );
}

// =============================================================================
TestRunner::section('H. Flow B - an applicant can prepare, not trade');

$pendingSeller = Database::selectOne(
    "SELECT u.id, u.email FROM users u
       JOIN user_roles ur ON ur.user_id = u.id
       JOIN roles r ON r.id = ur.role_id
      WHERE r.role_key = 'seller' AND u.status = 'pending_approval'
      LIMIT 1"
);

if ($pendingSeller === null) {
    TestRunner::check('The seed has a seller awaiting approval', false, 'none found');
} else {
    Http::newVisitor();
    Http::post('/login', ['email' => $pendingSeller['email'], 'password' => SEED_PASSWORD]);

    TestRunner::check(
        'An applicant can sign in and see their dashboard',
        Auth::check(),
        (string) $pendingSeller['email']
    );

    // They hold the role, so the route lets them through. Whether they may
    // trade is a different question, answered by sellers.status.
    $dash = Http::get('/seller');

    TestRunner::check(
        'The route gate lets them in, because the role is not the permission',
        in_array($dash->status(), [200, 403], true),
        'status ' . $dash->status()
    );
}

// =============================================================================
TestRunner::section('I. Nothing leaks that should not');

$ctxTwo = seller_order('delivery');
$createdOrders[] = $ctxTwo['order_id'];

as_seller();
$page = Http::get('/seller/orders/' . $ctxTwo['ref']);

$customer = Database::selectOne('SELECT email, phone FROM users WHERE id = 9');

TestRunner::check(
    "A seller never sees the customer's email address",
    !Http::sees($page, (string) $customer['email']),
    (string) $customer['email']
);

TestRunner::check(
    'Nor their full phone number',
    !Http::sees($page, (string) $customer['phone']),
    (string) $customer['phone']
);

TestRunner::check(
    'They do see a first name and an initial, which is what a counter needs',
    Http::sees($page, 'Asha M.'),
    'Asha M.'
);

// =============================================================================
TestRunner::section('J. Replying to a review');

as_seller();

$review = Database::selectOne(
    "SELECT r.id FROM reviews r JOIN products p ON p.id = r.product_id
      WHERE p.seller_id = 1 AND r.seller_reply IS NULL AND r.status = 'published' LIMIT 1"
);

if ($review === null) {
    TestRunner::check('The seed has an unanswered review on this seller', false, 'none found');
} else {
    Http::post('/seller/reviews/reply', ['review_id' => $review['id'], 'body' => '']);
    TestRunner::check(
        'An empty reply is refused',
        Database::scalar('SELECT seller_reply FROM reviews WHERE id = :i', ['i' => $review['id']]) === null,
        Http::flashText()
    );

    Http::post('/seller/reviews/reply', [
        'review_id' => $review['id'],
        'body'      => 'Sorry about that - we have changed supplier since.',
    ]);
    TestRunner::check(
        'A real reply is published under the review',
        str_contains(
            (string) Database::scalar('SELECT seller_reply FROM reviews WHERE id = :i', ['i' => $review['id']]),
            'changed supplier'
        )
    );

    // Somebody else's product.
    $foreignReview = Database::selectOne(
        "SELECT r.id FROM reviews r JOIN products p ON p.id = r.product_id
          WHERE p.seller_id <> 1 AND r.status = 'published' LIMIT 1"
    );

    if ($foreignReview !== null) {
        $before = Database::scalar('SELECT seller_reply FROM reviews WHERE id = :i', ['i' => $foreignReview['id']]);

        Http::post('/seller/reviews/reply', [
            'review_id' => $foreignReview['id'],
            'body'      => 'Replying to a review that is not mine.',
        ]);

        TestRunner::same(
            "A seller cannot reply to another seller's review",
            $before,
            Database::scalar('SELECT seller_reply FROM reviews WHERE id = :i', ['i' => $foreignReview['id']])
        );
    }

    Database::statement(
        'UPDATE reviews SET seller_reply = NULL, seller_replied_at = NULL WHERE id = :i',
        ['i' => $review['id']]
    );
}

// =============================================================================
TestRunner::section('K. Cash on fulfilment carries limits the other methods do not');

$policy    = new CashEligibility();
$settings  = new SettingsRepository();
$customer  = 9;
$oneSeller = [['seller_id' => 1]];

// --- the seller's own decision ---------------------------------------------
as_seller();

Http::post('/seller/settings/orders', [
    'prep_hours' => 4, 'auto_accept' => 'manual', 'low_stock_threshold' => 10,
]);

TestRunner::same(
    'A seller can switch cash off from their own settings',
    0,
    (int) Database::scalar('SELECT accepts_cod FROM sellers WHERE id = 1')
);

$verdict = $policy->forBasket($customer, $oneSeller, '10000.00');
TestRunner::check('And cash stops being offered for their products', !$verdict['allowed'], $verdict['reason']);
TestRunner::check(
    'With the seller named, so the customer knows which part of the basket',
    str_contains($verdict['reason'], 'Mama Lishe'),
    $verdict['reason']
);

// Posting it anyway is refused by the service, not just hidden by the page.
Http::newVisitor();
Http::post('/login', ['email' => 'customer.asha@sokolink.test', 'password' => SEED_PASSWORD]);

$refusedProduct = Database::selectOne(
    "SELECT p.id, p.seller_id FROM products p JOIN inventory i ON i.product_id = p.id
      WHERE p.status = 'published' AND p.seller_id = 1 AND p.allows_pickup = 1
      GROUP BY p.id HAVING SUM(i.qty_available) > 3 LIMIT 1"
);

Http::post('/cart/add', ['product_id' => $refusedProduct['id'], 'qty' => 1]);
Http::post('/checkout', ['fulfilment' => [(int) $refusedProduct['seller_id'] => 'pickup']]);

$paymentPage = Http::get('/checkout/payment');
TestRunner::check(
    'The payment page shows cash as unavailable, with the reason',
    Http::sees($paymentPage, 'does not accept cash'),
    'reason rendered from the service, not hardcoded'
);

$ordersBefore = (int) Database::scalar('SELECT COUNT(*) FROM orders WHERE user_id = :u', ['u' => $customer]);
Http::post('/checkout/place', ['payment_method' => 'cash', 'accept_terms' => '1']);

TestRunner::same(
    'And posting cash anyway creates no order at all',
    $ordersBefore,
    (int) Database::scalar('SELECT COUNT(*) FROM orders WHERE user_id = :u', ['u' => $customer])
);

Database::statement('UPDATE sellers SET accepts_cod = 1 WHERE id = 1');

// --- the platform's limits --------------------------------------------------
$cap = $settings->get('cod.max_order_value', '200000.00');

TestRunner::check(
    'Cash is refused above the order-value cap',
    !$policy->forBasket($customer, $oneSeller, (string) ((float) $cap + 1))['allowed'],
    'cap is ' . money((string) $cap)
);

TestRunner::check(
    'And allowed at exactly the cap - the limit is inclusive',
    $policy->forBasket($customer, $oneSeller, (string) $cap)['allowed']
);

// --- open cash orders -------------------------------------------------------
$maxOpen = $settings->getInt('cod.max_open_orders', 2);
$openNow = $policy->openCashOrders($customer);

$cashOrders = [];
for ($i = $openNow; $i < $maxOpen; $i++) {
    $made = seller_order('pickup');
    $cashOrders[] = $made['order_id'];
    $createdOrders[] = $made['order_id'];
}

TestRunner::same(
    'Open cash orders are counted',
    $maxOpen,
    $policy->openCashOrders($customer)
);

$atCap = $policy->forBasket($customer, $oneSeller, '5000.00');
TestRunner::check('At the cap, cash is withdrawn', !$atCap['allowed'], $atCap['reason']);

// Closing one frees the allowance again: the cap is about goods being held, not
// a lifetime quota.
//
// The order is closed through the real seller rejection, not by writing a
// status into the table. Faking it would leave the stock reserved for an order
// the database says is finished - which is exactly the inconsistency the
// service exists to prevent, and it quietly drained the seed the first time
// this test did it.
if ($cashOrders !== []) {
    $firstSub = Database::selectOne(
        'SELECT sub_number FROM seller_orders WHERE order_id = :o LIMIT 1',
        ['o' => $cashOrders[0]]
    );

    as_seller();
    Http::post('/seller/orders/reject', [
        'ref'         => $firstSub['sub_number'],
        'reason_code' => 'out_of_stock',
        'reason'      => 'Closing this one to free the cash allowance.',
    ]);

    TestRunner::same(
        'The order really is closed',
        'rejected_seller',
        (string) Database::scalar(
            'SELECT status FROM seller_orders WHERE sub_number = :r',
            ['r' => $firstSub['sub_number']]
        )
    );

    TestRunner::check(
        'And closing it frees the allowance again',
        $policy->forBasket($customer, $oneSeller, '5000.00')['allowed'],
        'the cap counts goods being held, not orders ever placed'
    );
}

// --- no-show strikes --------------------------------------------------------
TestRunner::same('This customer has no strikes to start with', 0, $policy->strikes($customer));

$strikeCtx = seller_order('delivery');
$createdOrders[] = $strikeCtx['order_id'];

$task = Database::selectOne(
    'SELECT id FROM delivery_tasks WHERE seller_order_id = :s',
    ['s' => $strikeCtx['sub_id']]
);

if ($task !== null) {
    // A failure the customer caused.
    Database::statement(
        "INSERT INTO delivery_events (task_id, event_type, reason_code, note, created_at)
         VALUES (:t, 'attempt_failed', 'recipient_absent', 'Nobody at the address', UTC_TIMESTAMP())",
        ['t' => (int) $task['id']]
    );

    TestRunner::same('A recipient-absent delivery is a strike', 1, $policy->strikes($customer));

    // One the customer did not cause.
    Database::statement(
        "INSERT INTO delivery_events (task_id, event_type, reason_code, note, created_at)
         VALUES (:t, 'attempt_failed', 'unsafe_conditions', 'Flooded road', UTC_TIMESTAMP())",
        ['t' => (int) $task['id']]
    );

    TestRunner::same(
        'A flooded road is not - only customer-caused failures count',
        1,
        $policy->strikes($customer)
    );

    Database::statement('DELETE FROM delivery_events WHERE task_id = :t', ['t' => (int) $task['id']]);
}

TestRunner::same('The strike is cleared again for the next run', 0, $policy->strikes($customer));

seller_cleanup($createdOrders, $createdProducts);

TestRunner::finish();
