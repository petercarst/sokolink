<?php
declare(strict_types=1);
/**
 * Status badge (DS-EXT-01 / DS-EXT-02).
 *
 * @var string $status  a status key from the Phase 2 enumerations
 * @var string $label   optional override
 * @var bool   $onDark  optional
 *
 * Colour is reinforcement, never the only signal: the text label is always
 * present and the dot gives a shape cue.
 *
 * Phase 2 turns these maps into App\Domain enums so PHP and MySQL cannot drift.
 */
$status = $status ?? 'draft';
$onDark = $onDark ?? false;

$tones = [
    'collected' => 'success', 'delivered' => 'success', 'completed' => 'success',
    'paid' => 'success', 'approved' => 'success', 'published' => 'success',
    'active' => 'success', 'in' => 'success',

    'preparing' => 'info', 'ready_for_pickup' => 'info', 'ready_for_dispatch' => 'info',
    'out_for_delivery' => 'info', 'processing' => 'info', 'assigned' => 'info',
    'picked_up' => 'info', 'confirmed' => 'info', 'in_progress' => 'info',

    'pending_payment' => 'warn', 'awaiting_seller' => 'warn', 'collection_overdue' => 'warn',
    'waiting_customer' => 'warn', 'pending_approval' => 'warn', 'pending' => 'warn',
    'low' => 'warn', 'open' => 'warn',

    'rejected_seller' => 'danger', 'cancelled_customer' => 'danger', 'delivery_failed' => 'danger',
    'returned_to_seller' => 'danger', 'expired_unpaid' => 'danger', 'suspended' => 'danger',
    'failed' => 'danger', 'out' => 'danger', 'escalated' => 'danger',

    'draft' => 'neutral', 'archived' => 'neutral', 'closed' => 'neutral',
    'refunded' => 'neutral', 'resolved' => 'neutral',
];

$labels = [
    'in' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock',
    'pending_payment' => 'Awaiting payment', 'awaiting_seller' => 'Awaiting seller',
    'ready_for_pickup' => 'Ready to collect', 'ready_for_dispatch' => 'Ready to dispatch',
    'out_for_delivery' => 'Out for delivery', 'collection_overdue' => 'Collection overdue',
    'rejected_seller' => 'Rejected by seller', 'cancelled_customer' => 'Cancelled',
    'delivery_failed' => 'Delivery failed', 'returned_to_seller' => 'Returned to seller',
    'expired_unpaid' => 'Expired unpaid', 'pending_approval' => 'Pending approval',
    'waiting_customer' => 'Waiting on customer', 'in_progress' => 'In progress',
];

$tone = $tones[$status] ?? 'neutral';
$text = $label ?? ($labels[$status] ?? ucwords(str_replace('_', ' ', $status)));
?>
<span class="sl-badge sl-badge-<?= e($tone) ?><?= $onDark ? ' sl-badge-on-dark' : '' ?>"><?= e($text) ?></span>
