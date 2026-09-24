<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\View;

/* =============================================================================
 *  PHASE 1 ONLY - DELETED IN PHASE 4, with app/Views/_mock/
 * -----------------------------------------------------------------------------
 *  Fakes the queries the dashboards need. Each method names the repository
 *  method that replaces it.
 *
 *  Note how scoping is expressed here: forSeller() filters by seller_id and
 *  forAgent() by agent_id. In Phase 3 those become WHERE clauses driven by the
 *  session-derived actor, never by anything the request supplies - which is the
 *  difference between a filter and an access control.
 * ===========================================================================*/
final class MockDashboard
{
    /** @return list<array<string,mixed>> All seller sub-orders. Admin only. */
    public static function orders(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = View::mock('orders');

        return $rows;
    }

    /** @return array<string,mixed>|null Replaced by OrderRepository::findByRef() + actor scoping */
    public static function order(string $ref): ?array
    {
        foreach (self::orders() as $order) {
            if ($order['ref'] === $ref) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Replaced by OrderRepository::forCustomer().
     *
     * @param 'active'|'history'|'all' $group
     * @return list<array<string,mixed>>
     */
    public static function customerOrders(string $group = 'all', string $customer = 'Asha M.'): array
    {
        $done = ['completed', 'collected', 'delivered', 'rejected_seller', 'cancelled_customer', 'refunded'];

        return array_values(array_filter(self::orders(), static function (array $o) use ($group, $customer, $done): bool {
            if ($o['customer_name'] !== $customer) {
                return false;
            }

            return match ($group) {
                'active'  => !in_array($o['status'], $done, true),
                'history' => in_array($o['status'], $done, true),
                default   => true,
            };
        }));
    }

    /**
     * Replaced by OrderRepository::forSeller(). The seller id comes from the
     * actor in Phase 3, never from the request.
     *
     * @return list<array<string,mixed>>
     */
    public static function sellerOrders(int $sellerId = 1, ?string $statusGroup = null): array
    {
        $rows = array_values(array_filter(
            self::orders(),
            static fn (array $o): bool => $o['seller_id'] === $sellerId
        ));

        if ($statusGroup === null) {
            return $rows;
        }

        $groups = [
            'incoming' => ['awaiting_seller', 'confirmed', 'preparing'],
            'pickup'   => ['ready_for_pickup', 'collection_overdue'],
            'dispatch' => ['ready_for_dispatch', 'assigned', 'picked_up', 'out_for_delivery'],
            'done'     => ['completed', 'collected', 'delivered'],
            'problem'  => ['rejected_seller', 'cancelled_customer', 'delivery_failed', 'returned_to_seller'],
        ];

        $wanted = $groups[$statusGroup] ?? [];

        return array_values(array_filter(
            $rows,
            static fn (array $o): bool => in_array($o['status'], $wanted, true)
        ));
    }

    /**
     * Replaced by DeliveryRepository::forAgent(). An agent sees ONLY tasks
     * assigned to them (FR-DEL-04).
     *
     * @return list<array<string,mixed>>
     */
    public static function agentTasks(int $agentId = 1, bool $completed = false): array
    {
        $live = ['assigned', 'picked_up', 'out_for_delivery', 'delivery_failed'];

        return array_values(array_filter(self::orders(), static function (array $o) use ($agentId, $completed, $live): bool {
            if ($o['fulfilment'] !== 'delivery' || empty($o['delivery']['agent_id'])) {
                return false;
            }
            if ((int) $o['delivery']['agent_id'] !== $agentId) {
                return false;
            }

            return $completed
                ? in_array($o['status'], ['delivered', 'completed', 'returned_to_seller'], true)
                : in_array($o['status'], $live, true);
        }));
    }

    /**
     * Unassigned work an agent may accept. Deliberately shows zone, distance
     * band and fee only - never the customer's address until accepted.
     *
     * @return list<array<string,mixed>>
     */
    public static function agentOffers(): array
    {
        return [
            ['ref' => 'DEL-4421', 'zone' => 'DSM Central', 'distance_band' => '3-6 km',
             'parcels' => 2, 'weight_band' => 'up to 10 kg', 'fee' => '6000.00',
             'pickup_store' => 'Mama Lishe - Kariakoo', 'ready_at_utc' => '2026-09-22 06:30:00',
             'cod_amount' => null],
            ['ref' => 'DEL-4423', 'zone' => 'DSM North', 'distance_band' => '6-12 km',
             'parcels' => 1, 'weight_band' => '10-25 kg', 'fee' => '8000.00',
             'pickup_store' => 'Mama Lishe - Mbezi Beach', 'ready_at_utc' => '2026-09-22 08:00:00',
             'cod_amount' => '42200.00'],
        ];
    }

    /** @return list<array<string,mixed>> Replaced by TicketRepository::queue() */
    public static function tickets(?string $status = null): array
    {
        /** @var array<string,mixed> $data */
        $data = View::mock('support');
        /** @var list<array<string,mixed>> $rows */
        $rows = $data['tickets'];

        if ($status === null) {
            return $rows;
        }

        if ($status === 'open') {
            return array_values(array_filter(
                $rows,
                static fn (array $t): bool => in_array($t['status'], ['open', 'in_progress', 'waiting_customer'], true)
            ));
        }

        return array_values(array_filter($rows, static fn (array $t): bool => $t['status'] === $status));
    }

    /** @return array<string,mixed>|null */
    public static function ticket(string $ref): ?array
    {
        foreach (self::tickets() as $ticket) {
            if ($ticket['ref'] === $ref) {
                return $ticket;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> Replaced by TicketRepository::forCustomer() */
    public static function customerTickets(string $email = 'customer.asha@sokolink.test'): array
    {
        return array_values(array_filter(
            self::tickets(),
            static fn (array $t): bool => $t['customer_email'] === $email
        ));
    }

    /** @return list<array<string,mixed>> Replaced by NotificationRepository::logFor() */
    public static function notificationLog(): array
    {
        /** @var array<string,mixed> $data */
        $data = View::mock('support');

        return $data['notifications'];
    }

    /** @return list<array<string,mixed>> Replaced by NotificationRepository::inboxFor() */
    public static function inbox(): array
    {
        /** @var array<string,mixed> $data */
        $data = View::mock('support');

        return $data['inbox'];
    }

    /** @return array<string,mixed> The whole admin dataset. */
    public static function admin(): array
    {
        /** @var array<string,mixed> $data */
        $data = View::mock('admin');

        return $data;
    }

    /** @return list<array<string,mixed>> */
    public static function adminSet(string $key): array
    {
        $data = self::admin();

        /** @var list<array<string,mixed>> $rows */
        $rows = $data[$key] ?? [];

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function user(int $id): ?array
    {
        foreach (self::adminSet('users') as $user) {
            if ((int) $user['id'] === $id) {
                return $user;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    public static function application(int $id): ?array
    {
        foreach (self::adminSet('applications') as $application) {
            if ((int) $application['id'] === $id) {
                return $application;
            }
        }

        return null;
    }

    /**
     * Products a customer could reorder, with the revalidation outcome the real
     * flow computes before touching the cart (FR-CRM-11, USER_FLOWS.md Flow J).
     *
     * @return list<array<string,mixed>>
     */
    public static function reorderable(): array
    {
        return [
            ['product' => 'Alizeti Pure Sunflower Cooking Oil', 'slug' => 'alizeti-sunflower-oil-5l',
             'pack_size' => '5 L', 'tone' => 'amber', 'seller' => 'Mama Lishe Provisions',
             'last_bought_utc' => '2026-08-07 12:05:00', 'last_price' => '27000.00',
             'current_price' => '28500.00', 'outcome' => 'price_changed', 'available' => 64],
            ['product' => 'Mwangaza Moisturising Bath Soap', 'slug' => 'mwangaza-bath-soap-4pk',
             'pack_size' => '4 x 175 g', 'tone' => 'rose', 'seller' => 'Mama Lishe Provisions',
             'last_bought_utc' => '2026-08-07 12:05:00', 'last_price' => '9200.00',
             'current_price' => '9200.00', 'outcome' => 'available', 'available' => 46],
            ['product' => 'Kilombero Brown Sugar', 'slug' => 'kilombero-brown-sugar-2kg',
             'pack_size' => '2 kg', 'tone' => 'clay', 'seller' => 'Duka Kuu Wholesalers',
             'last_bought_utc' => '2026-07-16 09:30:00', 'last_price' => '7800.00',
             'current_price' => '7800.00', 'outcome' => 'available', 'available' => 140],
            ['product' => 'Meno Safi Fluoride Toothpaste', 'slug' => 'meno-safi-toothpaste-150ml',
             'pack_size' => '150 ml', 'tone' => 'mint', 'seller' => 'Duka Kuu Wholesalers',
             'last_bought_utc' => '2026-07-16 09:30:00', 'last_price' => '4600.00',
             'current_price' => '4600.00', 'outcome' => 'partial', 'available' => 1],
            ['product' => 'Jamaa Laundry Soap Bars', 'slug' => 'jamaa-laundry-soap-bar-6pk',
             'pack_size' => '6 x 800 g', 'tone' => 'slate', 'seller' => 'Duka Kuu Wholesalers',
             'last_bought_utc' => '2026-06-02 10:00:00', 'last_price' => '11400.00',
             'current_price' => '11400.00', 'outcome' => 'unavailable', 'available' => 0],
        ];
    }

    /** Sums a column of decimal strings without float drift in the display. */
    public static function sum(array $rows, string $key): string
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $total += (float) ($row[$key] ?? 0);
        }

        return number_format($total, 2, '.', '');
    }

    /** @return list<array<string,mixed>> Rows for a simple bar chart. */
    public static function salesSeries(): array
    {
        return [
            ['label' => 'Mon', 'orders' => 18, 'revenue' => '612000.00'],
            ['label' => 'Tue', 'orders' => 24, 'revenue' => '798000.00'],
            ['label' => 'Wed', 'orders' => 21, 'revenue' => '705000.00'],
            ['label' => 'Thu', 'orders' => 29, 'revenue' => '934000.00'],
            ['label' => 'Fri', 'orders' => 37, 'revenue' => '1240000.00'],
            ['label' => 'Sat', 'orders' => 44, 'revenue' => '1486000.00'],
            ['label' => 'Sun', 'orders' => 26, 'revenue' => '841000.00'],
        ];
    }
}
