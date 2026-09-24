<?php

declare(strict_types=1);

/* =============================================================================
 *  MOCK DATA - PHASE 1 ONLY
 * -----------------------------------------------------------------------------
 *  Replaced in Phase 4 by:
 *    OrderRepository::forCustomer(int $customerId, ?string $statusGroup)
 *    OrderRepository::forSeller(int $sellerId, ?string $statusGroup)
 *    OrderRepository::findByRef(string $ref)          + actor scoping
 *    OrderRepository::monitorAll(OrderFilter $f)      admin only
 *
 *  Each row here is a SELLER SUB-ORDER, not a parent order (A-03). The parent
 *  holds the customer and the payment; the sub-order holds the fulfilment. One
 *  parent can appear twice in this list with different statuses - that is the
 *  whole point of the split and the dashboards must show it that way.
 *
 *  CONTRACT - per sub-order:
 *    ref, parent_ref            string   parent + "-N"
 *    customer_name              string   FIRST NAME + initial for sellers. A seller
 *                                        never receives the customer's email
 *                                        (USER_ROLES_AND_PERMISSIONS.md 4.1)
 *    customer_phone_masked      string
 *    seller_id / seller_name    int / string
 *    store_id / store_name      int / string
 *    fulfilment                 'pickup'|'delivery'
 *    status                     sub-order state machine value
 *    payment_status             parent payment state
 *    payment_method             'sandbox'|'cash'
 *    items[]                    name, sku, pack_size, qty, unit_price, line_total, tone
 *    subtotal/delivery_fee/total/commission   DECIMAL strings
 *    placed_at_utc/updated_at_utc             UTC datetimes
 *    pickup{} | delivery{}      whichever applies
 *    history[]                  status, at_utc, actor, actor_type, reason
 *
 *  Source tables (Phase 2): orders, seller_orders, order_items,
 *  order_status_history, order_pickups, delivery_tasks, payment_transactions.
 * ===========================================================================*/

$h = static fn (string $s, string $at, string $actor, string $type, ?string $reason = null): array
    => ['status' => $s, 'at_utc' => $at, 'actor' => $actor, 'actor_type' => $type, 'reason' => $reason];

return [
    // ---- Ready to collect. The customer has a code; the store has its hash. --
    [
        'ref' => 'SL-2026-9F3K2A-1', 'parent_ref' => 'SL-2026-9F3K2A',
        'customer_name' => 'Asha M.', 'customer_phone_masked' => '+255 7** *** 118',
        'seller_id' => 1, 'seller_name' => 'Mama Lishe Provisions',
        'store_id' => 1, 'store_name' => 'Mama Lishe - Kariakoo',
        'fulfilment' => 'pickup', 'status' => 'ready_for_pickup',
        'payment_status' => 'paid', 'payment_method' => 'sandbox',
        'items' => [
            ['name' => 'Alizeti Pure Sunflower Cooking Oil', 'sku' => 'ALZ-OIL-5L', 'pack_size' => '5 L', 'qty' => 1, 'unit_price' => '28500.00', 'line_total' => '28500.00', 'tone' => 'amber'],
            ['name' => 'Chai Bora Loose Leaf Tea', 'sku' => 'CHB-TEA-500', 'pack_size' => '500 g', 'qty' => 2, 'unit_price' => '6200.00', 'line_total' => '12400.00', 'tone' => 'forest'],
        ],
        'subtotal' => '40900.00', 'delivery_fee' => '0.00', 'total' => '40900.00', 'commission' => '2045.00',
        'placed_at_utc' => '2026-09-20 06:14:00', 'updated_at_utc' => '2026-09-21 07:41:00',
        'pickup' => [
            'window_from' => '2026-09-21 06:00:00', 'window_to' => '2026-09-24 17:00:00',
            'instructions' => 'Collection counter is inside the main entrance on the left.',
            'code_plain' => 'K7M2QP', 'collected_at_utc' => null,
        ],
        'delivery' => null,
        'history' => [
            $h('pending_payment', '2026-09-20 06:14:00', 'Asha M.', 'customer'),
            $h('awaiting_seller', '2026-09-20 06:15:00', 'System', 'system', 'Payment confirmed by verified callback'),
            $h('confirmed', '2026-09-20 07:02:00', 'Mama Lishe Provisions', 'seller'),
            $h('preparing', '2026-09-21 05:30:00', 'Mama Lishe Provisions', 'seller'),
            $h('ready_for_pickup', '2026-09-21 07:41:00', 'Mama Lishe Provisions', 'seller', 'Collection code issued'),
        ],
    ],

    // ---- Same parent order, different seller, out for delivery. -------------
    [
        'ref' => 'SL-2026-9F3K2A-2', 'parent_ref' => 'SL-2026-9F3K2A',
        'customer_name' => 'Asha M.', 'customer_phone_masked' => '+255 7** *** 118',
        'seller_id' => 2, 'seller_name' => 'Duka Kuu Wholesalers',
        'store_id' => 3, 'store_name' => 'Duka Kuu - Arusha Central',
        'fulfilment' => 'delivery', 'status' => 'out_for_delivery',
        'payment_status' => 'paid', 'payment_method' => 'sandbox',
        'items' => [
            ['name' => 'Mtoto Dry Nappies Size 4', 'sku' => 'MTO-NAP-4', 'pack_size' => '50 nappies', 'qty' => 1, 'unit_price' => '34000.00', 'line_total' => '34000.00', 'tone' => 'sky'],
        ],
        'subtotal' => '34000.00', 'delivery_fee' => '6000.00', 'total' => '40000.00', 'commission' => '1700.00',
        'placed_at_utc' => '2026-09-20 06:14:00', 'updated_at_utc' => '2026-09-22 05:10:00',
        'pickup' => null,
        'delivery' => [
            'task_ref' => 'DEL-4412', 'recipient' => 'Asha Mwinyi', 'phone_masked' => '+255 7** *** 118',
            'address' => 'Chole Road 22, Msasani, Kinondoni, Dar es Salaam',
            'landmark' => 'Blue gate opposite the pharmacy', 'zone' => 'DSM Central',
            'instructions' => 'Call on arrival, the gate bell does not work.',
            'agent_name' => 'Juma Kileo', 'agent_id' => 1, 'attempts' => 0,
            'code_plain' => '4471', 'delivered_at_utc' => null,
        ],
        'history' => [
            $h('pending_payment', '2026-09-20 06:14:00', 'Asha M.', 'customer'),
            $h('awaiting_seller', '2026-09-20 06:15:00', 'System', 'system', 'Payment confirmed by verified callback'),
            $h('confirmed', '2026-09-20 09:20:00', 'Duka Kuu Wholesalers', 'seller'),
            $h('preparing', '2026-09-21 08:00:00', 'Duka Kuu Wholesalers', 'seller'),
            $h('ready_for_dispatch', '2026-09-21 14:22:00', 'Duka Kuu Wholesalers', 'seller', 'Delivery task created'),
            $h('assigned', '2026-09-21 15:05:00', 'Platform Admin', 'admin', 'Assigned to Juma Kileo'),
            $h('picked_up', '2026-09-22 04:40:00', 'Juma Kileo', 'agent'),
            $h('out_for_delivery', '2026-09-22 05:10:00', 'Juma Kileo', 'agent'),
        ],
    ],

    // ---- Waiting on the seller to accept or reject. -------------------------
    [
        'ref' => 'SL-2026-7B1X9C-1', 'parent_ref' => 'SL-2026-7B1X9C',
        'customer_name' => 'Baraka J.', 'customer_phone_masked' => '+255 6** *** 903',
        'seller_id' => 1, 'seller_name' => 'Mama Lishe Provisions',
        'store_id' => 2, 'store_name' => 'Mama Lishe - Mbezi Beach',
        'fulfilment' => 'pickup', 'status' => 'awaiting_seller',
        'payment_status' => 'paid', 'payment_method' => 'sandbox',
        'items' => [
            ['name' => 'Sembe Fine Maize Flour', 'sku' => 'NYB-SMB-10', 'pack_size' => '10 kg', 'qty' => 2, 'unit_price' => '24000.00', 'line_total' => '48000.00', 'tone' => 'cream'],
        ],
        'subtotal' => '48000.00', 'delivery_fee' => '0.00', 'total' => '48000.00', 'commission' => '2400.00',
        'placed_at_utc' => '2026-09-22 04:02:00', 'updated_at_utc' => '2026-09-22 04:03:00',
        'pickup' => [
            'window_from' => null, 'window_to' => null,
            'instructions' => 'Ring the bell at the side gate marked COLLECTIONS.',
            'code_plain' => null, 'collected_at_utc' => null,
        ],
        'delivery' => null,
        'history' => [
            $h('pending_payment', '2026-09-22 04:02:00', 'Baraka J.', 'customer'),
            $h('awaiting_seller', '2026-09-22 04:03:00', 'System', 'system', 'Payment confirmed by verified callback'),
        ],
    ],

    // ---- Being prepared. ----------------------------------------------------
    [
        'ref' => 'SL-2026-2D8M4T-1', 'parent_ref' => 'SL-2026-2D8M4T',
        'customer_name' => 'Neema K.', 'customer_phone_masked' => '+255 7** *** 220',
        'seller_id' => 1, 'seller_name' => 'Mama Lishe Provisions',
        'store_id' => 1, 'store_name' => 'Mama Lishe - Kariakoo',
        'fulfilment' => 'delivery', 'status' => 'preparing',
        'payment_status' => 'pending', 'payment_method' => 'cash',
        'items' => [
            ['name' => 'Chemchem Drinking Water', 'sku' => 'CHM-WTR-12', 'pack_size' => '12 x 1.5 L', 'qty' => 2, 'unit_price' => '13200.00', 'line_total' => '26400.00', 'tone' => 'sky'],
            ['name' => 'Safi Multi-Surface Cleaner', 'sku' => 'SAF-MSC-2L', 'pack_size' => '2 L', 'qty' => 1, 'unit_price' => '9800.00', 'line_total' => '9800.00', 'tone' => 'mint'],
        ],
        'subtotal' => '36200.00', 'delivery_fee' => '6000.00', 'total' => '42200.00', 'commission' => '1810.00',
        'placed_at_utc' => '2026-09-21 16:40:00', 'updated_at_utc' => '2026-09-22 03:15:00',
        'pickup' => null,
        'delivery' => [
            'task_ref' => null, 'recipient' => 'Neema Kessy', 'phone_masked' => '+255 7** *** 220',
            'address' => 'Kawe Beach Road 8, Kinondoni, Dar es Salaam',
            'landmark' => 'Green roof, second house after the mosque', 'zone' => 'DSM North',
            'instructions' => 'Cash on delivery - exact change appreciated.',
            'agent_name' => null, 'agent_id' => null, 'attempts' => 0,
            'code_plain' => null, 'delivered_at_utc' => null,
        ],
        'history' => [
            $h('pending_payment', '2026-09-21 16:40:00', 'Neema K.', 'customer', 'Cash on delivery selected'),
            $h('awaiting_seller', '2026-09-21 16:40:00', 'System', 'system'),
            $h('confirmed', '2026-09-21 17:05:00', 'Mama Lishe Provisions', 'seller'),
            $h('preparing', '2026-09-22 03:15:00', 'Mama Lishe Provisions', 'seller'),
        ],
    ],

    // ---- Completed pickup - feeds review invitations and the reorder engine. -
    [
        'ref' => 'SL-2026-5H2P7Q-1', 'parent_ref' => 'SL-2026-5H2P7Q',
        'customer_name' => 'Asha M.', 'customer_phone_masked' => '+255 7** *** 118',
        'seller_id' => 1, 'seller_name' => 'Mama Lishe Provisions',
        'store_id' => 1, 'store_name' => 'Mama Lishe - Kariakoo',
        'fulfilment' => 'pickup', 'status' => 'completed',
        'payment_status' => 'paid', 'payment_method' => 'sandbox',
        'items' => [
            ['name' => 'Alizeti Pure Sunflower Cooking Oil', 'sku' => 'ALZ-OIL-5L', 'pack_size' => '5 L', 'qty' => 1, 'unit_price' => '27000.00', 'line_total' => '27000.00', 'tone' => 'amber'],
            ['name' => 'Mwangaza Moisturising Bath Soap', 'sku' => 'MWG-BSP-4', 'pack_size' => '4 x 175 g', 'qty' => 1, 'unit_price' => '9200.00', 'line_total' => '9200.00', 'tone' => 'rose'],
        ],
        'subtotal' => '36200.00', 'delivery_fee' => '0.00', 'total' => '36200.00', 'commission' => '1810.00',
        'placed_at_utc' => '2026-08-06 07:20:00', 'updated_at_utc' => '2026-08-07 12:05:00',
        'pickup' => [
            'window_from' => '2026-08-07 06:00:00', 'window_to' => '2026-08-10 17:00:00',
            'instructions' => 'Collection counter is inside the main entrance on the left.',
            'code_plain' => null, 'collected_at_utc' => '2026-08-07 12:05:00',
        ],
        'delivery' => null,
        'history' => [
            $h('pending_payment', '2026-08-06 07:20:00', 'Asha M.', 'customer'),
            $h('awaiting_seller', '2026-08-06 07:21:00', 'System', 'system'),
            $h('confirmed', '2026-08-06 08:00:00', 'Mama Lishe Provisions', 'seller'),
            $h('preparing', '2026-08-06 15:10:00', 'Mama Lishe Provisions', 'seller'),
            $h('ready_for_pickup', '2026-08-06 16:02:00', 'Mama Lishe Provisions', 'seller'),
            $h('collected', '2026-08-07 12:05:00', 'Mama Lishe - Kariakoo', 'seller', 'Collection code verified'),
            $h('completed', '2026-08-07 12:05:00', 'System', 'system'),
        ],
    ],

    // ---- Rejected by the seller, with a mandatory reason. -------------------
    [
        'ref' => 'SL-2026-3K9W1E-1', 'parent_ref' => 'SL-2026-3K9W1E',
        'customer_name' => 'Joseph S.', 'customer_phone_masked' => '+255 7** *** 507',
        'seller_id' => 2, 'seller_name' => 'Duka Kuu Wholesalers',
        'store_id' => 3, 'store_name' => 'Duka Kuu - Arusha Central',
        'fulfilment' => 'delivery', 'status' => 'rejected_seller',
        'payment_status' => 'refunded', 'payment_method' => 'sandbox',
        'items' => [
            ['name' => 'Jamaa Laundry Soap Bars', 'sku' => 'JMA-LSB-6', 'pack_size' => '6 x 800 g', 'qty' => 3, 'unit_price' => '11400.00', 'line_total' => '34200.00', 'tone' => 'slate'],
        ],
        'subtotal' => '34200.00', 'delivery_fee' => '6000.00', 'total' => '40200.00', 'commission' => '0.00',
        'placed_at_utc' => '2026-09-17 10:11:00', 'updated_at_utc' => '2026-09-17 13:44:00',
        'pickup' => null,
        'delivery' => [
            'task_ref' => null, 'recipient' => 'Joseph Shirima', 'phone_masked' => '+255 7** *** 507',
            'address' => 'Njiro Road 14, Arusha City, Arusha',
            'landmark' => null, 'zone' => 'Arusha Central', 'instructions' => null,
            'agent_name' => null, 'agent_id' => null, 'attempts' => 0,
            'code_plain' => null, 'delivered_at_utc' => null,
        ],
        'history' => [
            $h('pending_payment', '2026-09-17 10:11:00', 'Joseph S.', 'customer'),
            $h('awaiting_seller', '2026-09-17 10:12:00', 'System', 'system'),
            $h('rejected_seller', '2026-09-17 13:40:00', 'Duka Kuu Wholesalers', 'seller', 'Stock count was wrong - only 1 pack physically in the store'),
            $h('refund_pending', '2026-09-17 13:40:00', 'System', 'system', 'Reservation released'),
            $h('refunded', '2026-09-17 13:44:00', 'System', 'system', 'Refunded to original method'),
        ],
    ],

    // ---- Failed delivery attempt, awaiting retry. ---------------------------
    [
        'ref' => 'SL-2026-8T4R6Y-1', 'parent_ref' => 'SL-2026-8T4R6Y',
        'customer_name' => 'Grace T.', 'customer_phone_masked' => '+255 6** *** 771',
        'seller_id' => 2, 'seller_name' => 'Duka Kuu Wholesalers',
        'store_id' => 3, 'store_name' => 'Duka Kuu - Arusha Central',
        'fulfilment' => 'delivery', 'status' => 'delivery_failed',
        'payment_status' => 'paid', 'payment_method' => 'sandbox',
        'items' => [
            ['name' => 'Mbeya Premium White Rice', 'sku' => 'MBH-RCE-25', 'pack_size' => '25 kg', 'qty' => 1, 'unit_price' => '92000.00', 'line_total' => '92000.00', 'tone' => 'sand'],
        ],
        'subtotal' => '92000.00', 'delivery_fee' => '8000.00', 'total' => '100000.00', 'commission' => '4600.00',
        'placed_at_utc' => '2026-09-19 08:30:00', 'updated_at_utc' => '2026-09-21 11:20:00',
        'pickup' => null,
        'delivery' => [
            'task_ref' => 'DEL-4398', 'recipient' => 'Grace Temu', 'phone_masked' => '+255 6** *** 771',
            'address' => 'Sakina Street 3, Arusha City, Arusha',
            'landmark' => 'Behind the secondary school', 'zone' => 'Arusha Central',
            'instructions' => 'Heavy item - please ring before arriving.',
            'agent_name' => 'Neema Bakari', 'agent_id' => 2, 'attempts' => 1,
            'code_plain' => null, 'delivered_at_utc' => null,
            'failures' => [
                ['at_utc' => '2026-09-21 11:20:00', 'reason_code' => 'recipient_absent', 'note' => 'Nobody at the address, phone unanswered after three attempts.'],
            ],
        ],
        'history' => [
            $h('pending_payment', '2026-09-19 08:30:00', 'Grace T.', 'customer'),
            $h('awaiting_seller', '2026-09-19 08:31:00', 'System', 'system'),
            $h('confirmed', '2026-09-19 09:00:00', 'Duka Kuu Wholesalers', 'seller'),
            $h('preparing', '2026-09-20 07:15:00', 'Duka Kuu Wholesalers', 'seller'),
            $h('ready_for_dispatch', '2026-09-20 10:00:00', 'Duka Kuu Wholesalers', 'seller'),
            $h('assigned', '2026-09-20 10:30:00', 'Platform Admin', 'admin', 'Assigned to Neema Bakari'),
            $h('out_for_delivery', '2026-09-21 09:05:00', 'Neema Bakari', 'agent'),
            $h('delivery_failed', '2026-09-21 11:20:00', 'Neema Bakari', 'agent', 'Recipient absent - attempt 1 of 3'),
        ],
    ],

    // ---- Older completed delivery, used by history and reorder. -------------
    [
        'ref' => 'SL-2026-1A5B3C-1', 'parent_ref' => 'SL-2026-1A5B3C',
        'customer_name' => 'Asha M.', 'customer_phone_masked' => '+255 7** *** 118',
        'seller_id' => 2, 'seller_name' => 'Duka Kuu Wholesalers',
        'store_id' => 3, 'store_name' => 'Duka Kuu - Arusha Central',
        'fulfilment' => 'delivery', 'status' => 'completed',
        'payment_status' => 'paid', 'payment_method' => 'sandbox',
        'items' => [
            ['name' => 'Kilombero Brown Sugar', 'sku' => 'KLB-SGR-2', 'pack_size' => '2 kg', 'qty' => 3, 'unit_price' => '7800.00', 'line_total' => '23400.00', 'tone' => 'clay'],
            ['name' => 'Meno Safi Fluoride Toothpaste', 'sku' => 'MNS-TPS-150', 'pack_size' => '150 ml', 'qty' => 2, 'unit_price' => '4600.00', 'line_total' => '9200.00', 'tone' => 'mint'],
        ],
        'subtotal' => '32600.00', 'delivery_fee' => '6000.00', 'total' => '38600.00', 'commission' => '1630.00',
        'placed_at_utc' => '2026-07-14 12:00:00', 'updated_at_utc' => '2026-07-16 09:30:00',
        'pickup' => null,
        'delivery' => [
            'task_ref' => 'DEL-4102', 'recipient' => 'Asha Mwinyi', 'phone_masked' => '+255 7** *** 118',
            'address' => 'Chole Road 22, Msasani, Kinondoni, Dar es Salaam',
            'landmark' => 'Blue gate opposite the pharmacy', 'zone' => 'DSM Central',
            'instructions' => null, 'agent_name' => 'Juma Kileo', 'agent_id' => 1, 'attempts' => 1,
            'code_plain' => null, 'delivered_at_utc' => '2026-07-16 09:30:00',
        ],
        'history' => [
            $h('pending_payment', '2026-07-14 12:00:00', 'Asha M.', 'customer'),
            $h('awaiting_seller', '2026-07-14 12:01:00', 'System', 'system'),
            $h('confirmed', '2026-07-14 13:30:00', 'Duka Kuu Wholesalers', 'seller'),
            $h('preparing', '2026-07-15 08:00:00', 'Duka Kuu Wholesalers', 'seller'),
            $h('ready_for_dispatch', '2026-07-15 11:00:00', 'Duka Kuu Wholesalers', 'seller'),
            $h('assigned', '2026-07-15 12:00:00', 'Platform Admin', 'admin'),
            $h('out_for_delivery', '2026-07-16 07:00:00', 'Juma Kileo', 'agent'),
            $h('delivered', '2026-07-16 09:30:00', 'Juma Kileo', 'agent', 'Recipient code verified'),
            $h('completed', '2026-07-16 09:30:00', 'System', 'system'),
        ],
    ],
];
