<?php
declare(strict_types=1);
/**
 * Admin order detail.
 *
 * Admins can override a state transition, but an override is flagged as such in
 * the history with a mandatory reason and an audit entry
 * (USER_ROLES_AND_PERMISSIONS.md section 5). An override that looked identical
 * to a normal transition would make the history useless for working out what
 * really happened.
 *
 * @var array<string,mixed> $order
 */
$isPickup = $order['fulfilment'] === 'pickup';
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'Order monitor', 'url' => route('admin.orders')],
            ['label' => $order['ref'], 'url' => null],
        ],
        'title'    => 'Order ' . $order['ref'],
        'subtitle' => $order['seller_name'] . ' - ' . $order['customer_name'],
        'badge'    => $order['status'],
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">
        <div>
            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-5">Status history</h2>
                <ol class="sl-timeline">
                    <?php foreach (array_reverse($order['history']) as $i => $entry) : ?>
                        <li class="sl-timeline-item <?= $i === 0 ? 'is-current' : 'is-done' ?>">
                            <p class="tw-mb-1"><?= component('badge', ['status' => $entry['status']]) ?></p>
                            <p class="t-caption t-muted tw-mb-1">
                                <?= time_tag($entry['at_utc']) ?> &middot; <?= e($entry['actor']) ?>
                                <span class="sl-chip tw-ml-1"><?= e($entry['actor_type']) ?></span>
                            </p>
                            <?php if (!empty($entry['reason'])) : ?>
                                <p class="t-caption tw-mb-0"><?= e($entry['reason']) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <section class="sl-card sl-card-flush tw-mb-6">
                <h2 class="t-heading-xl tw-p-6 tw-pb-0 tw-mb-4">Items</h2>
                <div class="sl-table-scroll">
                <table class="sl-table sl-table-reflow">
                    <caption class="visually-hidden">Items in this order part</caption>
                    <thead>
                        <tr>
                            <th scope="col">Item</th><th scope="col">SKU</th>
                            <th scope="col" class="sl-num">Qty</th>
                            <th scope="col" class="sl-num">Unit</th>
                            <th scope="col" class="sl-num">Line</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order['items'] as $item) : ?>
                            <tr>
                                <td data-label="Item"><?= e($item['name']) ?></td>
                                <td data-label="SKU"><span class="t-code"><?= e($item['sku']) ?></span></td>
                                <td data-label="Qty" class="sl-num tabular"><?= e((string) $item['qty']) ?></td>
                                <td data-label="Unit" class="sl-num tabular"><?= e(money($item['unit_price'])) ?></td>
                                <td data-label="Line" class="sl-num tabular"><?= e(money($item['line_total'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </section>

            <section class="sl-card">
                <h2 class="t-heading-xl tw-mb-2">Override the status</h2>
                <p class="t-caption t-muted tw-mb-5">
                    Use only when the normal flow cannot resolve the situation. The history will show
                    this as an administrator override rather than a normal transition, with your
                    reason attached, so nobody reading it later is misled.
                </p>

                <form method="post" action="<?= e(route('preview.submit')) ?>" data-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="order_override">

                    <?= component('field', [
                        'name' => 'new_status', 'label' => 'Move to', 'type' => 'select', 'required' => true,
                        'options' => [
                            ''                  => 'Choose a status',
                            'confirmed'         => 'Confirmed',
                            'preparing'         => 'Being prepared',
                            'ready_for_pickup'  => 'Ready to collect',
                            'collected'         => 'Collected',
                            'delivered'         => 'Delivered',
                            'completed'         => 'Completed',
                            'cancelled_customer'=> 'Cancelled',
                            'refund_pending'    => 'Refund pending',
                        ],
                    ]) ?>

                    <?= component('field', [
                        'name' => 'override_reason', 'label' => 'Why is an override needed?',
                        'type' => 'textarea', 'required' => true,
                        'help' => 'Required. Recorded in the history and the audit log.',
                    ]) ?>

                    <button class="sl-btn sl-btn-danger" type="submit">Apply override</button>
                </form>
            </section>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Order</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Parent', 'value' => $order['parent_ref'], 'type' => 'code'],
                    ['label' => 'This part', 'value' => $order['ref'], 'type' => 'code'],
                    ['label' => 'Seller', 'value' => $order['seller_name']],
                    ['label' => 'Store', 'value' => $order['store_name']],
                    ['label' => 'Customer', 'value' => $order['customer_name']],
                    ['label' => 'Method', 'value' => $isPickup ? 'Click and collect' : 'Home delivery'],
                    ['label' => 'Placed', 'value' => $order['placed_at_utc'], 'type' => 'datetime'],
                    ['label' => 'Updated', 'value' => $order['updated_at_utc'], 'type' => 'datetime'],
                ]]) ?>
            </div>

            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Money</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Items', 'value' => $order['subtotal'], 'type' => 'money'],
                    ['label' => 'Delivery', 'value' => $order['delivery_fee'], 'type' => 'money'],
                    ['label' => 'Total', 'value' => $order['total'], 'type' => 'money'],
                    ['label' => 'Commission', 'value' => $order['commission'], 'type' => 'money'],
                    ['label' => 'Method', 'value' => $order['payment_method'] === 'cash' ? 'Cash on fulfilment' : 'Sandbox gateway'],
                    ['label' => 'Payment', 'value' => $order['payment_status'], 'type' => 'badge'],
                ]]) ?>
                <a class="sl-btn sl-btn-outline-light sl-btn-sm sl-btn-block tw-mt-4"
                   href="<?= e(route('admin.payments')) ?>">Payment monitor</a>
            </div>

            <?php if (!$isPickup && !empty($order['delivery'])) : ?>
                <div class="sl-card">
                    <h2 class="t-heading-md tw-mb-4">Delivery</h2>
                    <?= component('detail-list', ['items' => [
                        ['label' => 'Task', 'value' => $order['delivery']['task_ref'] ?? 'Not created', 'type' => 'code'],
                        ['label' => 'Zone', 'value' => $order['delivery']['zone']],
                        ['label' => 'Agent', 'value' => $order['delivery']['agent_name'] ?? 'Not assigned'],
                        ['label' => 'Attempts', 'value' => (string) $order['delivery']['attempts']],
                    ]]) ?>
                    <a class="sl-btn sl-btn-outline-light sl-btn-sm sl-btn-block tw-mt-4"
                       href="<?= e(route('admin.deliveries')) ?>">Delivery monitor</a>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
