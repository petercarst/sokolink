<?php

declare(strict_types=1);

namespace App\Support\View;

/**
 * A delivery task, shaped for the agent's screens.
 *
 * The agent dashboard was built against a nested shape — a sub-order carrying a
 * `delivery` block — because that is how an agent thinks about it: "the order
 * I am taking to this address". The repository returns one flat row, because
 * that is one query. This is the join between the two.
 *
 * **What an agent may see is the point of this class, not a side effect.**
 * They get the recipient's name, the address, the landmark, the instructions
 * and a masked phone number: everything needed to find a door and hand over a
 * parcel. They do not get the customer's email, their other orders, or what is
 * inside the box beyond a count and a weight
 * (USER_ROLES_AND_PERMISSIONS.md 4.1).
 *
 * And an **offer** — a job not yet accepted — gets less again: zone, collection
 * point, fee, size. No name and no address at all. Those columns are not even
 * selected by the query behind it, so this class could not leak them if it
 * tried.
 */
final class TaskView
{
    /**
     * A task this agent is carrying.
     *
     * @param  array<string,mixed> $row from DeliveryRepository::forAgent() or findForAgent()
     * @return array<string,mixed>
     */
    public static function assigned(array $row): array
    {
        return [
            'task_id'    => (int) $row['id'],
            'ref'        => (string) ($row['sub_number'] ?? ''),
            'status'     => self::orderStatus($row),
            'task_status' => (string) $row['status'],

            'delivery_fee' => (string) $row['fee'],
            'total'        => (string) ($row['order_total'] ?? $row['sub_total'] ?? '0.00'),

            'store_name'   => (string) ($row['store_name'] ?? ''),
            'store_street' => (string) ($row['store_street'] ?? ''),
            'store_district' => (string) ($row['store_district'] ?? ''),
            'seller_name'  => (string) ($row['business_name'] ?? ''),

            'payment_method' => (string) ($row['payment_method'] ?? ''),

            'updated_at_utc' => (string) ($row['assigned_at'] ?? $row['created_at'] ?? ''),

            'delivery' => [
                'task_ref'   => (string) $row['task_ref'],
                'status'     => (string) $row['status'],
                'zone'       => (string) ($row['zone_name'] ?? 'Unzoned'),

                // The four fields that get a parcel to a door.
                'recipient'  => (string) $row['recipient_name'],
                'phone_masked' => Present::store(['phone' => $row['recipient_phone']])['phone_masked'],
                'address'    => (string) $row['address_line'],
                'landmark'   => ($row['landmark'] ?? null) !== null ? (string) $row['landmark'] : null,
                'instructions' => ($row['instructions'] ?? null) !== null ? (string) $row['instructions'] : null,

                // Cash the agent is expected to collect on the doorstep. Null
                // when the order was already paid - an agent asking a customer
                // for money they have already handed over is a bad afternoon
                // for everybody.
                'cod_amount' => ($row['cod_amount'] ?? null) !== null ? (string) $row['cod_amount'] : null,

                'attempts'     => (int) $row['attempts'],
                'max_attempts' => (int) $row['max_attempts'],
                'attempts_left' => max(0, (int) $row['max_attempts'] - (int) $row['attempts']),

                'code_issued'  => ($row['code_hash'] ?? null) !== null,
                'assigned_at_utc'  => ($row['assigned_at'] ?? null) !== null ? (string) $row['assigned_at'] : null,
                'delivered_at_utc' => ($row['delivered_at'] ?? null) !== null ? (string) $row['delivered_at'] : null,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function assignedList(array $rows): array
    {
        return array_map(self::assigned(...), $rows);
    }

    /**
     * A job on offer: enough to decide whether to take it, and no more.
     *
     * Distance and weight are shown as BANDS rather than figures. A band is
     * what an agent actually decides on ("across town, heavy") and it does not
     * hand somebody a way to work out where a parcel is going before they have
     * agreed to take it.
     *
     * @param  array<string,mixed> $row from DeliveryRepository::availableForAgent()
     * @return array<string,mixed>
     */
    public static function offer(array $row): array
    {
        return [
            'task_id'      => (int) $row['id'],
            'ref'          => (string) $row['sub_number'],
            'zone'         => (string) ($row['zone_name'] ?? 'Unzoned'),
            'pickup_store' => (string) $row['store_name'] . ' - ' . (string) $row['store_district'],
            'fee'          => (string) $row['fee'],
            'cod_amount'   => ($row['cod_amount'] ?? null) !== null ? (string) $row['cod_amount'] : null,
            'parcels'      => (int) ($row['line_count'] ?? 0),
            'ready_at_utc' => (string) $row['created_at'],

            // Deliberately coarse, and deliberately not derived from the
            // address, which this query does not fetch.
            'distance_band' => 'Within ' . (string) ($row['zone_name'] ?? 'the zone'),
            'weight_band'   => self::weightBand((int) ($row['line_count'] ?? 0)),
        ];
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function offerList(array $rows): array
    {
        return array_map(self::offer(...), $rows);
    }

    /**
     * The agent's own counters, for the dashboard tiles.
     *
     * @param  array<string,mixed> $summary from DeliveryService::agentSummary()
     * @return array<string,mixed>
     */
    public static function summary(array $summary): array
    {
        $delivered = (int) ($summary['delivered'] ?? 0);
        $failed    = (int) ($summary['failed'] ?? 0);
        $total     = $delivered + $failed;

        return [
            'delivered' => $delivered,
            'failed'    => $failed,
            'active'    => (int) ($summary['active'] ?? 0),
            'fees_earned' => (string) ($summary['fees_earned'] ?? '0.00'),
            // Shown only once there is enough to mean anything. A single
            // delivery is not a 100% success rate, it is one delivery.
            'success_rate' => $total >= 5 ? (int) round($delivered / $total * 100) : null,
            'zones' => array_map(
                static fn (array $z): string => (string) $z['name'],
                $summary['zones'] ?? []
            ),
        ];
    }

    /**
     * The ORDER status a task implies, for the shared badge component.
     *
     * The two vocabularies overlap but are not the same: a task is `assigned`
     * while its order is still `ready_for_dispatch`, because nothing has
     * physically moved yet.
     *
     * @param array<string,mixed> $row
     */
    private static function orderStatus(array $row): string
    {
        if (isset($row['order_status'])) {
            return (string) $row['order_status'];
        }

        return match ((string) $row['status']) {
            'unassigned', 'offered', 'assigned' => 'ready_for_dispatch',
            'picked_up', 'out_for_delivery'     => 'out_for_delivery',
            'delivered'                         => 'delivered',
            'failed'                            => 'delivery_failed',
            'returned_to_seller'                => 'returned_to_seller',
            default                             => 'ready_for_dispatch',
        };
    }

    /** A band, from the line count, because the task row carries no weight. */
    private static function weightBand(int $lines): string
    {
        return match (true) {
            $lines <= 2 => 'Small - one bag',
            $lines <= 5 => 'Medium - a full bag',
            default     => 'Large - more than one trip to the bike',
        };
    }
}
