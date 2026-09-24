<?php
declare(strict_types=1);
/**
 * Cross-marketplace order monitor.
 *
 * The only view in the system that spans sellers. A seller sees their own
 * sub-orders; this sees all of them, which is exactly why it is admin-only and
 * why opening one is audited.
 *
 * @var list<array<string,mixed>> $orders
 * @var string $status
 */
$statuses = [
    ''                 => 'All statuses',
    'awaiting_seller'  => 'Awaiting seller',
    'preparing'        => 'Being prepared',
    'ready_for_pickup' => 'Ready to collect',
    'out_for_delivery' => 'Out for delivery',
    'delivery_failed'  => 'Delivery failed',
    'completed'        => 'Completed',
    'rejected_seller'  => 'Rejected by seller',
];

$rows = array_map(static fn (array $o): array => [
    'ref'      => $o['ref'],
    'parent'   => $o['parent_ref'],
    'seller'   => $o['seller_name'],
    'customer' => $o['customer_name'],
    'how'      => $o['fulfilment'] === 'pickup' ? 'Collect' : 'Delivery',
    'status'   => $o['status'],
    'payment'  => $o['payment_status'],
    'placed'   => $o['placed_at_utc'],
    'total'    => $o['total'],
    'url'      => route('admin.orders.show', ['ref' => $o['ref']]),
], $orders);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Order monitor',
        'subtitle' => 'Every seller sub-order across the marketplace.',
    ]) ?>

    <form class="sl-toolbar" method="get" action="<?= e(route('admin.orders')) ?>">
        <div class="sl-field">
            <label class="sl-label" for="status-filter">Status</label>
            <select class="sl-select" id="status-filter" name="status" data-auto-submit>
                <?php foreach ($statuses as $value => $label) : ?>
                    <option value="<?= e((string) $value) ?>"<?= $status === (string) $value ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <noscript><button class="sl-btn sl-btn-outline-light" type="submit">Filter</button></noscript>
        <?php if ($status !== '') : ?>
            <a class="sl-btn sl-btn-ghost" href="<?= e(route('admin.orders')) ?>">Clear</a>
        <?php endif; ?>
        <p class="t-caption t-muted tw-mb-0 tw-ml-auto" role="status">
            <?= e((string) count($orders)) ?> sub-order<?= count($orders) === 1 ? '' : 's' ?>
        </p>
    </form>

    <?= component('data-table', [
        'caption' => 'Marketplace orders',
        'columns' => [
            ['key' => 'ref',      'label' => 'Part',     'type' => 'code', 'href' => 'url', 'sub' => 'parent'],
            ['key' => 'seller',   'label' => 'Seller'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'how',      'label' => 'How'],
            ['key' => 'status',   'label' => 'Status',   'type' => 'badge'],
            ['key' => 'payment',  'label' => 'Payment',  'type' => 'badge'],
            ['key' => 'placed',   'label' => 'Placed',   'type' => 'relative'],
            ['key' => 'total',    'label' => 'Total',    'type' => 'money', 'align' => 'right'],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'package', 'title' => 'No orders at that status',
            'text' => 'Try another status or clear the filter.',
            'actionUrl' => route('admin.orders'), 'actionLabel' => 'Show all orders',
        ],
    ]) ?>

    <div class="sl-card sl-card-band tw-mt-8">
        <h2 class="t-heading-md tw-mb-3">Why one order appears twice</h2>
        <p class="t-caption tw-mb-0">
            A basket spanning two sellers becomes two sub-orders under one parent reference. They are
            accepted, prepared and completed independently, so one can be delivered while the other
            is rejected. The parent reference is what the customer sees; the part is what gets
            fulfilled.
        </p>
    </div>
</div>
