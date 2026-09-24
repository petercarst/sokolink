<?php
declare(strict_types=1);
/**
 * Customer order detail and tracking.
 *
 * @var array<string,mixed>       $order
 * @var list<array<string,mixed>> $siblings  other sellers on the same parent order
 */
$isPickup = $order['fulfilment'] === 'pickup';
$isOpen   = !in_array($order['status'], ['completed', 'collected', 'delivered', 'refunded', 'rejected_seller', 'cancelled_customer'], true);

// The lifecycle the customer is walked through, per fulfilment method.
$steps = $isPickup
    ? ['awaiting_seller' => 'Seller confirms', 'confirmed' => 'Accepted', 'preparing' => 'Being packed',
       'ready_for_pickup' => 'Ready to collect', 'collected' => 'Collected', 'completed' => 'Completed']
    : ['awaiting_seller' => 'Seller confirms', 'confirmed' => 'Accepted', 'preparing' => 'Being packed',
       'ready_for_dispatch' => 'Ready to dispatch', 'out_for_delivery' => 'Out for delivery',
       'delivered' => 'Delivered', 'completed' => 'Completed'];

$reached = array_column($order['history'], 'status');
$keys    = array_keys($steps);
$currentIndex = -1;
foreach ($keys as $i => $key) {
    if (in_array($key, $reached, true)) {
        $currentIndex = $i;
    }
}
?>
<div class="sl-container-wide sl-container">

    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'Active orders', 'url' => route('customer.orders')],
            ['label' => $order['parent_ref'], 'url' => null],
        ],
        'title'    => 'Order ' . $order['parent_ref'],
        'subtitle' => $order['seller_name'] . ' - ' . ($isPickup ? 'collect from ' . $order['store_name'] : 'home delivery'),
        'badge'    => $order['status'],
        'actions'  => $isOpen && in_array($order['status'], ['awaiting_seller', 'confirmed'], true)
            ? [['label' => 'Cancel this part', 'feature' => 'order_cancel', 'style' => 'sl-btn-danger', 'icon' => 'close']]
            : [['label' => 'Get help with this order', 'url' => route('customer.tickets'), 'icon' => 'info']],
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 380px;">
        <div>
            <!-- Collection code: the one thing the customer needs in the shop -->
            <?php if (!empty($order['pickup']['code_issued']) && empty($order['pickup']['collected_at_utc'])) : ?>
                <section class="sl-card sl-card-raised tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-2">Your collection code</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        Sent to you by email <?= time_tag((string) $order['pickup']['code_issued_at'], true) ?>.
                        Show it at <?= e($order['store_name']) ?>.
                    </p>

                    <div class="sl-alert sl-alert-info tw-mb-4">
                        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'shield', 'size' => 18]) ?></span>
                        <span>
                            <strong>We cannot show the code here.</strong>
                            Only its hash is stored, so this page has no way to read it back - and neither
                            does anyone who gets into our database. Check your email, or
                            <a href="<?= e(route('customer.tickets')) ?>">ask support to reissue it</a>.
                        </span>
                    </div>

                    <?php if (!empty($order['pickup']['window_to'])) : ?>
                        <p class="t-caption t-muted tw-mb-3">
                            <?= component('icon', ['name' => 'clock', 'size' => 15]) ?>
                            Collect between <?= e(local_datetime((string) $order['pickup']['window_from'])) ?>
                            and <?= e(local_datetime((string) $order['pickup']['window_to'])) ?>.
                        </p>
                    <?php endif; ?>

                    <?php if ($order['pickup']['instructions'] !== '') : ?>
                        <p class="t-caption t-muted tw-mb-0"><?= e((string) $order['pickup']['instructions']) ?></p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if (!empty($order['delivery']['code_issued']) && empty($order['delivery']['delivered_at_utc'])) : ?>
                <section class="sl-card sl-card-raised tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-2">Your delivery code</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        Give it to the agent when they arrive. They cannot mark the delivery
                        complete without it.
                    </p>

                    <div class="sl-alert sl-alert-info tw-mb-4">
                        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'shield', 'size' => 18]) ?></span>
                        <span>
                            <strong>We cannot show the code here.</strong>
                            Only its hash is stored. It was sent to you when the delivery was dispatched.
                        </span>
                    </div>

                    <?php if (!empty($order['delivery']['agent_name'])) : ?>
                        <p class="t-caption t-muted tw-mb-0">
                            <?= component('icon', ['name' => 'truck', 'size' => 15]) ?>
                            <?= e((string) $order['delivery']['agent_name']) ?> is delivering this order.
                        </p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($order['status'] === 'rejected_seller') : ?>
                <div class="sl-alert sl-alert-danger tw-mb-6">
                    <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                    <span>
                        <strong>The seller could not fulfil this part.</strong>
                        <?php
                        $reason = null;
                        foreach ($order['history'] as $entry) {
                            if ($entry['status'] === 'rejected_seller') {
                                $reason = $entry['reason'];
                            }
                        }
                        ?>
                        <?= e($reason ?? 'No reason recorded.') ?>
                        It has been refunded, and other parts of your order are unaffected.
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($order['status'] === 'delivery_failed') : ?>
                <div class="sl-alert sl-alert-warn tw-mb-6">
                    <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                    <span>
                        <strong>A delivery attempt did not succeed.</strong>
                        <?= e($order['delivery']['failures'][0]['note'] ?? '') ?>
                        Another attempt will be made. You have not been charged again.
                    </span>
                </div>
            <?php endif; ?>

            <!-- Progress -->
            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-5">Progress</h2>
                <ol class="sl-timeline">
                    <?php foreach ($keys as $i => $key) : ?>
                        <li class="sl-timeline-item <?= $i < $currentIndex ? 'is-done' : ($i === $currentIndex ? 'is-current' : '') ?>">
                            <p class="t-body-strong tw-mb-1"><?= e($steps[$key]) ?></p>
                            <?php
                            $at = null;
                            foreach ($order['history'] as $entry) {
                                if ($entry['status'] === $key) {
                                    $at = $entry['at_utc'];
                                }
                            }
                            ?>
                            <p class="t-caption t-muted tw-mb-0">
                                <?= $at !== null ? time_tag($at) : 'Not reached yet' ?>
                            </p>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <!-- Items -->
            <section class="sl-card sl-card-flush tw-mb-6">
                <h2 class="t-heading-xl tw-p-6 tw-pb-0 tw-mb-4">What is in this part</h2>
                <ul class="tw-list-none tw-p-0 tw-m-0">
                    <?php foreach ($order['items'] as $item) : ?>
                        <li class="tw-flex tw-gap-4 tw-px-6 tw-py-4" style="border-top:1px solid var(--c-hairline-light)">
                            <span class="sl-photo-frame tw-shrink-0" style="width:56px;height:56px;border-radius:var(--r-md)">
                                <?= component('product-image', ['tone' => $item['tone'], 'label' => $item['name'], 'mark' => false]) ?>
                            </span>
                            <span class="tw-flex-1" style="min-width:0">
                                <span class="t-body-strong tw-block"><?= e($item['name']) ?></span>
                                <span class="t-micro t-muted">
                                    <?= e($item['pack_size']) ?> &middot; <span class="t-code"><?= e($item['sku']) ?></span>
                                </span>
                            </span>
                            <span class="tw-text-right">
                                <span class="t-body-strong tabular tw-block"><?= e(money($item['line_total'])) ?></span>
                                <span class="t-micro t-muted"><?= e((string) $item['qty']) ?> &times; <?= e(money($item['unit_price'])) ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <?php if ($siblings !== []) : ?>
                <section class="sl-card">
                    <h2 class="t-heading-md tw-mb-2">Other parts of this order</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        Order <?= e($order['parent_ref']) ?> spans more than one seller. Each part is
                        prepared and tracked on its own.
                    </p>
                    <?php foreach ($siblings as $sib) : ?>
                        <a class="sl-option tw-mb-2 tw-no-underline" href="<?= e(route('customer.orders.show', ['ref' => $sib['ref']])) ?>">
                            <span class="tw-flex-1">
                                <span class="t-body-strong tw-block"><?= e($sib['seller_name']) ?></span>
                                <span class="t-micro t-muted">
                                    <?= e($sib['fulfilment'] === 'pickup' ? 'Collect from ' . $sib['store_name'] : 'Home delivery') ?>
                                </span>
                            </span>
                            <?= component('badge', ['status' => $sib['status']]) ?>
                        </a>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Summary</h2>
                <?= component('detail-list', ['items' => array_filter([
                    ['label' => 'Order', 'value' => $order['parent_ref'], 'type' => 'code'],
                    ['label' => 'Part', 'value' => $order['ref'], 'type' => 'code'],
                    ['label' => 'Seller', 'value' => $order['seller_name']],
                    ['label' => 'Method', 'value' => $isPickup ? 'Click and collect' : 'Home delivery'],
                    $isPickup ? ['label' => 'Store', 'value' => $order['store_name']] : null,
                    ['label' => 'Placed', 'value' => $order['placed_at_utc'], 'type' => 'datetime'],
                    ['label' => 'Items', 'value' => $order['subtotal'], 'type' => 'money'],
                    ['label' => 'Delivery', 'value' => $order['delivery_fee'], 'type' => 'money'],
                    ['label' => 'Total', 'value' => $order['total'], 'type' => 'money'],
                    ['label' => 'Payment', 'value' => $order['payment_status'], 'type' => 'badge'],
                ])]) ?>
            </div>

            <?php if (!$isPickup && !empty($order['delivery'])) : ?>
                <div class="sl-card tw-mb-6">
                    <h2 class="t-heading-md tw-mb-4">Delivering to</h2>
                    <address class="t-body-md tw-not-italic tw-mb-3">
                        <?= e($order['delivery']['recipient']) ?><br>
                        <?= e($order['delivery']['address']) ?>
                    </address>
                    <?php if (!empty($order['delivery']['landmark'])) : ?>
                        <p class="t-caption t-muted tw-mb-2"><?= e($order['delivery']['landmark']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($order['delivery']['instructions'])) : ?>
                        <p class="t-caption t-muted tw-mb-0">
                            <strong>Instructions:</strong> <?= e($order['delivery']['instructions']) ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="sl-card">
                <h2 class="t-heading-md tw-mb-3">Need help?</h2>
                <p class="t-caption t-muted tw-mb-4">
                    Support can see this order's full history when you quote the reference.
                </p>
                <a class="sl-btn sl-btn-outline-light sl-btn-block" href="<?= e(route('customer.tickets')) ?>">
                    Open a support request
                </a>
            </div>
        </aside>
    </div>
</div>
