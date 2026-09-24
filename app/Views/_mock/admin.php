<?php

declare(strict_types=1);

/* =============================================================================
 *  MOCK DATA - PHASE 1 ONLY
 * -----------------------------------------------------------------------------
 *  Replaced in Phase 4 by:
 *    UserRepository::list(UserFilter $f)              SellerApplicationRepository::pending()
 *    PaymentRepository::monitor(PaymentFilter $f)     RefundRepository::list()
 *    DeliveryZoneRepository::all()                    AuditRepository::search(AuditFilter $f)
 *    SettingsRepository::all()                        ReportService::*()
 *    ReminderRepository::scheduleFor(?int $customerId)
 *
 *  The audit log is APPEND-ONLY. There is no update or delete path for it in
 *  the application at all, in any phase (FR-ADM-09).
 * ===========================================================================*/

return [
    // =========================================================================
    'users' => [
        ['id' => 1, 'name' => 'Platform Admin', 'email' => 'admin@sokolink.test', 'role' => 'admin',
         'status' => 'active', 'joined_at_utc' => '2026-01-04 08:00:00', 'last_seen_utc' => '2026-09-22 04:50:00', 'orders' => 0],
        ['id' => 2, 'name' => 'Mama Lishe Provisions', 'email' => 'seller.mama.lishe@sokolink.test', 'role' => 'seller',
         'status' => 'active', 'joined_at_utc' => '2026-02-11 09:20:00', 'last_seen_utc' => '2026-09-22 03:15:00', 'orders' => 412],
        ['id' => 3, 'name' => 'Duka Kuu Wholesalers', 'email' => 'seller.duka.kuu@sokolink.test', 'role' => 'seller',
         'status' => 'active', 'joined_at_utc' => '2026-03-02 14:00:00', 'last_seen_utc' => '2026-09-21 16:40:00', 'orders' => 298],
        ['id' => 4, 'name' => 'Bustani Fresh', 'email' => 'seller.bustani@sokolink.test', 'role' => 'seller',
         'status' => 'active', 'joined_at_utc' => '2026-05-19 07:45:00', 'last_seen_utc' => '2026-09-20 11:02:00', 'orders' => 87],
        ['id' => 5, 'name' => 'Sokoni Traders', 'email' => 'seller.pending@sokolink.test', 'role' => 'seller',
         'status' => 'pending_approval', 'joined_at_utc' => '2026-09-21 18:30:00', 'last_seen_utc' => '2026-09-21 18:45:00', 'orders' => 0],
        ['id' => 6, 'name' => 'Juma Kileo', 'email' => 'agent.juma@sokolink.test', 'role' => 'delivery_agent',
         'status' => 'active', 'joined_at_utc' => '2026-04-08 06:00:00', 'last_seen_utc' => '2026-09-22 05:10:00', 'orders' => 0],
        ['id' => 7, 'name' => 'Neema Bakari', 'email' => 'agent.neema@sokolink.test', 'role' => 'delivery_agent',
         'status' => 'active', 'joined_at_utc' => '2026-06-15 06:00:00', 'last_seen_utc' => '2026-09-21 11:20:00', 'orders' => 0],
        ['id' => 8, 'name' => 'Neema Support', 'email' => 'support@sokolink.test', 'role' => 'support',
         'status' => 'active', 'joined_at_utc' => '2026-02-20 08:00:00', 'last_seen_utc' => '2026-09-22 05:45:00', 'orders' => 0],
        ['id' => 9, 'name' => 'Asha Mwinyi', 'email' => 'customer.asha@sokolink.test', 'role' => 'customer',
         'status' => 'active', 'joined_at_utc' => '2026-03-14 19:10:00', 'last_seen_utc' => '2026-09-22 05:55:00', 'orders' => 14],
        ['id' => 10, 'name' => 'Baraka Joseph', 'email' => 'customer.baraka@sokolink.test', 'role' => 'customer',
         'status' => 'active', 'joined_at_utc' => '2026-04-02 12:00:00', 'last_seen_utc' => '2026-09-22 05:40:00', 'orders' => 6],
        ['id' => 11, 'name' => 'Neema Kessy', 'email' => 'neema.k@example.co.tz', 'role' => 'customer',
         'status' => 'active', 'joined_at_utc' => '2026-05-30 10:00:00', 'last_seen_utc' => '2026-09-21 16:40:00', 'orders' => 9],
        ['id' => 12, 'name' => 'Rashid Omary', 'email' => 'rashid@example.co.tz', 'role' => 'customer',
         'status' => 'suspended', 'joined_at_utc' => '2026-06-21 08:00:00', 'last_seen_utc' => '2026-08-30 14:00:00', 'orders' => 2],
    ],

    // =========================================================================
    'applications' => [
        ['id' => 31, 'business_name' => 'Sokoni Traders', 'contact_name' => 'Hamisi Sokoni',
         'email' => 'seller.pending@sokolink.test', 'phone_masked' => '+255 7** *** 336',
         'business_type' => 'Sole trader', 'registration_number' => 'BRELA-772314',
         'region' => 'Dodoma', 'district' => 'Dodoma Urban', 'store_name' => 'Sokoni Traders - Majengo',
         'street' => 'Kuu Street 19', 'offers' => ['pickup', 'delivery'],
         'categories' => 'Dry goods, cooking oil, rice, sugar and household cleaning products.',
         'submitted_at_utc' => '2026-09-21 18:30:00', 'status' => 'pending_approval'],
        ['id' => 30, 'business_name' => 'Mlimani Grocers', 'contact_name' => 'Zainab Mlimani',
         'email' => 'zainab@example.co.tz', 'phone_masked' => '+255 6** *** 001',
         'business_type' => 'Partnership', 'registration_number' => null,
         'region' => 'Morogoro', 'district' => 'Morogoro Urban', 'store_name' => 'Mlimani Grocers',
         'street' => 'Station Road 4', 'offers' => ['pickup'],
         'categories' => 'Fresh vegetables and fruit, small quantities of dry goods.',
         'submitted_at_utc' => '2026-09-19 09:05:00', 'status' => 'pending_approval'],
        ['id' => 28, 'business_name' => 'Pwani Supplies', 'contact_name' => 'Salim Pwani',
         'email' => 'salim@example.co.tz', 'phone_masked' => '+255 7** *** 448',
         'business_type' => 'Company', 'registration_number' => 'BRELA-551200',
         'region' => 'Tanga', 'district' => 'Tanga City', 'store_name' => 'Pwani Supplies',
         'street' => 'Bandari Road 2', 'offers' => ['pickup', 'delivery'],
         'categories' => 'Imported household goods.',
         'submitted_at_utc' => '2026-09-08 11:00:00', 'status' => 'rejected',
         'decision_reason' => 'Registration number could not be verified with BRELA. Reapply with a copy of the certificate.',
         'decided_at_utc' => '2026-09-09 10:15:00', 'decided_by' => 'Platform Admin'],
    ],

    // =========================================================================
    'payments' => [
        ['ref' => 'TXN-88210', 'order_ref' => 'SL-2026-9F3K2A', 'customer' => 'Asha Mwinyi',
         'gateway' => 'sandbox', 'gateway_reference' => 'SBX-8f31c2a9', 'amount' => '80900.00',
         'status' => 'paid', 'at_utc' => '2026-09-20 06:15:00', 'method' => 'sandbox'],
        ['ref' => 'TXN-88206', 'order_ref' => 'SL-2026-7B1X9C', 'customer' => 'Baraka Joseph',
         'gateway' => 'sandbox', 'gateway_reference' => 'SBX-1a7d4e02', 'amount' => '48000.00',
         'status' => 'paid', 'at_utc' => '2026-09-22 04:03:00', 'method' => 'sandbox'],
        ['ref' => 'TXN-88199', 'order_ref' => 'SL-2026-3K9W1E', 'customer' => 'Joseph Shirima',
         'gateway' => 'sandbox', 'gateway_reference' => 'SBX-4c90b115', 'amount' => '40200.00',
         'status' => 'refunded', 'at_utc' => '2026-09-17 10:12:00', 'method' => 'sandbox'],
        ['ref' => 'TXN-88185', 'order_ref' => 'SL-2026-8T4R6Y', 'customer' => 'Grace Temu',
         'gateway' => 'sandbox', 'gateway_reference' => 'SBX-77aa2f18', 'amount' => '100000.00',
         'status' => 'paid', 'at_utc' => '2026-09-19 08:31:00', 'method' => 'sandbox'],
        ['ref' => 'TXN-88170', 'order_ref' => 'SL-2026-2D8M4T', 'customer' => 'Neema Kessy',
         'gateway' => 'cash', 'gateway_reference' => 'COD-2D8M4T', 'amount' => '42200.00',
         'status' => 'pending', 'at_utc' => '2026-09-21 16:40:00', 'method' => 'cash'],
        ['ref' => 'TXN-88140', 'order_ref' => 'SL-2026-5H2P7Q', 'customer' => 'Asha Mwinyi',
         'gateway' => 'sandbox', 'gateway_reference' => 'SBX-2b61ff04', 'amount' => '36200.00',
         'status' => 'paid', 'at_utc' => '2026-08-06 07:21:00', 'method' => 'sandbox'],
    ],

    'refunds' => [
        ['ref' => 'RFD-1204', 'order_ref' => 'SL-2026-3K9W1E', 'customer' => 'Joseph Shirima',
         'amount' => '40200.00', 'status' => 'refunded', 'reason' => 'Seller rejected - stock count error',
         'requested_by' => 'System', 'approved_by' => 'Platform Admin', 'at_utc' => '2026-09-17 13:44:00'],
        ['ref' => 'RFD-1198', 'order_ref' => 'SL-2026-0P3L8N', 'customer' => 'Rashid Omary',
         'amount' => '15600.00', 'status' => 'refund_pending', 'reason' => 'Uncollected past the window',
         'requested_by' => 'System', 'approved_by' => null, 'at_utc' => '2026-09-15 09:00:00'],
    ],

    // =========================================================================
    'zones' => [
        ['id' => 1, 'name' => 'DSM Central', 'region' => 'Dar es Salaam',
         'districts' => ['Ilala', 'Kinondoni'], 'base_fee' => '6000.00',
         'free_threshold' => '150000.00', 'heavy_surcharge' => '2000.00', 'active' => true, 'agents' => 4],
        ['id' => 2, 'name' => 'DSM North', 'region' => 'Dar es Salaam',
         'districts' => ['Kinondoni', 'Ubungo'], 'base_fee' => '6000.00',
         'free_threshold' => '150000.00', 'heavy_surcharge' => '2000.00', 'active' => true, 'agents' => 3],
        ['id' => 3, 'name' => 'Arusha Central', 'region' => 'Arusha',
         'districts' => ['Arusha City'], 'base_fee' => '8000.00',
         'free_threshold' => '200000.00', 'heavy_surcharge' => '3000.00', 'active' => true, 'agents' => 2],
        ['id' => 4, 'name' => 'Mwanza City', 'region' => 'Mwanza',
         'districts' => ['Nyamagana'], 'base_fee' => '7500.00',
         'free_threshold' => '200000.00', 'heavy_surcharge' => '2500.00', 'active' => false, 'agents' => 0],
    ],

    // =========================================================================
    // Append-only. No UI path updates or deletes these rows, in any phase.
    'audit' => [
        ['id' => 55231, 'at_utc' => '2026-09-22 05:10:00', 'actor' => 'Juma Kileo', 'actor_role' => 'delivery_agent',
         'action' => 'delivery.status.update', 'entity' => 'delivery_tasks#4412', 'ip' => '41.86.x.x',
         'detail' => 'picked_up -> out_for_delivery'],
        ['id' => 55230, 'at_utc' => '2026-09-21 14:30:00', 'actor' => 'Neema Support', 'actor_role' => 'support',
         'action' => 'support.customer_record.view', 'entity' => 'users#9', 'ip' => '196.44.x.x',
         'detail' => 'Justification: TKT-2026-0411'],
        ['id' => 55229, 'at_utc' => '2026-09-21 15:05:00', 'actor' => 'Platform Admin', 'actor_role' => 'admin',
         'action' => 'delivery.task.assign', 'entity' => 'delivery_tasks#4412', 'ip' => '41.59.x.x',
         'detail' => 'Assigned to agent#1 Juma Kileo'],
        ['id' => 55228, 'at_utc' => '2026-09-21 07:41:00', 'actor' => 'Mama Lishe Provisions', 'actor_role' => 'seller',
         'action' => 'order.transition.ready_for_pickup', 'entity' => 'seller_orders#SL-2026-9F3K2A-1', 'ip' => '41.222.x.x',
         'detail' => 'Collection code issued (hash stored)'],
        ['id' => 55221, 'at_utc' => '2026-09-17 13:40:00', 'actor' => 'Duka Kuu Wholesalers', 'actor_role' => 'seller',
         'action' => 'order.transition.reject', 'entity' => 'seller_orders#SL-2026-3K9W1E-1', 'ip' => '41.188.x.x',
         'detail' => 'Reason: stock count was wrong'],
        ['id' => 55218, 'at_utc' => '2026-09-15 07:10:00', 'actor' => 'Neema Support', 'actor_role' => 'support',
         'action' => 'notification.consent.withdraw', 'entity' => 'users#14', 'ip' => '196.44.x.x',
         'detail' => 'On customer request, TKT-2026-0407'],
        ['id' => 55202, 'at_utc' => '2026-08-30 14:12:00', 'actor' => 'Platform Admin', 'actor_role' => 'admin',
         'action' => 'user.suspend', 'entity' => 'users#12', 'ip' => '41.59.x.x',
         'detail' => 'Reason: repeated fraudulent chargeback claims'],
        ['id' => 55190, 'at_utc' => '2026-09-09 10:15:00', 'actor' => 'Platform Admin', 'actor_role' => 'admin',
         'action' => 'seller.reject', 'entity' => 'seller_applications#28', 'ip' => '41.59.x.x',
         'detail' => 'Reason: registration number unverifiable'],
    ],

    // =========================================================================
    'settings' => [
        ['key' => 'platform.name', 'value' => 'SokoLink', 'type' => 'string', 'group' => 'General'],
        ['key' => 'platform.currency', 'value' => 'TZS', 'type' => 'string', 'group' => 'General'],
        ['key' => 'platform.timezone_display', 'value' => 'Africa/Dar_es_Salaam', 'type' => 'string', 'group' => 'General'],
        ['key' => 'orders.unpaid_expiry_minutes', 'value' => '60', 'type' => 'int', 'group' => 'Orders'],
        ['key' => 'pickup.collection_window_hours', 'value' => '72', 'type' => 'int', 'group' => 'Orders'],
        ['key' => 'delivery.max_attempts', 'value' => '3', 'type' => 'int', 'group' => 'Delivery'],
        ['key' => 'delivery.assignment_mode', 'value' => 'admin', 'type' => 'enum', 'group' => 'Delivery'],
        ['key' => 'commission.default_percent', 'value' => '5.0', 'type' => 'decimal', 'group' => 'Finance'],
        ['key' => 'reminders.cooldown_days', 'value' => '14', 'type' => 'int', 'group' => 'Retention'],
        ['key' => 'reminders.monthly_cap', 'value' => '4', 'type' => 'int', 'group' => 'Retention'],
        ['key' => 'notify.quiet_hours_start', 'value' => '21', 'type' => 'int', 'group' => 'Retention'],
        ['key' => 'notify.quiet_hours_end', 'value' => '7', 'type' => 'int', 'group' => 'Retention'],
    ],

    // =========================================================================
    // Reminder schedule. Every SKIP records its reason, so "why did this
    // customer not get a reminder?" is answerable (USER_FLOWS.md Flow K).
    'reminders' => [
        ['customer' => 'Asha Mwinyi', 'product' => 'Alizeti Pure Sunflower Cooking Oil',
         'basis' => 'observed_interval', 'basis_detail' => 'Median of 3 prior repeats: 44 days',
         'last_bought_utc' => '2026-08-07 12:05:00', 'next_due_utc' => '2026-09-20 06:00:00',
         'state' => 'sent', 'skip_reason' => null],
        ['customer' => 'Asha Mwinyi', 'product' => 'Mwangaza Moisturising Bath Soap',
         'basis' => 'seller_hint', 'basis_detail' => '42 days x 1 pack',
         'last_bought_utc' => '2026-08-07 12:05:00', 'next_due_utc' => '2026-09-18 06:00:00',
         'state' => 'skipped', 'skip_reason' => 'cooldown'],
        ['customer' => 'Neema Kessy', 'product' => 'Chemchem Drinking Water',
         'basis' => 'seller_hint', 'basis_detail' => '12 days x 2 packs',
         'last_bought_utc' => '2026-09-21 16:40:00', 'next_due_utc' => '2026-10-15 06:00:00',
         'state' => 'scheduled', 'skip_reason' => null],
        ['customer' => 'Baraka Joseph', 'product' => 'Sembe Fine Maize Flour',
         'basis' => 'seller_hint', 'basis_detail' => '30 days x 2 bags',
         'last_bought_utc' => '2026-08-20 09:00:00', 'next_due_utc' => '2026-09-20 06:00:00',
         'state' => 'skipped', 'skip_reason' => 'no_consent'],
        ['customer' => 'Fatuma Hamisi', 'product' => 'Mbeya Premium White Rice',
         'basis' => 'observed_interval', 'basis_detail' => 'Median of 2 prior repeats: 58 days',
         'last_bought_utc' => '2026-07-25 08:00:00', 'next_due_utc' => '2026-09-21 06:00:00',
         'state' => 'skipped', 'skip_reason' => 'consent_withdrawn'],
        ['customer' => 'Joseph Shirima', 'product' => 'Karatasi Kitchen Roll',
         'basis' => 'none', 'basis_detail' => 'No seller hint and only one purchase - nothing scheduled',
         'last_bought_utc' => '2026-09-01 10:00:00', 'next_due_utc' => null,
         'state' => 'not_scheduled', 'skip_reason' => 'insufficient_data'],
    ],
];
