<?php
declare(strict_types=1);
/**
 * Support view of an order.
 *
 * Shows the full lifecycle so support can answer "what happened?" without
 * asking the customer to explain it. Note what is absent: any payment
 * credential, and the collection code in plain text. Codes are stored hashed -
 * support can trigger a regeneration, which is itself audited and tells the
 * customer, but cannot read the existing one.
 *
 * @var array<string,mixed> $order
 * @var string               $justification
 */
$isPickup = $order['fulfilment'] === 'pickup';
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'Order lookup', 'url' => route('support.orders')],
            ['label' => $order['ref'], 'url' => null],
        ],
        'title'    => 'Order ' . $order['parent_ref'],
        'subtitle' => $order['customer_name'] . ' - ' . $order['seller_name'],
        'badge'    => $order['status'],
        // No action buttons up here. Reissuing a code is a real form in the
        // sidebar, and a refund is an administrator's decision that support
        // asks for by escalating the ticket - not a button on an order page.
        'actions'  => [],
    ]) ?>

    <div class="sl-alert sl-alert-warn tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'lock', 'size' => 18]) ?></span>
        <span>
            <strong>This view is audited.</strong>
            Opening this order has been recorded against your account. You can request a refund, but
            you cannot approve one &mdash; that needs an administrator.
        </span>
    </div>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">
        <div>
            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-5">Full history</h2>
                <ol class="sl-timeline">
                    <?php foreach (array_reverse($order['history']) as $i => $entry) : ?>
                        <li class="sl-timeline-item <?= $i === 0 ? 'is-current' : 'is-done' ?>">
                            <p class="tw-mb-1"><?= component('badge', ['status' => $entry['status']]) ?></p>
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

            <?php if (!empty($order['delivery']['failures'])) : ?>
                <section class="sl-card tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-4">Failed delivery attempts</h2>
                    <?php foreach ($order['delivery']['failures'] as $failure) : ?>
                        <div class="sl-alert sl-alert-danger tw-mb-2">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                            <span>
                                <strong><?= e(ucwords(str_replace('_', ' ', $failure['reason_code']))) ?></strong>
                                &middot; <?= time_tag($failure['at_utc']) ?><br>
                                <span class="t-caption"><?= e($failure['note']) ?></span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <p class="t-micro t-muted tw-mb-0">
                        This is the agent's own account, recorded at the time. It is what the customer
                        sees too, so there is one version of events.
                    </p>
                </section>
            <?php endif; ?>

            <section class="sl-card sl-card-flush">
                <h2 class="t-heading-xl tw-p-6 tw-pb-0 tw-mb-4">Items</h2>
                <div class="sl-table-scroll">
                <table class="sl-table sl-table-reflow">
                    <caption class="visually-hidden">Items in this order part</caption>
                    <thead>
                        <tr>
                            <th scope="col">Item</th><th scope="col">SKU</th>
                            <th scope="col" class="sl-num">Qty</th><th scope="col" class="sl-num">Line</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order['items'] as $item) : ?>
                            <tr>
                                <td data-label="Item">
                                    <?= e($item['name']) ?>
                                    <span class="t-micro t-muted tw-block"><?= e($item['pack_size']) ?></span>
                                </td>
                                <td data-label="SKU"><span class="t-code"><?= e($item['sku']) ?></span></td>
                                <td data-label="Qty" class="sl-num tabular"><?= e((string) $item['qty']) ?></td>
                                <td data-label="Line" class="sl-num tabular"><?= e(money($item['line_total'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </section>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Order</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Customer order', 'value' => $order['parent_ref'], 'type' => 'code'],
                    ['label' => 'This part', 'value' => $order['ref'], 'type' => 'code'],
                    ['label' => 'Customer', 'value' => $order['customer_name']],
                    ['label' => 'Phone', 'value' => $order['customer_phone_masked']],
                    ['label' => 'Seller', 'value' => $order['seller_name']],
                    ['label' => 'Method', 'value' => $isPickup ? 'Click and collect' : 'Home delivery'],
                    ['label' => 'Store', 'value' => $order['store_name']],
                    ['label' => 'Placed', 'value' => $order['placed_at_utc'], 'type' => 'datetime'],
                ]]) ?>
            </div>

            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Money</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Items', 'value' => $order['subtotal'], 'type' => 'money'],
                    ['label' => 'Delivery', 'value' => $order['delivery_fee'], 'type' => 'money'],
                    ['label' => 'Total', 'value' => $order['total'], 'type' => 'money'],
                    ['label' => 'Method', 'value' => $order['payment_method'] === 'cash' ? 'Cash on fulfilment' : 'Sandbox gateway'],
                    ['label' => 'Payment status', 'value' => $order['payment_status'], 'type' => 'badge'],
                ]]) ?>
                <p class="t-micro t-muted tw-mt-3 tw-mb-0">
                    No card number, PIN or mobile-money credential is stored by the platform, so
                    there is nothing of that kind to show you here.
                </p>
            </div>

            <?php if ($isPickup) : ?>
                <div class="sl-card">
                    <h2 class="t-heading-md tw-mb-3">Collection code</h2>
                    <p class="t-caption t-muted tw-mb-3">
                        Stored as a hash. Nobody can read it back &mdash; not the seller, not support,
                        not us. If the customer has lost it, reissue it: the old one stops working,
                        the new one goes to the customer, and the action is audited against
                        <?= e($justification) ?>.
                    </p>

                    <?php if (($order['pickup']['code_issued'] ?? false) === false) : ?>
                        <p class="t-caption t-muted tw-mb-0">
                            No code has been issued yet &mdash; the seller has not marked this ready
                            to collect. There is nothing to reissue.
                        </p>
                    <?php else : ?>
                        <?= component('detail-list', ['items' => [
                            ['label' => 'Issued', 'value' => $order['pickup']['code_issued_at'], 'type' => 'datetime'],
                            ['label' => 'Collect by', 'value' => $order['pickup']['window_to'], 'type' => 'datetime'],
                        ]]) ?>

                        <form method="post" action="<?= e(route('support.orders.reissue')) ?>" class="tw-m-0 tw-mt-4">
                            <?= csrf_field() ?>
                            <input type="hidden" name="ref" value="<?= e($order['ref']) ?>">
                            <input type="hidden" name="ticket" value="<?= e($justification) ?>">
                            <button class="sl-btn sl-btn-outline-light sl-btn-block" type="submit">
                                Reissue and resend the code
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
