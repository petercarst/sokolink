<?php
declare(strict_types=1);
/**
 * Customer active orders.
 *
 * Listed per SELLER SUB-ORDER, because that is the thing with a status. Two
 * rows can share a parent reference and that is correct, not a duplicate.
 *
 * @var list<array<string,mixed>> $orders
 */
$rows = array_map(static fn (array $o): array => [
    'ref'        => $o['parent_ref'],
    'part'       => $o['seller_name'],
    'fulfilment' => $o['fulfilment'] === 'pickup' ? 'Collect - ' . $o['store_name'] : 'Delivery',
    'status'     => $o['status'],
    'total'      => $o['total'],
    'placed'     => $o['placed_at_utc'],
    'url'        => route('customer.orders.show', ['ref' => $o['ref']]),
], $orders);
?>
<div class="sl-container-wide sl-container">

    <?= partial('dash-page-header', [
        'title'    => 'Active orders',
        'subtitle' => 'Each seller in an order is prepared and tracked separately, so one order can appear here more than once.',
        'actions'  => [['label' => 'Order history', 'url' => route('customer.history'), 'icon' => 'clock']],
    ]) ?>

    <?= component('data-table', [
        'caption' => 'Your active orders',
        'columns' => [
            ['key' => 'ref',        'label' => 'Order',     'type' => 'code', 'href' => 'url', 'sub' => 'part'],
            ['key' => 'fulfilment', 'label' => 'How'],
            ['key' => 'status',     'label' => 'Status',    'type' => 'badge'],
            ['key' => 'placed',     'label' => 'Placed',    'type' => 'relative'],
            ['key' => 'total',      'label' => 'Total',     'type' => 'money', 'align' => 'right'],
            ['type' => 'actions',   'label' => '', 'actions' => [
                ['label' => 'Track', 'href' => static fn (array $r): string => $r['url']],
            ]],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'package', 'title' => 'No active orders',
            'text' => 'Anything you order will appear here until it is collected or delivered.',
            'actionUrl' => route('catalog.index'), 'actionLabel' => 'Start shopping',
        ],
    ]) ?>
</div>
