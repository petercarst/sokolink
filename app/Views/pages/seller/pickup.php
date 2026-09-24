<?php
declare(strict_types=1);
/**
 * Orders packed and waiting for the customer.
 *
 * NOTE WHAT IS NOT HERE: the collection code. It is stored hashed, so nobody at
 * the seller or the platform can read it back - staff verify a code the
 * customer presents (FR-PICK-02). Showing it here would make the whole
 * mechanism pointless.
 *
 * @var list<array<string,mixed>> $orders
 */
$rows = array_map(static fn (array $o): array => [
    'ref'      => $o['ref'],
    'customer' => $o['customer_name'],
    'store'    => $o['store_name'],
    'items'    => $o['line_count'] . ' item' . ((int) $o['line_count'] === 1 ? '' : 's'),
    'ready'    => $o['updated_at_utc'],
    'until'    => $o['collect_by_utc'],
    'status'   => $o['status'],
    'url'      => route('seller.orders.show', ['ref' => $o['ref']]),
], $orders);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Ready to collect',
        'subtitle' => 'Packed, coded and waiting at the counter.',
        'actions'  => [['label' => 'Verify a collection', 'url' => route('seller.pickup.verify'), 'style' => 'sl-btn-aloe', 'icon' => 'qr']],
    ]) ?>

    <div class="sl-alert sl-alert-info tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'lock', 'size' => 18]) ?></span>
        <span>
            Collection codes are stored as a hash, so they are not listed here and cannot be looked
            up by anyone &mdash; including us. The customer shows you their code and you verify it.
            That is what makes a collection record trustworthy.
        </span>
    </div>

    <?= component('data-table', [
        'caption' => 'Orders waiting to be collected',
        'columns' => [
            ['key' => 'ref',      'label' => 'Order',    'type' => 'code', 'href' => 'url', 'sub' => 'items'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'store',    'label' => 'Store'],
            ['key' => 'ready',    'label' => 'Ready since', 'type' => 'relative'],
            ['key' => 'until',    'label' => 'Collect by',  'type' => 'datetime'],
            ['key' => 'status',   'label' => 'Status',   'type' => 'badge'],
            ['type' => 'actions', 'label' => '', 'actions' => [
                ['label' => 'Verify', 'style' => 'sl-btn-aloe',
                 'href' => static fn (array $r): string => route('seller.pickup.verify') . '?ref=' . rawurlencode((string) $r['ref'])],
            ]],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'package', 'title' => 'Nothing waiting for collection',
            'text' => 'Orders appear here once you mark them ready.',
            'actionUrl' => route('seller.orders'), 'actionLabel' => 'See orders needing action',
        ],
    ]) ?>
</div>
