<?php
declare(strict_types=1);
/**
 * Payment and transaction history.
 *
 * Shows what was charged and what happened to it. It does NOT show a payment
 * instrument, because none is stored - not a card number, not a mobile-money
 * PIN, in any phase (FR-PAY-07).
 *
 * @var list<array<string,mixed>> $payments
 */
$paid = array_filter($payments, static fn (array $p): bool => $p['status'] === 'paid');
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Payments',
        'subtitle' => 'Every transaction on your account and what happened to it.',
    ]) ?>

    <?= component('stat-row', ['cols' => '3', 'stats' => [
        ['label' => 'Transactions', 'value' => (string) count($payments), 'hint' => 'All time'],
        ['label' => 'Total paid', 'value' => money_compact(\App\Support\MockDashboard::sum($paid, 'amount')), 'hint' => 'Confirmed payments only'],
        ['label' => 'Refunded', 'value' => (string) count(array_filter($payments, static fn (array $p): bool => $p['status'] === 'refunded')), 'hint' => 'Returned to source'],
    ]]) ?>

    <?= component('data-table', [
        'caption' => 'Your transactions',
        'columns' => [
            ['key' => 'ref',    'label' => 'Transaction', 'type' => 'code', 'sub' => 'order_ref'],
            ['key' => 'method', 'label' => 'Method'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ['key' => 'at_utc', 'label' => 'When',   'type' => 'datetime'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'align' => 'right'],
        ],
        'rows'  => $payments,
        'empty' => ['icon' => 'shield', 'title' => 'No payments yet', 'text' => 'Transactions appear here as soon as you place an order.'],
    ]) ?>

    <div class="tw-mt-6">
        <?= component('devnote', [
            'text' => 'Sample transactions against the development sandbox gateway - no money has moved. '
                    . 'Phase 3.8 adds the gateway abstraction, signature-verified webhooks and idempotent processing.',
        ]) ?>
    </div>
</div>
