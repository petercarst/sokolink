<?php
declare(strict_types=1);
/**
 * Seller incoming orders, filtered by stage.
 *
 * @var string $filter
 * @var list<array<string,mixed>> $orders
 * @var array<string,int> $counts
 */
$tabs = [
    'incoming' => 'Needs action',
    'pickup'   => 'Awaiting collection',
    'dispatch' => 'Out for delivery',
    'done'     => 'Completed',
    'problem'  => 'Problems',
];

$rows = array_map(static fn (array $o): array => [
    'ref'      => $o['ref'],
    'customer' => $o['customer_name'],
    'how'      => $o['fulfilment'] === 'pickup' ? 'Collect - ' . $o['store_name'] : 'Delivery',
    'items'    => $o['line_count'] . ' item' . ((int) $o['line_count'] === 1 ? '' : 's'),
    'status'   => $o['status'],
    'placed'   => $o['placed_at_utc'],
    'total'    => $o['total'],
    'url'      => route('seller.orders.show', ['ref' => $o['ref']]),
], $orders);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Orders',
        'subtitle' => 'Only your store\'s part of each order. You never see another seller\'s lines, or the customer\'s email address.',
    ]) ?>

    <?= component('tabs', [
        'label' => 'Order stage',
        'tabs'  => array_map(
            static fn (string $key, string $label): array => [
                'label'   => $label,
                'url'     => route('seller.orders') . '?status=' . $key,
                'current' => $filter === $key,
                'count'   => $counts[$key] ?? 0,
            ],
            array_keys($tabs),
            array_values($tabs)
        ),
    ]) ?>

    <?= component('data-table', [
        'caption' => 'Orders at stage: ' . ($tabs[$filter] ?? $filter),
        'columns' => [
            ['key' => 'ref',      'label' => 'Order',    'type' => 'code', 'href' => 'url', 'sub' => 'items'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'how',      'label' => 'How'],
            ['key' => 'status',   'label' => 'Status',   'type' => 'badge'],
            ['key' => 'placed',   'label' => 'Placed',   'type' => 'relative'],
            ['key' => 'total',    'label' => 'Value',    'type' => 'money', 'align' => 'right'],
            ['type' => 'actions', 'label' => '', 'actions' => [
                ['label' => 'Open', 'href' => static fn (array $r): string => $r['url']],
            ]],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'check-circle', 'title' => 'Nothing at this stage',
            'text' => 'Orders move through the stages as you accept, prepare and complete them.',
            'actionUrl' => route('seller.orders') . '?status=incoming',
            'actionLabel' => 'Back to orders needing action',
        ],
    ]) ?>

    <div class="tw-mt-6">
        <?= component('devnote', [
            'text' => 'Only Mama Lishe Provisions orders are listed. In Phase 3 that filter moves into the '
                    . 'repository as WHERE seller_id = :actor, which is what turns it from a view convenience '
                    . 'into an access control.',
        ]) ?>
    </div>
</div>
