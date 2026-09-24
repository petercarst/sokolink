<?php

declare(strict_types=1);

/* =============================================================================
 *  MOCK DATA - PHASE 1 ONLY
 * -----------------------------------------------------------------------------
 *  Replaced in Phase 4 by:
 *    TicketRepository::queue(TicketFilter $f)
 *    TicketRepository::findByRef(string $ref)        + actor scoping
 *    TicketMessageRepository::forTicket(int $id, bool $includeInternal)
 *    NotificationRepository::logFor(?int $customerId)
 *
 *  NOTE ON INTERNAL NOTES (FR-SUP-02): messages carry `internal => true`. In the
 *  real system the CUSTOMER-FACING query filters those rows out in SQL. They are
 *  never fetched and then hidden in the template, because a template-level hide
 *  is one refactor away from leaking.
 *
 *  NOTE ON NOTIFICATIONS: `skip_reason` records WHY a message was not sent, so
 *  "why did this customer not get a reminder?" is answerable rather than a
 *  mystery (USER_FLOWS.md Flow K).
 * ===========================================================================*/

return [
    // =========================================================================
    'tickets' => [
        [
            'ref' => 'TKT-2026-0412', 'subject' => 'Collection code not working',
            'customer_name' => 'Baraka Joseph', 'customer_email' => 'customer.baraka@sokolink.test',
            'order_ref' => 'SL-2026-7B1X9C', 'category' => 'collection',
            'status' => 'open', 'priority' => 'high', 'assignee' => null,
            'created_at_utc' => '2026-09-22 05:40:00', 'updated_at_utc' => '2026-09-22 05:40:00',
            'messages' => [
                ['author' => 'Baraka Joseph', 'role' => 'customer', 'internal' => false,
                 'at_utc' => '2026-09-22 05:40:00',
                 'body' => 'I am at the Mbezi Beach store and the counter says my code is not recognised. The order still shows as awaiting seller in my account.'],
            ],
        ],
        [
            'ref' => 'TKT-2026-0411', 'subject' => 'Where is my delivery?',
            'customer_name' => 'Grace Temu', 'customer_email' => 'grace@example.co.tz',
            'order_ref' => 'SL-2026-8T4R6Y', 'category' => 'delivery',
            'status' => 'in_progress', 'priority' => 'high', 'assignee' => 'Neema Support',
            'created_at_utc' => '2026-09-21 12:05:00', 'updated_at_utc' => '2026-09-21 14:30:00',
            'messages' => [
                ['author' => 'Grace Temu', 'role' => 'customer', 'internal' => false,
                 'at_utc' => '2026-09-21 12:05:00',
                 'body' => 'The agent marked my delivery as failed but nobody called me. I was home all morning.'],
                ['author' => 'Neema Support', 'role' => 'support', 'internal' => true,
                 'at_utc' => '2026-09-21 13:10:00',
                 'body' => 'Checked the task: agent Neema Bakari logged recipient_absent at 11:20. Phone on file is +255 6** *** 771. Asking the agent for the call log before replying.'],
                ['author' => 'Neema Support', 'role' => 'support', 'internal' => false,
                 'at_utc' => '2026-09-21 14:30:00',
                 'body' => 'Sorry about that Grace. I can see the failed attempt was logged at 11:20. I have asked the agent to confirm what happened and arranged a second attempt for tomorrow morning. Your order is not cancelled and you have not been charged again.'],
            ],
        ],
        [
            'ref' => 'TKT-2026-0409', 'subject' => 'Refund for rejected order',
            'customer_name' => 'Joseph Shirima', 'customer_email' => 'joseph@example.co.tz',
            'order_ref' => 'SL-2026-3K9W1E', 'category' => 'payment',
            'status' => 'escalated', 'priority' => 'normal', 'assignee' => 'Platform Admin',
            'created_at_utc' => '2026-09-18 09:12:00', 'updated_at_utc' => '2026-09-20 08:00:00',
            'messages' => [
                ['author' => 'Joseph Shirima', 'role' => 'customer', 'internal' => false,
                 'at_utc' => '2026-09-18 09:12:00',
                 'body' => 'The seller rejected my order for soap bars. The app says refunded but I have not seen the money.'],
                ['author' => 'Neema Support', 'role' => 'support', 'internal' => false,
                 'at_utc' => '2026-09-18 11:00:00',
                 'body' => 'The refund was recorded on 17 September. Sandbox refunds do not move real money, so nothing will appear in your account during this testing period.'],
                ['author' => 'Neema Support', 'role' => 'support', 'internal' => true,
                 'at_utc' => '2026-09-20 08:00:00',
                 'body' => 'Escalating: this is the third rejection from Duka Kuu this month for stock-count errors. Worth an inventory review with the seller.'],
            ],
        ],
        [
            'ref' => 'TKT-2026-0407', 'subject' => 'Stop the reorder reminders',
            'customer_name' => 'Fatuma Hamisi', 'customer_email' => 'fatuma@example.co.tz',
            'order_ref' => null, 'category' => 'account',
            'status' => 'resolved', 'priority' => 'low', 'assignee' => 'Neema Support',
            'created_at_utc' => '2026-09-15 06:30:00', 'updated_at_utc' => '2026-09-15 07:10:00',
            'messages' => [
                ['author' => 'Fatuma Hamisi', 'role' => 'customer', 'internal' => false,
                 'at_utc' => '2026-09-15 06:30:00',
                 'body' => 'I want to stop the emails about buying rice again.'],
                ['author' => 'Neema Support', 'role' => 'support', 'internal' => false,
                 'at_utc' => '2026-09-15 07:10:00',
                 'body' => 'Done - marketing consent withdrawn and recorded at your request. You will still get messages about orders you place. Every reminder email also has a one-click unsubscribe link that works without logging in.'],
            ],
        ],
        [
            'ref' => 'TKT-2026-0402', 'subject' => 'Wrong pack size delivered',
            'customer_name' => 'Asha Mwinyi', 'customer_email' => 'customer.asha@sokolink.test',
            'order_ref' => 'SL-2026-1A5B3C', 'category' => 'order',
            'status' => 'waiting_customer', 'priority' => 'normal', 'assignee' => 'Neema Support',
            'created_at_utc' => '2026-09-10 15:20:00', 'updated_at_utc' => '2026-09-11 08:00:00',
            'messages' => [
                ['author' => 'Asha Mwinyi', 'role' => 'customer', 'internal' => false,
                 'at_utc' => '2026-09-10 15:20:00',
                 'body' => 'I ordered 2 kg sugar packs and received 1 kg packs.'],
                ['author' => 'Neema Support', 'role' => 'support', 'internal' => false,
                 'at_utc' => '2026-09-11 08:00:00',
                 'body' => 'Thanks Asha. Could you send a photo of the packs and the receipt so I can raise this with the seller?'],
            ],
        ],
    ],

    // =========================================================================
    // Notification delivery log. Support sees status, never message secrets
    // such as a collection code (USER_ROLES_AND_PERMISSIONS.md 4.3).
    'notifications' => [
        ['id' => 9012, 'customer' => 'Asha Mwinyi', 'channel' => 'email', 'category' => 'transactional',
         'template' => 'order.ready_for_pickup', 'status' => 'delivered', 'attempts' => 1,
         'queued_at_utc' => '2026-09-21 07:41:00', 'sent_at_utc' => '2026-09-21 07:41:12',
         'provider_response' => '250 OK', 'skip_reason' => null],
        ['id' => 9011, 'customer' => 'Asha Mwinyi', 'channel' => 'email', 'category' => 'transactional',
         'template' => 'order.out_for_delivery', 'status' => 'delivered', 'attempts' => 1,
         'queued_at_utc' => '2026-09-22 05:10:00', 'sent_at_utc' => '2026-09-22 05:10:09',
         'provider_response' => '250 OK', 'skip_reason' => null],
        ['id' => 9008, 'customer' => 'Baraka Joseph', 'channel' => 'email', 'category' => 'marketing',
         'template' => 'reorder.reminder', 'status' => 'skipped', 'attempts' => 0,
         'queued_at_utc' => '2026-09-20 03:00:00', 'sent_at_utc' => null,
         'provider_response' => null, 'skip_reason' => 'no_consent'],
        ['id' => 9007, 'customer' => 'Neema Kessy', 'channel' => 'email', 'category' => 'marketing',
         'template' => 'reorder.reminder', 'status' => 'skipped', 'attempts' => 0,
         'queued_at_utc' => '2026-09-20 03:00:00', 'sent_at_utc' => null,
         'provider_response' => null, 'skip_reason' => 'already_repurchased'],
        ['id' => 9004, 'customer' => 'Grace Temu', 'channel' => 'email', 'category' => 'transactional',
         'template' => 'delivery.failed', 'status' => 'failed', 'attempts' => 3,
         'queued_at_utc' => '2026-09-21 11:20:00', 'sent_at_utc' => null,
         'provider_response' => '451 Temporary local problem, retry later', 'skip_reason' => null],
        ['id' => 9002, 'customer' => 'Asha Mwinyi', 'channel' => 'sms', 'category' => 'transactional',
         'template' => 'order.ready_for_pickup', 'status' => 'skipped', 'attempts' => 0,
         'queued_at_utc' => '2026-09-21 07:41:00', 'sent_at_utc' => null,
         'provider_response' => null, 'skip_reason' => 'skipped_no_provider'],
        ['id' => 8998, 'customer' => 'Fatuma Hamisi', 'channel' => 'email', 'category' => 'marketing',
         'template' => 'reorder.reminder', 'status' => 'skipped', 'attempts' => 0,
         'queued_at_utc' => '2026-09-16 03:00:00', 'sent_at_utc' => null,
         'provider_response' => null, 'skip_reason' => 'consent_withdrawn'],
        ['id' => 8990, 'customer' => 'Asha Mwinyi', 'channel' => 'email', 'category' => 'marketing',
         'template' => 'reorder.reminder', 'status' => 'delivered', 'attempts' => 1,
         'queued_at_utc' => '2026-09-14 06:00:00', 'sent_at_utc' => '2026-09-14 06:00:04',
         'provider_response' => '250 OK', 'skip_reason' => null],
    ],

    // =========================================================================
    // The customer's own notification inbox.
    'inbox' => [
        ['id' => 1, 'title' => 'Your order is ready to collect',
         'body' => 'Order SL-2026-9F3K2A from Mama Lishe Provisions is packed and waiting at Mama Lishe - Kariakoo. Bring your collection code.',
         'category' => 'transactional', 'at_utc' => '2026-09-21 07:41:00', 'read' => false,
         'link_route' => 'customer.order', 'link_ref' => 'SL-2026-9F3K2A-1'],
        ['id' => 2, 'title' => 'Out for delivery',
         'body' => 'Juma is on the way with the Duka Kuu part of order SL-2026-9F3K2A. Have your delivery code ready.',
         'category' => 'transactional', 'at_utc' => '2026-09-22 05:10:00', 'read' => false,
         'link_route' => 'customer.order', 'link_ref' => 'SL-2026-9F3K2A-2'],
        ['id' => 3, 'title' => 'Running low on cooking oil?',
         'body' => 'You bought a 5 L jerrycan about six weeks ago. If you are getting close to the end, it is one tap to reorder.',
         'category' => 'marketing', 'at_utc' => '2026-09-14 06:00:00', 'read' => false,
         'link_route' => 'customer.reorder', 'link_ref' => null],
        ['id' => 4, 'title' => 'Order collected',
         'body' => 'Thanks for collecting order SL-2026-5H2P7Q. Your receipt is in your order history.',
         'category' => 'transactional', 'at_utc' => '2026-08-07 12:05:00', 'read' => true,
         'link_route' => 'customer.order', 'link_ref' => 'SL-2026-5H2P7Q-1'],
        ['id' => 5, 'title' => 'How was your order?',
         'body' => 'You can now review the products from order SL-2026-5H2P7Q.',
         'category' => 'transactional', 'at_utc' => '2026-08-08 06:00:00', 'read' => true,
         'link_route' => 'customer.reviews', 'link_ref' => null],
    ],
];
