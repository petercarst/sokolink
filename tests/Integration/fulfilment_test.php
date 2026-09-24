<?php

declare(strict_types=1);

/**
 * Phase 3.6 / 3.7 / 3.8 - collection, delivery and payment.
 *
 * The claims under test, all of them negative:
 *
 *   - A collection code cannot be read back out of the database by anyone.
 *   - A wrong code does not collect an order, and repeated wrong codes lock it.
 *   - An agent cannot see or touch another agent's delivery.
 *   - A browser cannot mark an order paid; only a signed callback can.
 *   - A replayed callback cannot credit an order twice.
 *   - A callback for the wrong amount is flagged, not honoured.
 *
 * Run: php tests/Integration/fulfilment_test.php
 */

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Domain\Enums\ActorType;
use App\Domain\Enums\DeliveryFailureReason;
use App\Domain\Enums\OrderStatus;
use App\Repositories\DeliveryRepository;
use App\Repositories\OrderRepository;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\DeliveryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Payment\SandboxGateway;
use App\Services\PickupService;

require __DIR__ . '/../bootstrap.php';

TestRunner::suite('Collection, delivery and payment');

$cartService  = new CartService();
$checkout     = new CheckoutService();
$orderService = new OrderService();
$pickup       = new PickupService();
$delivery     = new DeliveryService();
$payments     = new PaymentService();
$orders       = new OrderRepository();
$tasks        = new DeliveryRepository();
$sandbox      = new SandboxGateway();

/** @return array{product_id:int,store_id:int,seller_id:int,seller_user:int,price:string} */
function fulfilment_product(bool $delivery = false): array
{
    $column = $delivery ? 'offers_delivery' : 'offers_pickup';
    $allows = $delivery ? 'allows_delivery' : 'allows_pickup';

    $row = Database::selectOne(
        "SELECT p.id AS product_id, p.price, p.seller_id, s.id AS store_id, sel.user_id AS seller_user
           FROM products p
           JOIN inventory i ON i.product_id = p.id
           JOIN stores s    ON s.id = i.store_id AND s.seller_id = p.seller_id
           JOIN sellers sel ON sel.id = p.seller_id
          WHERE p.status = 'published' AND sel.status = 'active' AND s.status = 'published'
            AND s.{$column} = 1 AND p.{$allows} = 1 AND i.qty_available > 3
          ORDER BY p.id
          LIMIT 1"
    );

    if ($row === null) {
        fwrite(STDERR, 'No suitable seeded product.' . PHP_EOL);
        exit(1);
    }

    return [
        'product_id'  => (int) $row['product_id'],
        'store_id'    => (int) $row['store_id'],
        'seller_id'   => (int) $row['seller_id'],
        'seller_user' => (int) $row['seller_user'],
        'price'       => (string) $row['price'],
    ];
}

/**
 * Places a paid order ready for the seller, and returns the sub-order id.
 *
 * @return array{sub_id:int,order_id:int,order_number:string,item:array<string,mixed>}
 */
function paid_order(bool $delivery = false): array
{
    $cartService  = new CartService();
    $checkout     = new CheckoutService();
    $orderService = new OrderService();
    $orders       = new OrderRepository();

    $item     = fulfilment_product($delivery);
    $customer = seed_user('customer.asha@sokolink.test');

    Auth::actAs($customer);
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    $address = null;
    if ($delivery) {
        $zone = Database::selectOne('SELECT * FROM delivery_zones WHERE is_active = 1 LIMIT 1');
        $district = Database::selectOne(
            'SELECT region, district FROM zone_districts WHERE zone_id = :z LIMIT 1',
            ['z' => $zone['id']]
        );
        $address = [
            'region' => $district['region'], 'district' => $district['district'],
            'zone_id' => (int) $zone['id'], 'recipient' => 'Asha Mwinyi',
            'phone' => '+255712000001', 'street' => '14 Test Road',
        ];
    }

    $result = $checkout->placeOrder(
        $cartId,
        [$item['seller_id'] => $delivery ? 'delivery' : 'pickup'],
        $address,
        [],
        'sandbox'
    );

    $order = $orders->findByNumber($result['order_number']);
    $subId = (int) $orders->sellerOrdersFor((int) $order['id'])[0]['id'];

    $orders->markPaid((int) $order['id']);
    $orderService->transition($subId, OrderStatus::AwaitingSeller, ActorType::System);

    Auth::actAs($item['seller_user']);
    $orderService->acceptAsSeller($subId, $item['seller_id']);
    $orderService->startPreparing($subId, $item['seller_id']);

    return [
        'sub_id'       => $subId,
        'order_id'     => (int) $order['id'],
        'order_number' => $result['order_number'],
        'item'         => $item,
    ];
}

// ---------------------------------------------------------------------------
TestRunner::section('A. COLLECTION CODES');

in_rollback(static function () use ($pickup, $orders): void {
    $ctx  = paid_order();
    $item = $ctx['item'];

    Auth::actAs($item['seller_user']);
    $code = $pickup->markReadyAndIssueCode($ctx['sub_id'], $item['seller_id']);

    TestRunner::same('A six-character code is issued', 6, strlen($code));
    TestRunner::same(
        'The order is now ready to collect',
        'ready_for_pickup',
        (string) $orders->findSellerOrder($ctx['sub_id'])['status']
    );

    $stored = Database::selectOne(
        'SELECT code_hash, window_to FROM order_pickups WHERE seller_order_id = :id',
        ['id' => $ctx['sub_id']]
    );

    TestRunner::same('Only a SHA-256 hash is stored', 64, strlen((string) $stored['code_hash']));
    TestRunner::check(
        'The plaintext code appears NOWHERE in the database',
        (int) Database::scalar(
            'SELECT COUNT(*) FROM order_pickups WHERE code_hash = :code OR instructions_snapshot LIKE :like',
            ['code' => $code, 'like' => '%' . $code . '%']
        ) === 0,
        'no seller, support agent or admin can read it back'
    );
    TestRunner::check('A collection window is set', $stored['window_to'] !== null, (string) $stored['window_to']);

    $queued = (int) Database::scalar(
        "SELECT COUNT(*) FROM notifications
          WHERE reference_id = :id AND template_key = 'pickup.code_issued'",
        ['id' => $ctx['sub_id']]
    );
    TestRunner::same('The code is sent to the customer in a queued message', 1, $queued);

    $auditLeak = (int) Database::scalar(
        'SELECT COUNT(*) FROM audit_log WHERE detail LIKE :like OR COALESCE(after_json, :empty) LIKE :like2',
        ['like' => '%' . $code . '%', 'empty' => '', 'like2' => '%' . $code . '%']
    );
    TestRunner::same('And never reaches the audit log', 0, $auditLeak);
});

in_rollback(static function () use ($pickup, $orders): void {
    $ctx  = paid_order();
    $item = $ctx['item'];

    Auth::actAs($item['seller_user']);
    $code = $pickup->markReadyAndIssueCode($ctx['sub_id'], $item['seller_id']);

    TestRunner::throws(
        'A wrong code does not collect the order',
        static fn () => $pickup->confirmCollection($ctx['sub_id'], $item['seller_id'], 'WRONG1'),
        'does not match'
    );

    TestRunner::same(
        'And the order is still waiting',
        'ready_for_pickup',
        (string) $orders->findSellerOrder($ctx['sub_id'])['status']
    );

    $pickup->confirmCollection($ctx['sub_id'], $item['seller_id'], $code);

    TestRunner::same(
        'The correct code completes it',
        'completed',
        (string) $orders->findSellerOrder($ctx['sub_id'])['status']
    );

    $collected = Database::selectOne(
        'SELECT collected_at, collected_by_user_id FROM order_pickups WHERE seller_order_id = :id',
        ['id' => $ctx['sub_id']]
    );
    TestRunner::check('The collection is timestamped', $collected['collected_at'] !== null);
    TestRunner::check('And records who handed it over', $collected['collected_by_user_id'] !== null);
});

in_rollback(static function () use ($pickup): void {
    $ctx  = paid_order();
    $item = $ctx['item'];

    Auth::actAs($item['seller_user']);
    $code = $pickup->markReadyAndIssueCode($ctx['sub_id'], $item['seller_id']);

    for ($i = 0; $i < 5; $i++) {
        try {
            $pickup->confirmCollection($ctx['sub_id'], $item['seller_id'], 'BAD' . $i . 'XX');
        } catch (Throwable) {
        }
    }

    TestRunner::throws(
        'Five wrong codes lock the order, even against the RIGHT code',
        static fn () => $pickup->confirmCollection($ctx['sub_id'], $item['seller_id'], $code),
        'Too many incorrect codes'
    );
});

in_rollback(static function () use ($pickup): void {
    $ctx  = paid_order();
    $item = $ctx['item'];

    Auth::actAs($item['seller_user']);
    $code = $pickup->markReadyAndIssueCode($ctx['sub_id'], $item['seller_id']);

    $otherSeller = Database::selectOne(
        'SELECT id, user_id FROM sellers WHERE id <> :s AND status = :active LIMIT 1',
        ['s' => $item['seller_id'], 'active' => 'active']
    );

    Auth::actAs((int) $otherSeller['user_id']);

    TestRunner::throws(
        'Another seller cannot collect this order even WITH the code',
        static fn () => $pickup->confirmCollection($ctx['sub_id'], (int) $otherSeller['id'], $code),
        'could not be found'
    );

    TestRunner::throws(
        'Nor reissue its code',
        static fn () => $pickup->regenerateCode($ctx['sub_id'], (int) $otherSeller['id']),
        'could not be found'
    );
});

in_rollback(static function () use ($pickup): void {
    $ctx  = paid_order();
    $item = $ctx['item'];

    Auth::actAs($item['seller_user']);
    $first = $pickup->markReadyAndIssueCode($ctx['sub_id'], $item['seller_id']);
    $second = $pickup->regenerateCode($ctx['sub_id'], $item['seller_id']);

    TestRunner::check('A replacement code is different', $first !== $second, 'reissued');

    TestRunner::throws(
        'The old code stops working immediately',
        static fn () => $pickup->confirmCollection($ctx['sub_id'], $item['seller_id'], $first),
        'does not match'
    );

    $audited = (int) Database::scalar(
        "SELECT COUNT(*) FROM audit_log
          WHERE action = 'pickup.code.reissued' AND entity_id = :id",
        ['id' => (string) $ctx['sub_id']]
    );
    TestRunner::same('And the reissue is audited', 1, $audited);
});

// ---------------------------------------------------------------------------
TestRunner::section('B. DELIVERY - scoping and progress');

in_rollback(static function () use ($delivery, $tasks, $orders): void {
    $ctx  = paid_order(true);
    $item = $ctx['item'];

    Auth::actAs($item['seller_user']);
    $code = $delivery->markReadyForDispatch($ctx['sub_id'], $item['seller_id']);

    TestRunner::same('A delivery code is six characters', 6, strlen($code));

    $task = $tasks->findForSellerOrder($ctx['sub_id']);
    TestRunner::check('A delivery task exists', $task !== null, (string) $task['task_ref']);
    TestRunner::same('Its code is stored hashed', 64, strlen((string) $task['code_hash']));
    TestRunner::same('It starts unassigned', 'unassigned', (string) $task['status']);

    $agent = seed_user('agent.juma@sokolink.test');
    Auth::actAs(seed_user('admin@sokolink.test'));

    // Make sure the zone matches so the assignment is legitimate.
    Database::statement(
        'UPDATE delivery_tasks SET zone_id = (SELECT zone_id FROM agent_zones WHERE user_id = :a LIMIT 1)
          WHERE id = :id',
        ['a' => $agent, 'id' => $task['id']]
    );

    $delivery->assignToAgent((int) $task['id'], $agent);

    TestRunner::same(
        'The order follows the task to assigned',
        'assigned',
        (string) $orders->findSellerOrder($ctx['sub_id'])['status']
    );

    Auth::actAs($agent);
    $delivery->markPickedUp((int) $task['id'], $agent);
    $delivery->markOutForDelivery((int) $task['id'], $agent);

    TestRunner::same(
        'The agent takes it out for delivery',
        'out_for_delivery',
        (string) $orders->findSellerOrder($ctx['sub_id'])['status']
    );

    TestRunner::throws(
        'A wrong code does not complete the delivery',
        static fn () => $delivery->confirmDelivery((int) $task['id'], $agent, 'NOPE22'),
        'does not match'
    );

    $delivery->confirmDelivery((int) $task['id'], $agent, $code);

    TestRunner::same(
        'The customer code completes it',
        'completed',
        (string) $orders->findSellerOrder($ctx['sub_id'])['status']
    );

    $events = $tasks->eventsFor((int) $task['id']);
    TestRunner::check('Every step is in the event log', count($events) >= 4, count($events) . ' events');
});

in_rollback(static function () use ($delivery, $tasks): void {
    $ctx  = paid_order(true);
    $item = $ctx['item'];

    Auth::actAs($item['seller_user']);
    $delivery->markReadyForDispatch($ctx['sub_id'], $item['seller_id']);

    $task  = $tasks->findForSellerOrder($ctx['sub_id']);
    $juma  = seed_user('agent.juma@sokolink.test');
    $neema = seed_user('agent.neema@sokolink.test');

    Database::statement(
        'UPDATE delivery_tasks SET zone_id = (SELECT zone_id FROM agent_zones WHERE user_id = :a LIMIT 1)
          WHERE id = :id',
        ['a' => $juma, 'id' => $task['id']]
    );

    Auth::actAs(seed_user('admin@sokolink.test'));
    $delivery->assignToAgent((int) $task['id'], $juma);

    Auth::actAs($neema);

    TestRunner::throws(
        'Another agent cannot even read the task',
        static fn () => $delivery->taskDetail((int) $task['id'], $neema),
        'could not be found'
    );

    TestRunner::throws(
        'Nor mark it picked up',
        static fn () => $delivery->markPickedUp((int) $task['id'], $neema),
        'could not be found'
    );

    TestRunner::throws(
        'Nor complete it with a guessed code',
        static fn () => $delivery->confirmDelivery((int) $task['id'], $neema, 'ANY123'),
        'could not be found'
    );

    $visible = array_filter(
        $delivery->myTasks($neema),
        static fn (array $t): bool => (int) $t['id'] === (int) $task['id']
    );
    TestRunner::same('And it never appears in their task list', 0, count($visible));
});

in_rollback(static function () use ($delivery, $tasks, $orders): void {
    $ctx  = paid_order(true);
    $item = $ctx['item'];

    Auth::actAs($item['seller_user']);
    $delivery->markReadyForDispatch($ctx['sub_id'], $item['seller_id']);

    $task  = $tasks->findForSellerOrder($ctx['sub_id']);
    $agent = seed_user('agent.juma@sokolink.test');

    Database::statement(
        'UPDATE delivery_tasks SET zone_id = (SELECT zone_id FROM agent_zones WHERE user_id = :a LIMIT 1),
                max_attempts = 2
          WHERE id = :id',
        ['a' => $agent, 'id' => $task['id']]
    );

    Auth::actAs(seed_user('admin@sokolink.test'));
    $delivery->assignToAgent((int) $task['id'], $agent);

    Auth::actAs($agent);
    $delivery->markPickedUp((int) $task['id'], $agent);
    $delivery->markOutForDelivery((int) $task['id'], $agent);

    $delivery->recordFailedAttempt((int) $task['id'], $agent, DeliveryFailureReason::RecipientAbsent, 'Nobody home');

    TestRunner::same(
        'A failed attempt is recorded on the order',
        'delivery_failed',
        (string) $orders->findSellerOrder($ctx['sub_id'])['status']
    );

    $reason = (string) Database::scalar(
        "SELECT reason_code FROM delivery_events
          WHERE task_id = :id AND event_type = 'attempt_failed'",
        ['id' => $task['id']]
    );
    TestRunner::same('With a reason from the fixed list', 'recipient_absent', $reason);

    $delivery->retryDelivery((int) $task['id'], $agent);
    $delivery->recordFailedAttempt((int) $task['id'], $agent, DeliveryFailureReason::WrongAddress, 'No such house');

    TestRunner::same(
        'Using the last attempt sends the parcel back and queues a refund',
        'refund_pending',
        (string) $orders->findSellerOrder($ctx['sub_id'])['status']
    );

    TestRunner::same(
        'The task is marked returned to the seller',
        'returned_to_seller',
        (string) $tasks->find((int) $task['id'])['status']
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('C. PAYMENT - only a signed callback marks an order paid');

in_rollback(static function () use ($cartService, $checkout, $payments, $orders, $sandbox): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = fulfilment_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    $placed = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox');
    $order  = $orders->findByNumber($placed['order_number']);

    TestRunner::same('The order starts unpaid', 'pending', (string) $order['payment_status']);

    $intent = $payments->createIntent($placed['order_number'], 'sandbox');
    TestRunner::check('An intent is created', str_starts_with($intent['intent_ref'], 'SBX-'), $intent['intent_ref']);

    $intentStatus = (string) Database::scalar(
        'SELECT status FROM payment_intents WHERE id = :id',
        ['id' => $intent['intent_id']]
    );
    TestRunner::same('An intent is NOT a payment', 'created', $intentStatus);
    TestRunner::same(
        'And the order is still unpaid',
        'pending',
        (string) $orders->findByNumber($placed['order_number'])['payment_status']
    );

    $payload = [
        'order_number' => $placed['order_number'],
        'intent_ref'   => $intent['intent_ref'],
        'reference'    => 'GW-REF-' . bin2hex(random_bytes(4)),
        'status'       => 'paid',
        'amount'       => (string) $order['grand_total'],
    ];

    // Unsigned first - what an attacker would POST.
    $forged = $payments->confirmPayment('sandbox', $payload, 'not-a-real-signature');

    TestRunner::same('An unsigned callback is refused', 'signature_invalid', $forged['outcome']);
    TestRunner::same(
        'The order is NOT marked paid by it',
        'pending',
        (string) $orders->findByNumber($placed['order_number'])['payment_status']
    );

    $flagged = (int) Database::scalar(
        "SELECT COUNT(*) FROM payment_transactions
          WHERE order_id = :id AND signature_verified = 0 AND status = 'flagged_for_review'",
        ['id' => $order['id']]
    );
    TestRunner::same('But it IS recorded, so somebody can see a forgery arrived', 1, $flagged);

    // Now properly signed.
    $signature = $sandbox->sign($payload);
    $accepted  = $payments->confirmPayment('sandbox', $payload, $signature);

    TestRunner::same('A signed callback is accepted', 'paid', $accepted['outcome']);
    TestRunner::same(
        'And the order becomes paid',
        'paid',
        (string) $orders->findByNumber($placed['order_number'])['payment_status']
    );

    $subStatus = (string) $orders->sellerOrdersFor((int) $order['id'])[0]['status'];
    TestRunner::same('Payment releases the order to its seller', 'awaiting_seller', $subStatus);

    // The replay.
    $before = (int) Database::scalar(
        'SELECT COUNT(*) FROM payment_transactions WHERE order_id = :id',
        ['id' => $order['id']]
    );

    $replay = $payments->confirmPayment('sandbox', $payload, $signature);

    $after = (int) Database::scalar(
        'SELECT COUNT(*) FROM payment_transactions WHERE order_id = :id',
        ['id' => $order['id']]
    );

    TestRunner::same('A replayed callback reports already_processed', 'already_processed', $replay['outcome']);
    TestRunner::check('So the gateway stops retrying', $replay['handled'], 'handled=true');
    TestRunner::same('And credits nothing a second time', $before, $after);

    $paidRows = (int) Database::scalar(
        "SELECT COUNT(*) FROM payment_transactions WHERE order_id = :id AND status = 'paid'",
        ['id' => $order['id']]
    );
    TestRunner::same('Exactly one successful charge exists', 1, $paidRows);
});

in_rollback(static function () use ($cartService, $checkout, $payments, $orders, $sandbox): void {
    Auth::actAs(seed_user('customer.baraka@sokolink.test'));
    $item   = fulfilment_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 2, $item['store_id']);

    $placed = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox');
    $order  = $orders->findByNumber($placed['order_number']);

    $payload = [
        'order_number' => $placed['order_number'],
        'reference'    => 'GW-CHEAP-' . bin2hex(random_bytes(4)),
        'status'       => 'paid',
        'amount'       => '1.00',
    ];

    $result = $payments->confirmPayment('sandbox', $payload, $sandbox->sign($payload));

    TestRunner::same('A callback for the wrong amount is refused', 'amount_mismatch', $result['outcome']);
    TestRunner::same(
        'The order stays unpaid',
        'pending',
        (string) $orders->findByNumber($placed['order_number'])['payment_status']
    );

    $flagged = (string) Database::scalar(
        'SELECT status FROM payment_transactions WHERE order_id = :id ORDER BY id DESC LIMIT 1',
        ['id' => $order['id']]
    );
    TestRunner::same('And the attempt is flagged for a human to look at', 'flagged_for_review', $flagged);
});

in_rollback(static function () use ($payments, $sandbox): void {
    $payload = [
        'order_number' => 'SL-2026-NOTREAL',
        'reference'    => 'GW-GHOST',
        'status'       => 'paid',
        'amount'       => '1000.00',
    ];

    $result = $payments->confirmPayment('sandbox', $payload, $sandbox->sign($payload));

    TestRunner::same('A callback for an unknown order is refused', 'unknown_order', $result['outcome']);
});

in_rollback(static function () use ($payments): void {
    $options   = $payments->optionsForCheckout();
    $available = array_filter($options, static fn (array $o): bool => $o['available']);
    $blocked   = array_filter($options, static fn (array $o): bool => !$o['available']);

    TestRunner::check('Some payment methods are usable', count($available) >= 2, count($available) . ' available');
    TestRunner::check('Mobile money is offered but blocked', count($blocked) >= 4, count($blocked) . ' unavailable');

    foreach ($blocked as $option) {
        TestRunner::check(
            sprintf('"%s" says WHY it cannot be used', $option['label']),
            $option['reason'] !== '',
            $option['reason']
        );
    }

    $sandboxOption = array_values(array_filter($options, static fn (array $o): bool => $o['key'] === 'sandbox'))[0];
    TestRunner::check(
        'The sandbox driver declares itself simulated',
        $sandboxOption['simulated'] && str_contains($sandboxOption['label'], 'no money moves'),
        $sandboxOption['label']
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('D. UNPAID EXPIRY');

in_rollback(static function () use ($cartService, $checkout, $payments, $orders): void {
    Auth::actAs(seed_user('customer.grace@sokolink.test'));
    $item   = fulfilment_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 2, $item['store_id']);

    $placed = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox');
    $order  = $orders->findByNumber($placed['order_number']);

    $reservedBefore = (int) Database::scalar(
        'SELECT qty_reserved FROM inventory WHERE product_id = :p AND store_id = :s',
        ['p' => $item['product_id'], 's' => $item['store_id']]
    );

    // Wind the clock back past the expiry.
    Database::statement(
        'UPDATE orders SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id = :id',
        ['id' => $order['id']]
    );

    $result = $payments->expireUnpaidOrders();

    TestRunner::check('The sweep finds the expired order', $result['expired'] >= 1, $result['expired'] . ' expired');
    TestRunner::same(
        'It is marked expired',
        'expired',
        (string) $orders->findByNumber($placed['order_number'])['payment_status']
    );
    TestRunner::same(
        'Its sub-order follows',
        'expired_unpaid',
        (string) $orders->sellerOrdersFor((int) $order['id'])[0]['status']
    );

    $reservedAfter = (int) Database::scalar(
        'SELECT qty_reserved FROM inventory WHERE product_id = :p AND store_id = :s',
        ['p' => $item['product_id'], 's' => $item['store_id']]
    );

    TestRunner::same(
        'And the stock goes back on the shelf',
        $reservedBefore - 2,
        $reservedAfter
    );
});

in_rollback(static function () use ($cartService, $checkout, $payments, $orders): void {
    Auth::actAs(seed_user('customer.joseph@sokolink.test'));
    $item   = fulfilment_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    $placed = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'cash');
    $order  = $orders->findByNumber($placed['order_number']);

    TestRunner::same('A cash order is pending_cod, not pending', 'pending_cod', (string) $order['payment_status']);
    TestRunner::same('And has no expiry, because there is nothing to wait for', null, $order['expires_at']);

    Database::statement('UPDATE orders SET placed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) WHERE id = :id', ['id' => $order['id']]);

    $payments->expireUnpaidOrders();

    TestRunner::same(
        'The expiry sweep leaves cash orders alone',
        'pending_cod',
        (string) $orders->findByNumber($placed['order_number'])['payment_status']
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('E. NO PAYMENT CREDENTIAL IS EVER STORED');

in_rollback(static function () use ($cartService, $checkout, $payments, $orders, $sandbox): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = fulfilment_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    $placed = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox');
    $order  = $orders->findByNumber($placed['order_number']);

    // A badly-behaved provider sends more than it should.
    $payload = [
        'order_number' => $placed['order_number'],
        'reference'    => 'GW-LEAKY-' . bin2hex(random_bytes(4)),
        'status'       => 'paid',
        'amount'       => (string) $order['grand_total'],
        'card_number'  => '4111111111111111',
        'cvv'          => '123',
        'pin'          => '4321',
    ];

    $payments->confirmPayment('sandbox', $payload, $sandbox->sign($payload));

    $stored = (string) Database::scalar(
        'SELECT raw_payload FROM payment_transactions WHERE order_id = :id ORDER BY id DESC LIMIT 1',
        ['id' => $order['id']]
    );

    TestRunner::check('The card number is stripped before storage', !str_contains($stored, '4111111111111111'), 'stripped');
    TestRunner::check('So is the CVV', !str_contains($stored, '"123"'), 'stripped');
    TestRunner::check('So is the PIN', !str_contains($stored, '4321'), 'stripped');
    TestRunner::check('The rest of the payload is kept', str_contains($stored, 'GW-LEAKY'), 'reference retained');

    $columns = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND (column_name LIKE '%card%' OR column_name LIKE '%cvv%'
                 OR column_name LIKE '%pin' OR column_name LIKE '%pan')"
    );
    TestRunner::same('And no column anywhere could hold one', 0, $columns);
});

TestRunner::finish();
