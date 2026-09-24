<?php

declare(strict_types=1);

namespace App\Support\View;

use App\Core\Database;
use App\Repositories\DeliveryRepository;
use App\Repositories\OrderRepository;

/**
 * A seller sub-order, shaped for the dashboards that track one.
 *
 * The customer's order list shows SUB-orders, not parent orders, and that is
 * deliberate (A-03): one parent order split between two sellers can be ready to
 * collect from one and out for delivery from the other, and a list that showed
 * a single row with a single status would have to lie about one of them.
 *
 * **There is no collection code in here.** The mock contract this replaces had
 * a `code_plain` field, because a fixture can hold anything. The real system
 * stores only `code_hash`; the plaintext exists in the message that was sent
 * and nowhere else. The page says a code was issued and when the window closes,
 * and cannot show the code itself - which is the property that makes the code
 * worth anything.
 */
final class SubOrderView
{
    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly DeliveryRepository $deliveries = new DeliveryRepository(),
    ) {
    }

    /**
     * A row for the order list.
     *
     * Deliberately cheap: no items, no history, no delivery join. A list of
     * twenty orders should be twenty rows from one query plus nothing.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function row(array $row): array
    {
        return [
            'seller_order_id' => (int) $row['id'],
            'ref'            => (string) $row['sub_number'],
            'parent_ref'     => (string) $row['order_number'],
            'seller_id'      => (int) $row['seller_id'],
            'seller_name'    => (string) $row['business_name'],
            'store_id'       => $row['store_id'] !== null ? (int) $row['store_id'] : null,
            'store_name'     => (string) ($row['store_name'] ?? ''),
            'fulfilment'     => (string) $row['fulfilment_method'],
            'status'         => (string) $row['status'],
            'payment_status' => (string) $row['payment_status'],
            'payment_method' => (string) $row['payment_method'],
            'total'          => (string) $row['total'],
            'line_count'     => (int) ($row['line_count'] ?? 0),

            // What a SELLER may know about the customer: a first name, an
            // initial, and enough of a phone number to recognise the person at
            // the counter. Never the email, and never the full number - the
            // platform is the channel, not a contact list
            // (USER_ROLES_AND_PERMISSIONS.md 4.1).
            'customer_name'         => self::shortName((string) ($row['contact_name'] ?? '')),
            'customer_phone_masked' => Present::store(['phone' => $row['contact_phone'] ?? ''])['phone_masked'],
            'placed_at_utc'  => (string) $row['placed_at'],
            'updated_at_utc' => (string) $row['updated_at'],

            // Present only on the seller's lists, which join order_pickups.
            // Still no code - just when the window closes and whether one was
            // issued at all.
            'collect_by_utc' => ($row['pickup_window_to'] ?? null) !== null
                ? (string) $row['pickup_window_to']
                : null,
            'code_issued'    => ($row['pickup_code_issued_at'] ?? null) !== null,
        ];
    }

    /**
     * "Asha Mwinyi" becomes "Asha M."
     *
     * Enough to greet somebody by name at the counter and match them to an
     * order; not enough to look them up anywhere else.
     */
    private static function shortName(string $full): string
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];

        if ($parts === [] || $parts[0] === '') {
            return 'A customer';
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return $parts[0] . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function rows(array $rows): array
    {
        return array_map(self::row(...), $rows);
    }

    /**
     * The full detail page for one sub-order.
     *
     * @param  array<string,mixed> $row  a sub-order joined to its parent
     * @return array<string,mixed>
     */
    public function detail(array $row): array
    {
        $subId  = (int) $row['id'];
        $method = (string) $row['fulfilment_method'];

        $commission = (string) ($row['commission_amount'] ?? '0.00');

        return array_merge(self::row($row), [
            'subtotal'     => (string) $row['subtotal'],
            'delivery_fee' => (string) $row['delivery_fee'],
            'commission'   => $commission,
            // What the seller is actually owed. Shown because a seller reading
            // "order value" and expecting that to arrive is how a support
            // ticket starts.
            'payout'       => number_format(
                ((float) $row['total'] - (float) $commission),
                2,
                '.',
                ''
            ),
            'items'        => $this->items($subId),
            'history'      => $this->history($subId),
            'pickup'       => $method === 'pickup' ? $this->pickup($subId) : null,
            'delivery'     => $method === 'delivery' ? $this->delivery($subId) : null,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function items(int $sellerOrderId): array
    {
        return array_map(
            static fn (array $i): array => [
                'name'       => (string) $i['name_snapshot'],
                'sku'        => (string) $i['sku_snapshot'],
                'pack_size'  => (string) $i['pack_size_snapshot'],
                'qty'        => (int) $i['qty'],
                'unit_price' => (string) $i['unit_price'],
                'line_total' => (string) $i['line_total'],
                'slug'       => $i['product_slug'] !== null ? (string) $i['product_slug'] : null,
                'tone'       => Present::product(['slug' => (string) ($i['product_slug'] ?? '')])['tone'],
            ],
            $this->orders->itemsFor($sellerOrderId)
        );
    }

    /** @return list<array<string,mixed>> */
    private function history(int $sellerOrderId): array
    {
        return array_map(
            static fn (array $h): array => [
                'status'     => (string) $h['to_status'],
                'from'       => $h['from_status'] !== null ? (string) $h['from_status'] : null,
                'at_utc'     => (string) $h['created_at'],
                'actor'      => trim((string) $h['actor_name']) ?: 'System',
                'actor_type' => (string) $h['actor_type'],
                'reason'     => $h['reason'] !== null ? (string) $h['reason'] : null,
            ],
            $this->orders->historyFor($sellerOrderId)
        );
    }

    /**
     * Collection details, minus the code.
     *
     * @return array<string,mixed>|null
     */
    private function pickup(int $sellerOrderId): ?array
    {
        $row = Database::selectOne(
            'SELECT p.code_issued_at, p.window_from, p.window_to, p.collected_at,
                    p.instructions_snapshot, s.name AS store_name, s.street, s.district, s.region
               FROM order_pickups p
               JOIN stores s ON s.id = p.store_id
              WHERE p.seller_order_id = :id',
            ['id' => $sellerOrderId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'code_issued'      => $row['code_issued_at'] !== null,
            'code_issued_at'   => $row['code_issued_at'] !== null ? (string) $row['code_issued_at'] : null,
            'window_from'      => $row['window_from'] !== null ? (string) $row['window_from'] : null,
            'window_to'        => $row['window_to'] !== null ? (string) $row['window_to'] : null,
            'instructions'     => (string) ($row['instructions_snapshot'] ?? ''),
            'collected_at_utc' => $row['collected_at'] !== null ? (string) $row['collected_at'] : null,
            'store_address'    => implode(', ', array_filter([
                (string) $row['street'], (string) $row['district'], (string) $row['region'],
            ])),
            'is_overdue' => $row['window_to'] !== null
                && $row['collected_at'] === null
                && strtotime((string) $row['window_to'] . ' UTC') < time(),
        ];
    }

    /**
     * Delivery details, minus the code, and minus the agent's phone number.
     *
     * A customer is told who is bringing their parcel; they are not given the
     * agent's contact details (USER_ROLES_AND_PERMISSIONS.md 4.1).
     *
     * @return array<string,mixed>|null
     */
    private function delivery(int $sellerOrderId): ?array
    {
        $task = $this->deliveries->findForSellerOrder($sellerOrderId);

        if ($task === null) {
            return null;
        }

        return [
            'task_ref'      => (string) $task['task_ref'],
            'status'        => (string) $task['status'],
            'recipient'     => (string) $task['recipient_name'],
            'phone_masked'  => Present::store(['phone' => $task['recipient_phone']])['phone_masked'],
            'address'       => (string) $task['address_line'],
            'landmark'      => $task['landmark'] !== null ? (string) $task['landmark'] : null,
            'instructions'  => $task['instructions'] !== null ? (string) $task['instructions'] : null,
            'agent_name'    => isset($task['agent_name']) && $task['agent_name'] !== null
                ? (string) $task['agent_name']
                : null,
            'attempts'      => (int) $task['attempts'],
            'max_attempts'  => (int) $task['max_attempts'],
            'code_issued'   => $task['code_hash'] !== null,
            'delivered_at_utc' => $task['delivered_at'] !== null ? (string) $task['delivered_at'] : null,

            // Every failed attempt, in the agent's own words, recorded at the
            // time. The customer sees this list and so does support, so there
            // is one account of what happened rather than two that disagree.
            'failures'      => $this->failures((int) $task['id']),
        ];
    }

    /**
     * Failed delivery attempts for a task.
     *
     * @return list<array<string,mixed>>
     */
    private function failures(int $taskId): array
    {
        $events = array_filter(
            $this->deliveries->eventsFor($taskId),
            static fn (array $e): bool => (string) $e['event_type'] === 'attempt_failed'
        );

        return array_map(
            static fn (array $e): array => [
                'reason_code' => (string) ($e['reason_code'] ?? 'unknown'),
                'note'        => (string) ($e['note'] ?? ''),
                'contacted'   => (bool) $e['contacted_recipient'],
                'at_utc'      => (string) $e['created_at'],
            ],
            array_values($events)
        );
    }
}
