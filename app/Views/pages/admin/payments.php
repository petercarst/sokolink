<?php
declare(strict_types=1);
/**
 * Payment and refund monitor.
 *
 * The gateway reference column is the important one. It carries a UNIQUE
 * constraint with the gateway, which is what makes webhook replay safe: a
 * repeated event hits the constraint and is ignored, rather than crediting an
 * order twice (FR-PAY-05). Idempotency is a database guarantee here, not
 * application logic that can be refactored away.
 *
 * @var list<array<string,mixed>> $payments
 * @var list<array<string,mixed>> $refunds
 */
$paid = array_filter($payments, static fn (array $p): bool => $p['status'] === 'paid');
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Payments and refunds',
        'subtitle' => 'Every recorded transaction. Refunds need your approval; support can only request one.',
    ]) ?>

    <?= component('stat-row', ['stats' => [
        ['label' => 'Confirmed', 'value' => money_compact(\App\Support\MockDashboard::sum($paid, 'amount')), 'hint' => 'Verified server-side'],
        ['label' => 'Transactions', 'value' => (string) count($payments), 'hint' => 'All statuses'],
        ['label' => 'Awaiting cash', 'value' => (string) count(array_filter($payments, static fn (array $p): bool => $p['status'] === 'pending')), 'hint' => 'Cash on fulfilment'],
        ['label' => 'Refunds pending', 'value' => (string) count(array_filter($refunds, static fn (array $r): bool => $r['status'] === 'refund_pending')), 'hint' => 'Need your approval'],
    ]]) ?>

    <div class="sl-alert sl-alert-info tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'shield', 'size' => 18]) ?></span>
        <span>
            <strong>No payment credential is stored anywhere in this system</strong> &mdash; not a
            card number, not a PIN, not a mobile-money token. What is stored is the provider's own
            reference, and a unique constraint on it is what makes a replayed webhook credit an order
            exactly once.
        </span>
    </div>

    <section class="tw-mb-10">
        <h2 class="t-heading-xl tw-mb-4">Transactions</h2>
        <?= component('data-table', [
            'caption' => 'Payment transactions',
            'columns' => [
                ['key' => 'ref',        'label' => 'Transaction', 'type' => 'code', 'sub' => 'order_ref'],
                ['key' => 'customer',   'label' => 'Customer'],
                ['key' => 'gateway',    'label' => 'Gateway'],
                ['key' => 'gateway_reference', 'label' => 'Provider reference', 'type' => 'code'],
                ['key' => 'status',     'label' => 'Status', 'type' => 'badge'],
                ['key' => 'at_utc',     'label' => 'When',   'type' => 'relative'],
                ['key' => 'amount',     'label' => 'Amount', 'type' => 'money', 'align' => 'right'],
            ],
            'rows'  => $payments,
            'empty' => ['icon' => 'shield', 'title' => 'No transactions', 'text' => 'Payments appear here as orders are placed.'],
        ]) ?>
    </section>

    <section>
        <h2 class="t-heading-xl tw-mb-4">Refunds</h2>
        <?= component('data-table', [
            'caption' => 'Refund records',
            'columns' => [
                ['key' => 'ref',          'label' => 'Refund',   'type' => 'code', 'sub' => 'order_ref'],
                ['key' => 'customer',     'label' => 'Customer'],
                ['key' => 'reason',       'label' => 'Reason',   'type' => 'muted'],
                ['key' => 'requested_by', 'label' => 'Requested by'],
                ['key' => 'approved_by',  'label' => 'Approved by'],
                ['key' => 'status',       'label' => 'Status',   'type' => 'badge'],
                ['key' => 'amount',       'label' => 'Amount',   'type' => 'money', 'align' => 'right'],
                ['type' => 'actions',     'label' => '', 'actions' => [
                    ['label' => 'Approve', 'feature' => 'refund_approve', 'style' => 'sl-btn-aloe'],
                ]],
            ],
            'rows'  => array_map(static fn (array $r): array => array_merge($r, [
                'approved_by' => $r['approved_by'] ?? 'Not yet',
            ]), $refunds),
            'empty' => ['icon' => 'check-circle', 'title' => 'No refunds', 'text' => 'Refund records appear when an order is cancelled, rejected or returned.'],
        ]) ?>
    </section>

    <div class="tw-mt-6">
        <?= component('devnote', [
            'text' => 'Every transaction here is against the development sandbox gateway. No money has moved '
                    . 'and no live provider is connected. Phase 3.8 builds the gateway abstraction, '
                    . 'signature verification and the idempotency guard for real.',
        ]) ?>
    </div>
</div>
