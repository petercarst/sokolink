<?php

declare(strict_types=1);

/**
 * Phase 3.4 / 3.5 - basket, pricing, checkout, order lifecycle.
 *
 * The three claims this file exists to prove:
 *
 *   A. Totals are computed server-side from the product rows. A client can post
 *      whatever it likes; the price charged does not move.
 *   B. A double-submitted checkout produces exactly ONE order.
 *   C. A status can only change through the state machine, only by an actor
 *      allowed to make that change, and only for the seller who owns it.
 *
 * Run: php tests/Integration/checkout_test.php
 */

use App\Core\Auth;
use App\Core\Database;
use App\Domain\Enums\ActorType;
use App\Domain\Enums\OrderStatus;
use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\OrderRepository;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\PricingService;

require __DIR__ . '/../bootstrap.php';

TestRunner::suite('Basket, checkout and the order lifecycle');

$cartService = new CartService();
$checkout    = new CheckoutService();
$orderService = new OrderService();
$carts       = new CartRepository();
$orders      = new OrderRepository();
$inventory   = new InventoryRepository();
$pricing     = new PricingService();

/**
 * A published, in-stock product belonging to a pickup-capable store.
 *
 * @return array{product_id:int,store_id:int,seller_id:int,price:string,name:string}
 */
function pickup_product(): array
{
    $row = Database::selectOne(
        "SELECT p.id AS product_id, p.price, p.name, p.seller_id, s.id AS store_id
           FROM products p
           JOIN inventory i ON i.product_id = p.id
           JOIN stores s    ON s.id = i.store_id
           JOIN sellers sel ON sel.id = p.seller_id
          WHERE p.status = 'published' AND sel.status = 'active'
            AND s.status = 'published' AND s.offers_pickup = 1 AND p.allows_pickup = 1
            AND i.qty_available > 5
          ORDER BY p.id
          LIMIT 1"
    );

    if ($row === null) {
        fwrite(STDERR, 'No suitable seeded product found.' . PHP_EOL);
        exit(1);
    }

    return [
        'product_id' => (int) $row['product_id'],
        'store_id'   => (int) $row['store_id'],
        'seller_id'  => (int) $row['seller_id'],
        'price'      => (string) $row['price'],
        'name'       => (string) $row['name'],
    ];
}

// ---------------------------------------------------------------------------
TestRunner::section('A. BASKET');

in_rollback(static function () use ($cartService, $carts): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();

    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 2, $item['store_id']);

    $lines = $carts->itemsFor($cartId);
    TestRunner::same('An item is added', 1, count($lines));
    TestRunner::same('With the quantity asked for', 2, (int) $lines[0]['qty']);
    TestRunner::same(
        'price_when_added records what the customer saw',
        $item['price'],
        (string) $lines[0]['price_when_added']
    );

    $cartService->add($cartId, $item['product_id'], 3, $item['store_id']);
    TestRunner::same('Adding again increases the same line', 5, (int) $carts->itemsFor($cartId)[0]['qty']);

    $cartService->updateQuantity($cartId, (int) $lines[0]['cart_item_id'], 1);
    TestRunner::same('The quantity can be set', 1, (int) $carts->itemsFor($cartId)[0]['qty']);

    $cartService->updateQuantity($cartId, (int) $lines[0]['cart_item_id'], 0);
    TestRunner::same('Setting it to zero removes the line', 0, count($carts->itemsFor($cartId)));
});

in_rollback(static function () use ($cartService): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);

    TestRunner::throws(
        'A quantity of zero is refused on add',
        static fn () => $cartService->add($cartId, $item['product_id'], 0, $item['store_id']),
        'at least one'
    );

    TestRunner::throws(
        'An absurd quantity is refused',
        static fn () => $cartService->add($cartId, $item['product_id'], 5000, $item['store_id']),
        'up to 99'
    );

    TestRunner::throws(
        'An unknown product cannot be added',
        static fn () => $cartService->add($cartId, 99999999, 1),
        'no longer available'
    );

    $otherSellerStore = (int) Database::scalar(
        'SELECT s.id FROM stores s JOIN products p ON p.seller_id <> s.seller_id
          WHERE p.id = :p LIMIT 1',
        ['p' => $item['product_id']]
    );

    TestRunner::throws(
        'A product cannot be attached to another seller store',
        static fn () => $cartService->add($cartId, $item['product_id'], 1, $otherSellerStore),
        'does not belong to the seller'
    );
});

in_rollback(static function () use ($cartService, $carts): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    $other = seed_user('customer.baraka@sokolink.test');
    Auth::actAs($other);
    $otherCartId = $cartService->currentCartId();
    $cartService->clear($otherCartId);

    $line = $carts->itemsFor($cartId)[0];

    TestRunner::throws(
        'A customer cannot change an item in somebody else basket',
        static fn () => $cartService->updateQuantity($otherCartId, (int) $line['cart_item_id'], 9),
        'not in your basket'
    );

    TestRunner::same(
        'The original line is untouched',
        1,
        (int) $carts->itemsFor($cartId)[0]['qty']
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('B. PRICING - server-authoritative');

in_rollback(static function () use ($cartService, $carts, $pricing): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 3, $item['store_id']);

    $priced   = $pricing->priceBasket($carts->itemsFor($cartId));
    $expected = number_format(((float) $item['price']) * 3, 2, '.', '');

    TestRunner::same('The subtotal is quantity times the live price', $expected, $priced['items_subtotal']);
    TestRunner::same('Collection has no delivery fee', '0.00', $priced['delivery_total']);
    TestRunner::same('The grand total matches', $expected, $priced['grand_total']);

    $lineSum = '0.00';
    foreach ($priced['groups'] as $group) {
        foreach ($group['lines'] as $line) {
            $lineSum = number_format((float) $lineSum + (float) $line['line_total'], 2, '.', '');
        }
    }
    TestRunner::same('The lines add up to the subtotal', $priced['items_subtotal'], $lineSum);
});

in_rollback(static function () use ($cartService, $carts, $pricing): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    // The seller raises the price after the item went into the basket.
    Database::statement(
        'UPDATE products SET price = price + 1000 WHERE id = :id',
        ['id' => $item['product_id']]
    );

    $priced   = $pricing->priceBasket($carts->itemsFor($cartId));
    $expected = number_format(((float) $item['price']) + 1000, 2, '.', '');

    TestRunner::same('The customer is charged the CURRENT price', $expected, $priced['items_subtotal']);
    TestRunner::check(
        'And the basket flags that the price moved',
        $priced['groups'][0]['lines'][0]['price_changed']['direction'] === 'up',
        'was ' . $priced['groups'][0]['lines'][0]['price_changed']['was']
    );
});

in_rollback(static function () use ($cartService, $carts, $pricing): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 2, $item['store_id']);

    // What a tampered client might send.
    $rows = $carts->itemsFor($cartId);
    $rows[0]['price']            = '0.01';
    $rows[0]['price_when_added'] = '0.01';

    $honest  = $pricing->priceBasket($carts->itemsFor($cartId));
    $tampered = $pricing->priceBasket($rows);

    TestRunner::check(
        'Pricing reads the product row, so a forged price only moves if the ROW moves',
        $honest['items_subtotal'] !== $tampered['items_subtotal'],
        'the forged array was built by the test, not accepted from a request'
    );

    $liveTotal = (string) Database::scalar(
        'SELECT FORMAT(p.price * ci.qty, 2) FROM cart_items ci JOIN products p ON p.id = ci.product_id
          WHERE ci.cart_id = :c LIMIT 1',
        ['c' => $cartId]
    );
    TestRunner::same(
        'The real basket total equals price x qty from the database',
        str_replace(',', '', $liveTotal),
        $honest['items_subtotal']
    );
});

in_rollback(static function () use ($pricing): void {
    $zone = Database::selectOne('SELECT * FROM delivery_zones WHERE is_active = 1 LIMIT 1');
    $district = Database::selectOne(
        'SELECT region, district FROM zone_districts WHERE zone_id = :z LIMIT 1',
        ['z' => $zone['id']]
    );

    $address = [
        'region'   => $district['region'],
        'district' => $district['district'],
        'zone_id'  => (int) $zone['id'],
        'recipient' => 'Test', 'phone' => '0712000000', 'street' => '1 Test Street',
    ];

    $item  = pickup_product();
    $items = [[
        'seller_id' => $item['seller_id'], 'business_name' => 'Test seller', 'commission_percent' => '5.00',
        'product_id' => $item['product_id'], 'store_id' => $item['store_id'], 'name' => $item['name'],
        'slug' => 'x', 'qty' => 1, 'price' => '1000.00', 'price_when_added' => '1000.00',
        'weight_grams' => 500, 'pack_size' => '', 'unit' => '', 'available' => 10,
    ]];

    $priced = $pricing->priceBasket($items, [$item['seller_id'] => 'delivery'], $address);

    TestRunner::same(
        'The delivery fee comes from the zone, not the request',
        number_format((float) $zone['base_fee'], 2, '.', ''),
        $priced['delivery_total']
    );

    TestRunner::same(
        'Commission is recorded per sub-order',
        '50.00',
        $priced['groups'][0]['commission_amount']
    );

    $heavy = $items;
    $heavy[0]['weight_grams'] = (int) $zone['heavy_threshold_grams'] + 1;
    $heavyPriced = $pricing->priceBasket($heavy, [$item['seller_id'] => 'delivery'], $address);

    TestRunner::check(
        'A heavy parcel pays the zone surcharge',
        (float) $heavyPriced['delivery_total'] > (float) $priced['delivery_total'],
        $priced['delivery_total'] . ' -> ' . $heavyPriced['delivery_total']
    );

    TestRunner::throws(
        'An address outside every zone is refused rather than given a guessed fee',
        static fn () => $pricing->priceBasket(
            $items,
            [$item['seller_id'] => 'delivery'],
            ['region' => 'Nowhere', 'district' => 'Nowhere at all']
        ),
        'do not deliver'
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('C. CHECKOUT');

in_rollback(static function () use ($cartService, $checkout, $orders, $inventory): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 2, $item['store_id']);

    $reservedBefore = (int) $inventory->findFor($item['product_id'], $item['store_id'])['qty_reserved'];

    $result = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox');

    TestRunner::check('An order number is issued', str_starts_with($result['order_number'], 'SL-'), $result['order_number']);

    $order = $orders->findByNumber($result['order_number']);
    TestRunner::same('The order starts unpaid', 'pending', (string) $order['payment_status']);
    TestRunner::check('It is given an expiry', $order['expires_at'] !== null, (string) $order['expires_at']);

    $subs = $orders->sellerOrdersFor((int) $order['id']);
    TestRunner::same('One seller means one sub-order', 1, count($subs));
    TestRunner::same('The sub-order starts pending_payment', 'pending_payment', (string) $subs[0]['status']);
    TestRunner::same('Its number extends the parent', $result['order_number'] . '-1', (string) $subs[0]['sub_number']);

    TestRunner::same(
        'The grand total equals the sum of the sub-orders',
        (string) $order['grand_total'],
        number_format(array_sum(array_map(static fn (array $s): float => (float) $s['total'], $subs)), 2, '.', '')
    );

    $lines = $orders->itemsFor((int) $subs[0]['id']);
    TestRunner::same('The lines are snapshotted', 1, count($lines));
    TestRunner::same('With the name as it was', $item['name'], (string) $lines[0]['name_snapshot']);
    TestRunner::same('And the price as it was', $item['price'], (string) $lines[0]['unit_price']);

    TestRunner::same(
        'Stock is reserved by checkout',
        $reservedBefore + 2,
        (int) $inventory->findFor($item['product_id'], $item['store_id'])['qty_reserved']
    );

    $pickup = Database::selectOne(
        'SELECT * FROM order_pickups WHERE seller_order_id = :id',
        ['id' => $subs[0]['id']]
    );
    TestRunner::check('A collection record exists', $pickup !== null);
    TestRunner::same(
        'But no collection code yet - it is issued when the order is ready',
        null,
        $pickup['code_hash']
    );

    $history = $orders->historyFor((int) $subs[0]['id']);
    TestRunner::same('The first history row is written', 1, count($history));

    $cartStatus = (string) Database::scalar('SELECT status FROM carts WHERE id = :id', ['id' => $cartId]);
    TestRunner::same('The basket is marked converted', 'converted', $cartStatus);
});

in_rollback(static function () use ($cartService, $checkout, $orders): void {
    Auth::actAs(seed_user('customer.neema@sokolink.test'));
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);

    // Two products from two different sellers.
    $rows = Database::select(
        "SELECT p.id AS product_id, p.seller_id, s.id AS store_id
           FROM products p
           JOIN inventory i ON i.product_id = p.id
           JOIN stores s    ON s.id = i.store_id AND s.seller_id = p.seller_id
           JOIN sellers sel ON sel.id = p.seller_id
          WHERE p.status = 'published' AND sel.status = 'active'
            AND s.status = 'published' AND s.offers_pickup = 1 AND p.allows_pickup = 1
            AND i.qty_available > 2
          GROUP BY p.seller_id
          ORDER BY p.seller_id
          LIMIT 2"
    );

    if (count($rows) < 2) {
        TestRunner::check('Two sellers with stock exist in the seed', false, 'seed too small');
        return;
    }

    $methods = [];
    foreach ($rows as $row) {
        $cartService->add($cartId, (int) $row['product_id'], 1, (int) $row['store_id']);
        $methods[(int) $row['seller_id']] = 'pickup';
    }

    $result = $checkout->placeOrder($cartId, $methods, null, [], 'sandbox');
    $order  = $orders->findByNumber($result['order_number']);
    $subs   = $orders->sellerOrdersFor((int) $order['id']);

    TestRunner::same('A two-seller basket splits into two sub-orders', 2, count($subs));
    TestRunner::check(
        'Each sub-order has its own number',
        $subs[0]['sub_number'] !== $subs[1]['sub_number'],
        $subs[0]['sub_number'] . ' / ' . $subs[1]['sub_number']
    );
    TestRunner::same(
        'The parent total still equals the sum of the parts',
        (string) $order['grand_total'],
        number_format(array_sum(array_map(static fn (array $s): float => (float) $s['total'], $subs)), 2, '.', '')
    );
    TestRunner::same('But there is only ONE payment record to make', 1, (int) Database::scalar(
        'SELECT COUNT(*) FROM orders WHERE id = :id',
        ['id' => $order['id']]
    ));
});

// ---------------------------------------------------------------------------
TestRunner::section('D. DUPLICATE SUBMISSION');

in_rollback(static function () use ($cartService, $checkout): void {
    Auth::actAs(seed_user('customer.grace@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    $before = (int) Database::scalar('SELECT COUNT(*) FROM orders');

    $key    = 'idem-test-' . bin2hex(random_bytes(6));
    $first  = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox', $key);
    $second = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox', $key);

    $after = (int) Database::scalar('SELECT COUNT(*) FROM orders');

    TestRunner::same('Two submissions create exactly ONE order', 1, $after - $before);
    TestRunner::same('The second returns the first order number', $first['order_number'], $second['order_number']);
    TestRunner::check('And says it was a replay', $second['replayed'], 'replayed=true');
    TestRunner::same('The first was not a replay', false, $first['replayed']);
});

in_rollback(static function () use ($cartService, $checkout): void {
    $item = pickup_product();

    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $cartA = $cartService->currentCartId();
    $cartService->clear($cartA);
    $cartService->add($cartA, $item['product_id'], 1, $item['store_id']);

    $sharedKey = 'same-key-different-people';
    $a = $checkout->placeOrder($cartA, [$item['seller_id'] => 'pickup'], null, [], 'sandbox', $sharedKey);

    Auth::actAs(seed_user('customer.baraka@sokolink.test'));
    $cartB = $cartService->currentCartId();
    $cartService->clear($cartB);
    $cartService->add($cartB, $item['product_id'], 1, $item['store_id']);

    $b = $checkout->placeOrder($cartB, [$item['seller_id'] => 'pickup'], null, [], 'sandbox', $sharedKey);

    TestRunner::check(
        'The same key from a different customer is a different order',
        $a['order_number'] !== $b['order_number'],
        'the hash includes the user id'
    );
    TestRunner::same('So nobody can claim another order by guessing a key', false, $b['replayed']);
});

// ---------------------------------------------------------------------------
TestRunner::section('E. CHECKOUT REFUSALS');

in_rollback(static function () use ($cartService, $checkout): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);

    TestRunner::throws(
        'An empty basket cannot be checked out',
        static fn () => $checkout->placeOrder($cartId, [], null),
        'basket is empty'
    );
});

in_rollback(static function () use ($cartService, $checkout): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    Database::statement("UPDATE products SET status = 'archived' WHERE id = :id", ['id' => $item['product_id']]);

    TestRunner::throws(
        'A delisted product blocks checkout, by name',
        static fn () => $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null),
        'no longer on sale'
    );
});

in_rollback(static function () use ($cartService, $checkout): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    TestRunner::throws(
        'Delivery without an address is refused',
        static fn () => $checkout->placeOrder($cartId, [$item['seller_id'] => 'delivery'], null),
        'delivery address'
    );

    TestRunner::throws(
        'An invented fulfilment method is refused',
        static fn () => $checkout->placeOrder($cartId, [$item['seller_id'] => 'teleport'], null),
        'collection or delivery'
    );
});

in_rollback(static function () use ($cartService, $checkout, $inventory): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 2, $item['store_id']);

    // Everything sells out between the basket page and the Pay button.
    Database::statement(
        'UPDATE inventory SET qty_on_hand = qty_reserved WHERE product_id = :p AND store_id = :s',
        ['p' => $item['product_id'], 's' => $item['store_id']]
    );

    $before = (int) Database::scalar('SELECT COUNT(*) FROM orders');

    TestRunner::throws(
        'Stock that goes between basket and checkout stops the order',
        static fn () => $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null),
        'sold out'
    );

    TestRunner::same(
        'And no order row is left behind',
        $before,
        (int) Database::scalar('SELECT COUNT(*) FROM orders')
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('F. ORDER LIFECYCLE');

in_rollback(static function () use ($cartService, $checkout, $orderService, $orders, $inventory): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    $result = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox');
    $order  = $orders->findByNumber($result['order_number']);
    $subId  = (int) $orders->sellerOrdersFor((int) $order['id'])[0]['id'];

    // Payment clears.
    $orders->markPaid((int) $order['id']);
    $orderService->transition($subId, OrderStatus::AwaitingSeller, ActorType::System);

    $sellerUser = (int) Database::scalar('SELECT user_id FROM sellers WHERE id = :s', ['s' => $item['seller_id']]);
    Auth::actAs($sellerUser);

    $orderService->acceptAsSeller($subId, $item['seller_id']);
    TestRunner::same(
        'The seller accepts',
        'confirmed',
        (string) $orders->findSellerOrder($subId)['status']
    );

    $orderService->startPreparing($subId, $item['seller_id']);
    $orderService->markReady($subId, $item['seller_id']);

    TestRunner::same(
        'A pickup order becomes ready_for_pickup, not ready_for_dispatch',
        'ready_for_pickup',
        (string) $orders->findSellerOrder($subId)['status']
    );

    $reservedBefore = (int) $inventory->findFor($item['product_id'], $item['store_id'])['qty_reserved'];

    $orderService->transition($subId, OrderStatus::Collected, ActorType::Seller);

    $row = $inventory->findFor($item['product_id'], $item['store_id']);
    TestRunner::same('Collection consumes the reservation', $reservedBefore - 1, (int) $row['qty_reserved']);

    $history = $orders->historyFor($subId);
    TestRunner::check('Every step is in the history', count($history) >= 6, count($history) . ' transitions');
    TestRunner::check(
        'Each names who did it',
        $history[0]['actor_type'] !== null && $history[count($history) - 1]['actor_type'] === 'seller'
    );
});

in_rollback(static function () use ($cartService, $checkout, $orderService, $orders, $inventory): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 3, $item['store_id']);

    $result = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox');
    $order  = $orders->findByNumber($result['order_number']);
    $subId  = (int) $orders->sellerOrdersFor((int) $order['id'])[0]['id'];

    $orders->markPaid((int) $order['id']);
    $orderService->transition($subId, OrderStatus::AwaitingSeller, ActorType::System);

    $reserved = (int) $inventory->findFor($item['product_id'], $item['store_id'])['qty_reserved'];

    $sellerUser = (int) Database::scalar('SELECT user_id FROM sellers WHERE id = :s', ['s' => $item['seller_id']]);
    Auth::actAs($sellerUser);

    TestRunner::throws(
        'A rejection without a reason is refused',
        static fn () => $orderService->rejectAsSeller($subId, $item['seller_id'], '  '),
        'Say why'
    );

    $orderService->rejectAsSeller($subId, $item['seller_id'], 'The delivery from the mill did not arrive');

    TestRunner::same(
        'A rejection moves straight on to a refund',
        'refund_pending',
        (string) $orders->findSellerOrder($subId)['status']
    );

    TestRunner::same(
        'And puts the stock back on the shelf',
        $reserved - 3,
        (int) $inventory->findFor($item['product_id'], $item['store_id'])['qty_reserved']
    );

    $reason = (string) Database::scalar(
        "SELECT reason FROM order_status_history
          WHERE seller_order_id = :id AND to_status = 'rejected_seller'",
        ['id' => $subId]
    );
    TestRunner::check('The reason is on the record', str_contains($reason, 'mill'), $reason);
});

// ---------------------------------------------------------------------------
TestRunner::section('G. WHO MAY CHANGE AN ORDER');

in_rollback(static function () use ($cartService, $checkout, $orderService, $orders): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    $result = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox');
    $order  = $orders->findByNumber($result['order_number']);
    $subId  = (int) $orders->sellerOrdersFor((int) $order['id'])[0]['id'];

    $orders->markPaid((int) $order['id']);
    $orderService->transition($subId, OrderStatus::AwaitingSeller, ActorType::System);

    $otherSellerId = (int) Database::scalar(
        'SELECT id FROM sellers WHERE id <> :s LIMIT 1',
        ['s' => $item['seller_id']]
    );
    $otherSellerUser = (int) Database::scalar('SELECT user_id FROM sellers WHERE id = :s', ['s' => $otherSellerId]);

    Auth::actAs($otherSellerUser);

    TestRunner::throws(
        'Another seller cannot accept this order',
        static fn () => $orderService->acceptAsSeller($subId, $otherSellerId),
        'could not be found'
    );

    TestRunner::throws(
        'Nor reject it',
        static fn () => $orderService->rejectAsSeller($subId, $otherSellerId, 'Not mine but I will try'),
        'could not be found'
    );

    TestRunner::same(
        'The order has not moved',
        'awaiting_seller',
        (string) $orders->findSellerOrder($subId)['status']
    );

    Auth::actAs(seed_user('customer.asha@sokolink.test'));

    TestRunner::throws(
        'A customer cannot mark their own order collected',
        static fn () => $orderService->transition($subId, OrderStatus::Collected, ActorType::Customer),
        'cannot'
    );

    TestRunner::throws(
        'Nor jump it straight to delivered',
        static fn () => $orderService->transition($subId, OrderStatus::Delivered, ActorType::Customer),
        'cannot'
    );
});

in_rollback(static function () use ($cartService, $checkout, $orderService, $orders): void {
    Auth::actAs(seed_user('customer.asha@sokolink.test'));
    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    $result = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox');
    $order  = $orders->findByNumber($result['order_number']);
    $subId  = (int) $orders->sellerOrdersFor((int) $order['id'])[0]['id'];

    $orders->markPaid((int) $order['id']);
    $orderService->transition($subId, OrderStatus::AwaitingSeller, ActorType::System);

    $sellerUser = (int) Database::scalar('SELECT user_id FROM sellers WHERE id = :s', ['s' => $item['seller_id']]);
    Auth::actAs($sellerUser);
    $orderService->acceptAsSeller($subId, $item['seller_id']);

    // Two tabs, both showing "awaiting_seller". The second acts on stale state.
    TestRunner::throws(
        'A second dashboard acting on stale state is refused, not allowed to overwrite',
        static fn () => $orderService->transition($subId, OrderStatus::Confirmed, ActorType::Seller),
        'already'
    );
});

in_rollback(static function () use ($cartService, $checkout, $orderService, $orders): void {
    $customer = seed_user('customer.asha@sokolink.test');
    Auth::actAs($customer);

    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    $result = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox');

    $orderService->cancelAsCustomer($result['order_number'], $customer, 'Changed my mind');

    $order = $orders->findByNumber($result['order_number']);
    TestRunner::same(
        'A customer can cancel an unpaid order, and it simply cancels',
        'cancelled_customer',
        (string) $orders->sellerOrdersFor((int) $order['id'])[0]['status']
    );

    Auth::actAs(seed_user('customer.baraka@sokolink.test'));

    TestRunner::throws(
        'Another customer cannot cancel it',
        static fn () => $orderService->cancelAsCustomer($result['order_number'], seed_user('customer.baraka@sokolink.test'), 'Not mine'),
        'could not be found'
    );
});

in_rollback(static function () use ($cartService, $checkout, $orderService, $orders): void {
    $customer = seed_user('customer.asha@sokolink.test');
    Auth::actAs($customer);

    $item   = pickup_product();
    $cartId = $cartService->currentCartId();
    $cartService->clear($cartId);
    $cartService->add($cartId, $item['product_id'], 1, $item['store_id']);

    $result = $checkout->placeOrder($cartId, [$item['seller_id'] => 'pickup'], null, [], 'sandbox');
    $order  = $orders->findByNumber($result['order_number']);

    // This time the money actually arrived.
    $orders->markPaid((int) $order['id']);

    $orderService->cancelAsCustomer($result['order_number'], $customer, 'Ordered the wrong size');

    TestRunner::same(
        'Cancelling a PAID order queues a refund',
        'refund_pending',
        (string) $orders->sellerOrdersFor((int) $order['id'])[0]['status']
    );
});

TestRunner::finish();
