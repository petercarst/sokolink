<?php
declare(strict_types=1);
/**
 * Order history.
 *
 * @var list<array<string,mixed>> $orders
 */
$rows = array_map(static fn (array $o): array => [
    'ref'    => $o['parent_ref'],
    'seller' => $o['seller_name'],
    'how'    => $o['fulfilment'] === 'pickup' ? 'Collected' : 'Delivered',
    'status' => $o['status'],
    'when'   => $o['updated_at_utc'],
    'total'  => $o['total'],
    'url'    => route('customer.orders.show', ['ref' => $o['ref']]),
], $orders);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Order history',
        'subtitle' => 'Everything you have completed, cancelled or had refunded.',
        'actions'  => [['label' => 'Reorder something', 'url' => route('customer.reorder'), 'style' => 'sl-btn-primary', 'icon' => 'repeat']],
    ]) ?>

    <?= component('data-table', [
        'caption' => 'Completed and closed orders',
        'columns' => [
            ['key' => 'ref',    'label' => 'Order',   'type' => 'code', 'href' => 'url', 'sub' => 'seller'],
            ['key' => 'how',    'label' => 'How'],
            ['key' => 'status', 'label' => 'Outcome', 'type' => 'badge'],
            ['key' => 'when',   'label' => 'Closed',  'type' => 'date'],
            ['key' => 'total',  'label' => 'Total',   'type' => 'money', 'align' => 'right'],
            ['type' => 'actions', 'label' => '', 'actions' => [
                ['label' => 'Receipt',   'href' => static fn (array $r): string => $r['url']],
                ['label' => 'Buy again', 'href' => static fn (array $r): string => route('customer.reorder'), 'style' => 'sl-btn-aloe'],
            ]],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'clock', 'title' => 'No past orders yet',
            'text' => 'Once an order is collected or delivered it moves here, with its receipt.',
            'actionUrl' => route('catalog.index'), 'actionLabel' => 'Start shopping',
        ],
    ]) ?>
</div>
