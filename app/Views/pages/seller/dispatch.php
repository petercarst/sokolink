<?php
declare(strict_types=1);
/**
 * Orders packed for delivery.
 *
 * A seller hands the parcel to a platform agent and stops being responsible for
 * it. Agent assignment is an admin action in v1 (OQ-03), so this screen shows
 * who has it rather than offering to choose.
 *
 * @var list<array<string,mixed>> $orders
 */
$rows = array_map(static fn (array $o): array => [
    'ref'      => $o['ref'],
    'customer' => $o['customer_name'],
    'zone'     => $o['delivery']['zone'] ?? '-',
    'agent'    => $o['delivery']['agent_name'] ?? 'Not assigned yet',
    'status'   => $o['status'],
    'updated'  => $o['updated_at_utc'],
    'total'    => $o['total'],
    'url'      => route('seller.orders.show', ['ref' => $o['ref']]),
], $orders);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Ready to dispatch',
        'subtitle' => 'Packed for delivery. A platform agent collects these from your store.',
    ]) ?>

    <?= component('data-table', [
        'caption' => 'Orders awaiting or in delivery',
        'columns' => [
            ['key' => 'ref',      'label' => 'Order',    'type' => 'code', 'href' => 'url'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'zone',     'label' => 'Zone'],
            ['key' => 'agent',    'label' => 'Agent'],
            ['key' => 'status',   'label' => 'Status',   'type' => 'badge'],
            ['key' => 'updated',  'label' => 'Updated',  'type' => 'relative'],
            ['key' => 'total',    'label' => 'Value',    'type' => 'money', 'align' => 'right'],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'truck', 'title' => 'Nothing waiting for dispatch',
            'text' => 'Delivery orders appear here once you mark them packed.',
            'actionUrl' => route('seller.orders'), 'actionLabel' => 'See orders needing action',
        ],
    ]) ?>

    <div class="sl-card sl-card-band tw-mt-8">
        <h2 class="t-heading-md tw-mb-3">Handing a parcel over</h2>
        <ul class="tw-m-0 tw-pl-5 tw-flex tw-flex-col tw-gap-2">
            <li class="t-caption">Check the agent's name matches the one shown against the order.</li>
            <li class="t-caption">
                For cash-on-delivery orders, the amount the agent must collect is on the order page.
                You are not handling that money.
            </li>
            <li class="t-caption">
                Once the agent marks the parcel picked up, the delivery is theirs. Failed attempts
                and their reasons appear on the order.
            </li>
        </ul>
    </div>
</div>
