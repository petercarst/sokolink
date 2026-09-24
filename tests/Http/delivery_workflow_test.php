<?php

declare(strict_types=1);

/**
 * Phase 4c - the delivery agent's path, driven through the HTTP layer.
 *
 * Workflow I: a parcel leaves a store, travels, and is handed to somebody who
 * proves they are the right person.
 *
 * What this file exists to prove, which no service test can:
 *
 *   - An agent sees their own tasks and nobody else's, and a task that is not
 *     theirs is a 404.
 *   - A job they have NOT accepted shows them no name and no address.
 *   - The buttons reach the task state machine in the right order, and it
 *     refuses being skipped.
 *   - A delivery cannot be completed without the recipient's code, and the code
 *     is never readable from any page.
 *   - A failed attempt is counted, reasoned, and after enough of them the
 *     parcel goes back and the money is owed.
 *   - Cash taken at the door is recorded as a payment, which it had not been.
 *
 * Run: php tests/Http/delivery_workflow_test.php
 */

use App\Core\Auth;
use App\Core\Database;
use App\Services\DeliveryService;

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/kernel.php';

const SEED_PASSWORD = 'SokoLink!Dev2026';
const TEST_IP       = '127.0.0.1';

TestRunner::suite('Phase 4c - the delivery path over HTTP');

Http::boot();

$createdOrders = [];

/** Signs in as a seeded delivery agent and returns their user id. */
function as_agent(string $email = 'agent.juma@sokolink.test'): int
{
    Http::newVisitor();
    Http::post('/login', ['email' => $email, 'password' => SEED_PASSWORD]);

    return (int) Auth::agentUserId();
}

/**
 * Builds a delivery that is ready for an agent: ordered, accepted, prepared,
 * marked ready (which issues the code) and assigned.
 *
 * Every step goes through the real handler. Writing the columns directly would
 * skip the very transitions this suite is here to check.
 *
 * @return array{order_id:int,sub_id:int,sub_ref:string,task_id:int,task_ref:string,code:string,product_id:int,store_id:int}
 */
function delivery_ready(int $agentId, string $paymentMethod = 'cash'): array
{
    $product = Database::selectOne(
        "SELECT p.id, p.seller_id FROM products p
           JOIN inventory i ON i.product_id = p.id
          WHERE p.status = 'published' AND p.seller_id = 1 AND p.allows_delivery = 1
          GROUP BY p.id HAVING SUM(i.qty_available) > 3
          ORDER BY p.id LIMIT 1"
    );

    Http::newVisitor();
    Http::post('/login', ['email' => 'customer.asha@sokolink.test', 'password' => SEED_PASSWORD]);

    $address = Database::selectOne(
        'SELECT id FROM customer_addresses WHERE user_id = :u AND zone_id IS NOT NULL LIMIT 1',
        ['u' => Auth::id()]
    );

    Http::post('/cart/add', ['product_id' => $product['id'], 'qty' => 1]);
    Http::post('/checkout', [
        'fulfilment' => [(int) $product['seller_id'] => 'delivery'],
        'address_id' => $address['id'],
    ]);
    Http::get('/checkout/payment');
    Http::post('/checkout/place', ['payment_method' => $paymentMethod, 'accept_terms' => '1']);

    $order = Database::selectOne('SELECT * FROM orders ORDER BY id DESC LIMIT 1');

    // Fail loudly rather than carry on with somebody else's order. Checkout can
    // legitimately refuse - the cash cap is a real rule - and silently picking
    // up the previous order produced a cascade of confusing failures three
    // sections later.
    if ($order === null || strtotime((string) $order['placed_at'] . ' UTC') < time() - 120) {
        throw new RuntimeException(
            'Checkout did not place an order. Last flash: ' . Http::flashText()
        );
    }

    $sub = Database::selectOne('SELECT * FROM seller_orders WHERE order_id = :o', ['o' => $order['id']]);

    // A card order has to be settled before the seller may accept it.
    if ($paymentMethod !== 'cash') {
        Http::post('/payments/simulate', ['order_number' => $order['order_number'], 'outcome' => 'paid']);
    }

    Http::newVisitor();
    Http::post('/login', ['email' => 'seller.mama.lishe@sokolink.test', 'password' => SEED_PASSWORD]);
    Http::post('/seller/orders/accept', ['ref' => $sub['sub_number']]);
    Http::post('/seller/orders/prepare', ['ref' => $sub['sub_number']]);
    Http::post('/seller/orders/ready', ['ref' => $sub['sub_number']]);

    $task = Database::selectOne('SELECT * FROM delivery_tasks WHERE seller_order_id = :s', ['s' => $sub['id']]);

    // This zone assigns through an administrator, and those screens are stage
    // 4e, so the assignment goes through the SERVICE rather than by writing the
    // columns - it moves the order to `assigned` too, which every later step
    // depends on.
    Auth::actAs(1);
    (new DeliveryService())->assignToAgent((int) $task['id'], $agentId);
    Auth::actAs(null);

    $message = Database::selectOne(
        "SELECT payload_json FROM notifications
          WHERE template_key = 'delivery.code_issued' AND reference_id = :s
          ORDER BY id DESC LIMIT 1",
        ['s' => $sub['id']]
    );

    return [
        'order_id'   => (int) $order['id'],
        'sub_id'     => (int) $sub['id'],
        'sub_ref'    => (string) $sub['sub_number'],
        'task_id'    => (int) $task['id'],
        'task_ref'   => (string) $task['task_ref'],
        'code'       => (string) (json_decode((string) ($message['payload_json'] ?? '{}'), true)['code'] ?? ''),
        'product_id' => (int) $product['id'],
        'store_id'   => (int) $sub['store_id'],
    ];
}

/** Undoes everything a run created. Mirrors the seller suite's teardown. */
function delivery_cleanup(array $orderIds): void
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
            $status   = (string) $line['status'];
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
                Database::statement(
                    'UPDATE inventory
                        SET qty_reserved = CASE WHEN qty_reserved >= :qty THEN qty_reserved - :qty2 ELSE 0 END
                      WHERE product_id = :p AND store_id = :s',
                    $where + ['qty' => $qty, 'qty2' => $qty]
                );
            }
        }

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

        Database::statement('DELETE FROM payment_transactions WHERE order_id = :o', ['o' => $orderId]);
        Database::statement('DELETE FROM payment_intents WHERE order_id = :o', ['o' => $orderId]);
        Database::statement('DELETE FROM orders WHERE id = :o', ['o' => $orderId]);
    }

    Database::statement("DELETE FROM carts WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@sokolink.test')");
    Database::statement("DELETE FROM idempotency_keys WHERE endpoint = 'checkout.place'");
    Database::statement('DELETE FROM auth_attempts WHERE ip_address = INET6_ATON(:ip)', ['ip' => TEST_IP]);
}

// =============================================================================
TestRunner::section('A. The agent dashboard is an agents-only door');

Http::newVisitor();
$guarded = Http::get('/delivery/tasks');
TestRunner::check(
    'A signed-out visitor is sent to the login page',
    str_contains((string) Http::redirectedTo($guarded), '/login'),
    (string) Http::redirectedTo($guarded)
);

Http::newVisitor();
Http::post('/login', ['email' => 'customer.asha@sokolink.test', 'password' => SEED_PASSWORD]);
TestRunner::same('A customer is refused the agent area with 403', 403, Http::get('/delivery/tasks')->status());

Http::newVisitor();
Http::post('/login', ['email' => 'seller.mama.lishe@sokolink.test', 'password' => SEED_PASSWORD]);
TestRunner::same('So is a seller', 403, Http::get('/delivery/tasks')->status());

$agentId = as_agent();
TestRunner::check('An agent resolves to their own user id', $agentId > 0, 'user ' . $agentId);
TestRunner::same('And the dashboard renders', 200, Http::get('/delivery')->status());

// =============================================================================
TestRunner::section('B. Workflow I - store to doorstep');

$ctx = delivery_ready($agentId);
$createdOrders[] = $ctx['order_id'];

TestRunner::check(
    'Marking a delivery order ready issues its code in the same step',
    $ctx['code'] !== '',
    strlen($ctx['code']) . ' characters, sent to the customer'
);

TestRunner::check(
    'And stores only the hash',
    strlen((string) Database::scalar(
        'SELECT code_hash FROM delivery_tasks WHERE id = :t',
        ['t' => $ctx['task_id']]
    )) === 64
);

TestRunner::same(
    'A cash delivery records what the agent must collect at the door',
    (string) Database::scalar('SELECT total FROM seller_orders WHERE id = :s', ['s' => $ctx['sub_id']]),
    (string) Database::scalar('SELECT cod_amount FROM delivery_tasks WHERE id = :t', ['t' => $ctx['task_id']])
);

as_agent();

$list = Http::get('/delivery/tasks');
TestRunner::check('The task appears on the agent list', Http::sees($list, $ctx['task_ref']), $ctx['task_ref']);

// Out of order on purpose: the task state machine decides, not the button.
Http::post('/delivery/tasks/out', ['ref' => $ctx['task_ref']]);
TestRunner::same(
    'Going out for delivery before collecting it is refused',
    'assigned',
    (string) Database::scalar('SELECT status FROM delivery_tasks WHERE id = :t', ['t' => $ctx['task_id']])
);

Http::post('/delivery/tasks/deliver', ['ref' => $ctx['task_ref'], 'code' => $ctx['code']]);
TestRunner::same(
    'And so is delivering something never collected',
    'assigned',
    (string) Database::scalar('SELECT status FROM delivery_tasks WHERE id = :t', ['t' => $ctx['task_id']])
);

Http::post('/delivery/tasks/picked-up', ['ref' => $ctx['task_ref']]);
TestRunner::same(
    'Collecting from the store moves it on',
    'picked_up',
    (string) Database::scalar('SELECT status FROM delivery_tasks WHERE id = :t', ['t' => $ctx['task_id']])
);

Http::post('/delivery/tasks/out', ['ref' => $ctx['task_ref']]);
TestRunner::same(
    'Then out for delivery',
    'out_for_delivery',
    (string) Database::scalar('SELECT status FROM delivery_tasks WHERE id = :t', ['t' => $ctx['task_id']])
);

TestRunner::same(
    'And the order follows the task',
    'out_for_delivery',
    (string) Database::scalar('SELECT status FROM seller_orders WHERE id = :s', ['s' => $ctx['sub_id']])
);

// =============================================================================
TestRunner::section('C. The code is the proof, and nobody can read it');

$taskPage = Http::get('/delivery/tasks/' . $ctx['task_ref']);
TestRunner::check(
    'The agent task page does NOT show the code',
    !Http::sees($taskPage, $ctx['code']),
    'only the recipient has it'
);

Http::post('/delivery/tasks/deliver', ['ref' => $ctx['task_ref'], 'code' => 'WRONG1']);
TestRunner::same(
    'A wrong code does not hand the parcel over',
    'out_for_delivery',
    (string) Database::scalar('SELECT status FROM delivery_tasks WHERE id = :t', ['t' => $ctx['task_id']])
);

Http::post('/delivery/tasks/deliver', ['ref' => $ctx['task_ref'], 'code' => '']);
TestRunner::check('An empty code is refused before anything is compared', Http::flashText() !== '', Http::flashText());

$onHandBefore = (int) Database::scalar(
    'SELECT qty_on_hand FROM inventory WHERE product_id = :p AND store_id = :s',
    ['p' => $ctx['product_id'], 's' => $ctx['store_id']]
);

Http::post('/delivery/tasks/deliver', ['ref' => $ctx['task_ref'], 'code' => $ctx['code']]);

TestRunner::same(
    'The right code completes the delivery',
    'delivered',
    (string) Database::scalar('SELECT status FROM delivery_tasks WHERE id = :t', ['t' => $ctx['task_id']])
);
TestRunner::same(
    'And the order with it',
    'completed',
    (string) Database::scalar('SELECT status FROM seller_orders WHERE id = :s', ['s' => $ctx['sub_id']])
);
TestRunner::same(
    'Handover takes the units off the shelf',
    $onHandBefore - 1,
    (int) Database::scalar(
        'SELECT qty_on_hand FROM inventory WHERE product_id = :p AND store_id = :s',
        ['p' => $ctx['product_id'], 's' => $ctx['store_id']]
    )
);

// =============================================================================
TestRunner::section('D. Cash taken at the door is money that was taken');

$cashTxn = Database::selectOne(
    "SELECT gateway, gateway_reference, amount, status, direction
       FROM payment_transactions WHERE order_id = :o",
    ['o' => $ctx['order_id']]
);

TestRunner::check('The cash is recorded as a transaction', $cashTxn !== null);

if ($cashTxn !== null) {
    TestRunner::same('Against the cash gateway', 'cash', (string) $cashTxn['gateway']);
    TestRunner::same('As a paid charge', 'paid', (string) $cashTxn['status']);
    TestRunner::same(
        'For the amount the agent was asked to collect',
        (string) Database::scalar('SELECT total FROM seller_orders WHERE id = :s', ['s' => $ctx['sub_id']]),
        (string) $cashTxn['amount']
    );
    TestRunner::check(
        'Referenced by the sub-order, so confirming twice cannot charge twice',
        str_contains((string) $cashTxn['gateway_reference'], $ctx['sub_ref']),
        (string) $cashTxn['gateway_reference']
    );
}

TestRunner::same(
    'And the order is no longer recorded as owing money',
    'paid',
    (string) Database::scalar('SELECT payment_status FROM orders WHERE id = :o', ['o' => $ctx['order_id']])
);

// =============================================================================
TestRunner::section('E. A failed attempt is counted and reasoned');

$failCtx = delivery_ready($agentId);
$createdOrders[] = $failCtx['order_id'];

as_agent();
Http::post('/delivery/tasks/picked-up', ['ref' => $failCtx['task_ref']]);
Http::post('/delivery/tasks/out', ['ref' => $failCtx['task_ref']]);

Http::post('/delivery/tasks/failed', ['ref' => $failCtx['task_ref'], 'reason_code' => 'not-a-reason', 'note' => 'x']);
TestRunner::same(
    'A failure with no recognised reason is refused',
    0,
    (int) Database::scalar('SELECT attempts FROM delivery_tasks WHERE id = :t', ['t' => $failCtx['task_id']])
);

Http::post('/delivery/tasks/failed', [
    'ref'         => $failCtx['task_ref'],
    'reason_code' => 'recipient_absent',
    'note'        => 'Knocked twice, nobody home.',
]);

TestRunner::same(
    'A real failure is counted',
    1,
    (int) Database::scalar('SELECT attempts FROM delivery_tasks WHERE id = :t', ['t' => $failCtx['task_id']])
);
TestRunner::same(
    'And the task is marked failed',
    'failed',
    (string) Database::scalar('SELECT status FROM delivery_tasks WHERE id = :t', ['t' => $failCtx['task_id']])
);
TestRunner::check(
    'With the reason on the append-only event log, for the customer to be told',
    (int) Database::scalar(
        "SELECT COUNT(*) FROM delivery_events
          WHERE task_id = :t AND event_type = 'attempt_failed' AND reason_code = 'recipient_absent'",
        ['t' => $failCtx['task_id']]
    ) === 1
);

Http::post('/delivery/tasks/retry', ['ref' => $failCtx['task_ref']]);
TestRunner::same(
    'An agent can try again while attempts remain',
    'out_for_delivery',
    (string) Database::scalar('SELECT status FROM delivery_tasks WHERE id = :t', ['t' => $failCtx['task_id']])
);

// Burn the remaining attempts.
$max = (int) Database::scalar('SELECT max_attempts FROM delivery_tasks WHERE id = :t', ['t' => $failCtx['task_id']]);

for ($i = 1; $i < $max; $i++) {
    Http::post('/delivery/tasks/failed', [
        'ref'         => $failCtx['task_ref'],
        'reason_code' => 'recipient_absent',
        'note'        => 'Still nobody home.',
    ]);

    if ($i < $max - 1) {
        Http::post('/delivery/tasks/retry', ['ref' => $failCtx['task_ref']]);
    }
}

TestRunner::same(
    'Out of attempts, the parcel goes back to the seller',
    'returned_to_seller',
    (string) Database::scalar('SELECT status FROM delivery_tasks WHERE id = :t', ['t' => $failCtx['task_id']])
);

TestRunner::check(
    'And the order is queued for a refund rather than left in limbo',
    in_array(
        (string) Database::scalar('SELECT status FROM seller_orders WHERE id = :s', ['s' => $failCtx['sub_id']]),
        ['refund_pending', 'returned_to_seller'],
        true
    ),
    (string) Database::scalar('SELECT status FROM seller_orders WHERE id = :s', ['s' => $failCtx['sub_id']])
);

TestRunner::same(
    'An undelivered cash order took no money',
    0,
    (int) Database::scalar(
        'SELECT COUNT(*) FROM payment_transactions WHERE order_id = :o',
        ['o' => $failCtx['order_id']]
    )
);

// =============================================================================
TestRunner::section('F. One agent cannot see or touch another agent');

// The task belongs to the first agent; the SECOND agent is the one who must not
// reach it. Built this way round because zones are real: the other seeded agent
// covers Arusha and could not legitimately be assigned a Dar es Salaam parcel
// at all - the service refuses it, which is itself the right behaviour.
$foreign = delivery_ready($agentId);
$createdOrders[] = $foreign['order_id'];

$otherAgent = as_agent('agent.neema@sokolink.test');

TestRunner::check('The other agent signs in', $otherAgent > 0 && $otherAgent !== $agentId, 'user ' . $otherAgent);

TestRunner::same(
    "Another agent's task reference is a 404, not a 403",
    404,
    Http::get('/delivery/tasks/' . $foreign['task_ref'])->status()
);

TestRunner::same(
    'Nor does it appear on their own list',
    0,
    (int) substr_count(Http::get('/delivery/tasks')->body(), $foreign['task_ref'])
);

Http::post('/delivery/tasks/picked-up', ['ref' => $foreign['task_ref']]);
TestRunner::same(
    'And posting it to a handler changes nothing',
    'assigned',
    (string) Database::scalar('SELECT status FROM delivery_tasks WHERE id = :t', ['t' => $foreign['task_id']])
);

// The zone rule, which is why this section is built the way it is.
Auth::actAs(1);
TestRunner::throws(
    'An agent cannot be assigned a parcel outside the zones they cover',
    static fn () => (new DeliveryService())->assignToAgent($foreign['task_id'], $otherAgent),
    'does not cover the zone'
);
Auth::actAs(null);

// =============================================================================
TestRunner::section('G. An unaccepted job shows no name and no address');

$offers = Http::get('/delivery/offers');
TestRunner::same('The offers page renders', 200, $offers->status());

// Whatever is on offer, the customer's details must not be on that page.
$recipients = Database::select(
    "SELECT DISTINCT recipient_name, address_line FROM delivery_tasks WHERE status = 'unassigned'"
);

$leaked = [];

foreach ($recipients as $row) {
    if ((string) $row['recipient_name'] !== '' && Http::sees($offers, (string) $row['recipient_name'])) {
        $leaked[] = 'name';
    }

    if ((string) $row['address_line'] !== '' && Http::sees($offers, (string) $row['address_line'])) {
        $leaked[] = 'address';
    }
}

TestRunner::same('No recipient name or address appears on the offers page', [], $leaked);

TestRunner::same(
    'Because the query behind it does not select them',
    [],
    array_values(array_intersect(
        ['recipient_name', 'recipient_phone', 'address_line', 'landmark'],
        array_keys(
            (new App\Repositories\DeliveryRepository())->availableForAgent($agentId)[0]
            ?? ['task_ref' => '']
        )
    ))
);

delivery_cleanup($createdOrders);

TestRunner::finish();
