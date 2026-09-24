<?php
declare(strict_types=1);
/**
 * Seller order detail and preparation.
 *
 * The actions offered depend on the CURRENT STATUS, and only valid transitions
 * are shown. In Phase 3 the same rules are enforced again in
 * OrderStateMachine before any write, because a button the browser can see is a
 * request the browser can forge (FR-ORD-03).
 *
 * @var array<string,mixed> $order
 */
$isPickup = $order['fulfilment'] === 'pickup';

$ref = ['ref' => $order['ref']];

$actions = match ($order['status']) {
    'awaiting_seller' => [
        ['label' => 'Accept this order', 'post' => route('seller.orders.accept'), 'fields' => $ref,
         'style' => 'sl-btn-aloe', 'icon' => 'check'],
    ],
    'confirmed' => [
        ['label' => 'Start preparing', 'post' => route('seller.orders.prepare'), 'fields' => $ref,
         'style' => 'sl-btn-primary', 'icon' => 'package'],
    ],
    'preparing' => [[
        'label'  => $isPickup ? 'Mark ready to collect' : 'Mark ready to dispatch',
        'post'   => route('seller.orders.ready'), 'fields' => $ref,
        'style'  => 'sl-btn-aloe', 'icon' => 'check',
    ]],
    'ready_for_pickup' => [
        ['label' => 'Verify a collection code', 'url' => route('seller.pickup.verify'), 'style' => 'sl-btn-aloe', 'icon' => 'qr'],
    ],
    default => [],
};

// Rejecting needs a reason, so it cannot be a header button - it lives in its
// own form further down the page where there is room to type one.
$canReject = in_array($order['status'], ['awaiting_seller', 'confirmed'], true);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'Orders', 'url' => route('seller.orders')],
            ['label' => $order['ref'], 'url' => null],
        ],
        'title'    => 'Order ' . $order['ref'],
        'subtitle' => $order['customer_name'] . ' - ' . ($isPickup ? 'collect from ' . $order['store_name'] : 'home delivery'),
        'badge'    => $order['status'],
        'actions'  => $actions,
    ]) ?>

    <?php if ($order['status'] === 'awaiting_seller') : ?>
        <div class="sl-alert sl-alert-warn tw-mb-6">
            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'clock', 'size' => 18]) ?></span>
            <span>
                <strong>Waiting on you.</strong>
                The customer has paid and the stock is reserved. Accepting confirms you can fulfil
                it; rejecting releases the stock and refunds this part automatically. A reason is
                required either way if you reject.
            </span>
        </div>
    <?php endif; ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">
        <div>
            <!-- Pick list: the thing the person in the shop actually uses -->
            <section class="sl-card sl-card-flush tw-mb-6">
                <div class="tw-flex tw-items-center tw-justify-between tw-gap-3 tw-p-6 tw-pb-4">
                    <h2 class="t-heading-xl tw-mb-0">Pick list</h2>
                    <span class="sl-chip"><?= e((string) count($order['items'])) ?> line<?= count($order['items']) === 1 ? '' : 's' ?></span>
                </div>

                <ul class="tw-list-none tw-p-0 tw-m-0">
                    <?php foreach ($order['items'] as $item) : ?>
                        <li class="tw-flex tw-gap-4 tw-px-6 tw-py-4 tw-items-center"
                            style="border-top:1px solid var(--c-hairline-light)">
                            <span class="sl-code-display tw-shrink-0"
                                  style="font-size:22px;padding:8px 14px;letter-spacing:0;min-width:56px">
                                <?= e((string) $item['qty']) ?>
                            </span>
                            <span class="tw-flex-1" style="min-width:0">
                                <span class="t-body-strong tw-block"><?= e($item['name']) ?></span>
                                <span class="t-micro t-muted">
                                    <?= e($item['pack_size']) ?> &middot;
                                    SKU <span class="t-code"><?= e($item['sku']) ?></span>
                                </span>
                            </span>
                            <span class="t-body-strong tabular tw-text-right"><?= e(money($item['line_total'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <!-- Reject form: reason is mandatory -->
            <?php if ($canReject) : ?>
                <section class="sl-card tw-mb-6">
                    <h2 class="t-heading-md tw-mb-2">If you cannot fulfil this</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        The customer is told the reason you give. "Unsuccessful" with no explanation
                        just creates a support ticket, so a reason is required.
                    </p>

                    <form method="post" action="<?= e(route('seller.orders.reject')) ?>" data-validate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="ref" value="<?= e($order['ref']) ?>">

                        <?= component('field', [
                            'name' => 'reason_code', 'label' => 'Reason', 'type' => 'select', 'required' => true,
                            'options' => [
                                '' => 'Choose a reason',
                                'out_of_stock'    => 'Stock count was wrong - not physically in the store',
                                'quality'         => 'Stock is here but not in sellable condition',
                                'store_closed'    => 'Store cannot fulfil in the promised window',
                                'pricing_error'   => 'The listed price was wrong',
                                'other'           => 'Something else',
                            ],
                        ]) ?>

                        <?= component('field', [
                            'name' => 'reason', 'label' => 'Note to the customer', 'type' => 'textarea',
                            'required' => true, 'help' => 'Shown to the customer exactly as written.',
                        ]) ?>

                        <button class="sl-btn sl-btn-danger" type="submit">Reject this order</button>
                    </form>
                </section>
            <?php endif; ?>

            <section class="sl-card">
                <h2 class="t-heading-xl tw-mb-5">History</h2>
                <ol class="sl-timeline">
                    <?php foreach (array_reverse($order['history']) as $i => $entry) : ?>
                        <li class="sl-timeline-item <?= $i === 0 ? 'is-current' : 'is-done' ?>">
                            <p class="t-body-strong tw-mb-1">
                                <?= component('badge', ['status' => $entry['status']]) ?>
                            </p>
                            <p class="t-caption t-muted tw-mb-0">
                                <?= time_tag($entry['at_utc']) ?> &middot; <?= e($entry['actor']) ?>
                                <span class="sl-chip tw-ml-1"><?= e($entry['actor_type']) ?></span>
                            </p>
                            <?php if (!empty($entry['reason'])) : ?>
                                <p class="t-caption tw-mt-1 tw-mb-0"><?= e($entry['reason']) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Order</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Your part', 'value' => $order['ref'], 'type' => 'code'],
                    ['label' => 'Customer order', 'value' => $order['parent_ref'], 'type' => 'code'],
                    ['label' => 'Placed', 'value' => $order['placed_at_utc'], 'type' => 'datetime'],
                    ['label' => 'Method', 'value' => $isPickup ? 'Click and collect' : 'Home delivery'],
                    ['label' => 'Store', 'value' => $order['store_name']],
                    ['label' => 'Items', 'value' => $order['subtotal'], 'type' => 'money'],
                    ['label' => 'Delivery', 'value' => $order['delivery_fee'], 'type' => 'money'],
                    ['label' => 'Order value', 'value' => $order['total'], 'type' => 'money'],
                    ['label' => 'Platform commission', 'value' => $order['commission'], 'type' => 'money'],
                    ['label' => 'You receive', 'value' => $order['payout'], 'type' => 'money'],
                    ['label' => 'Payment', 'value' => $order['payment_status'], 'type' => 'badge'],
                ]]) ?>
            </div>

            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Customer</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Name', 'value' => $order['customer_name']],
                    ['label' => 'Phone', 'value' => $order['customer_phone_masked']],
                ]]) ?>
                <p class="t-micro t-muted tw-mt-3 tw-mb-0">
                    You see a first name and a masked number. Email addresses, other orders and
                    payment details are never shared with sellers.
                </p>
            </div>

            <?php if (!$isPickup && !empty($order['delivery'])) : ?>
                <div class="sl-card">
                    <h2 class="t-heading-md tw-mb-4">Delivery address</h2>
                    <address class="t-caption tw-not-italic tw-mb-3">
                        <?= e($order['delivery']['recipient']) ?><br>
                        <?= e($order['delivery']['address']) ?>
                    </address>
                    <?php if (!empty($order['delivery']['landmark'])) : ?>
                        <p class="t-micro t-muted tw-mb-2"><?= e($order['delivery']['landmark']) ?></p>
                    <?php endif; ?>
                    <p class="t-micro t-muted tw-mb-0">
                        Shown because you are packing this delivery. An agent sees it only once the
                        task is assigned to them.
                    </p>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
