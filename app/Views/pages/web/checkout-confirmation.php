<?php
declare(strict_types=1);
/**
 * Checkout step 3 - confirmation.
 *
 * Reached only by redirect after a successful POST (POST-redirect-GET), so
 * refreshing it cannot create a second order (FR-CART-09).
 *
 * Note what this page deliberately does NOT say: it does not claim the payment
 * succeeded. Until a verified server-side record exists, the payment is
 * "being confirmed" (FR-PAY-06).
 *
 * @var array<string,mixed> $order  from Support\View\OrderView::present()
 * @var bool                $canSimulate  unpaid, and the sandbox driver is configured
 * @var int                 $step
 */
$orderNumber = $order['order_number'];
$placedAtUtc = $order['placed_at'];
$isCash      = $order['payment_method'] === 'cash';
?>

<section class="sl-section-tight">
    <div class="sl-container-read sl-container">

        <?= partial('checkout-steps', ['step' => $step]) ?>

        <div class="tw-text-center tw-mb-10">
            <span class="sl-empty-icon" style="background:var(--c-status-success-bg);color:var(--c-status-success-fg)">
                <?= component('icon', ['name' => 'check', 'size' => 28]) ?>
            </span>
            <h1 class="t-display-md tw-mt-6 tw-mb-4">Order placed</h1>
            <p class="t-body-lg t-muted tw-mb-6">
                We have your order. Each seller now confirms their part and you will be notified as
                it progresses.
            </p>
            <p class="t-caption t-muted tw-mb-0">
                Order number
                <span class="t-code t-body-strong tw-ml-2" style="color:var(--c-ink)"><?= e($orderNumber) ?></span>
            </p>
            <p class="t-micro t-muted tw-mt-1">Placed <?= time_tag($placedAtUtc) ?></p>
        </div>

        <?php if ($isCash) : ?>
            <div class="sl-alert sl-alert-info tw-mb-8">
                <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'package', 'size' => 18]) ?></span>
                <span>
                    <strong>You pay when you receive it.</strong>
                    Have <?= e(money($order['totals']['grand_total'])) ?> ready at the counter or for the
                    delivery agent. Nothing is charged before then, and this order will not expire
                    while it waits.
                </span>
            </div>
        <?php else : ?>
            <div class="sl-alert sl-alert-info tw-mb-8">
                <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'clock', 'size' => 18]) ?></span>
                <span>
                    <strong>Payment is being confirmed.</strong>
                    Your order status updates on its own once the payment provider confirms it to our server.
                    We never treat a message from your browser as proof of payment.
                    <?php if ($order['expires_at'] !== null) : ?>
                        <span class="tw-block tw-mt-1">
                            If it is not confirmed by <?= time_tag($order['expires_at']) ?>, the order is
                            released and the stock goes back on sale.
                        </span>
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($canSimulate) : ?>
                <!-- No provider is connected, so nothing will ever call our
                     webhook on its own. This posts the same payload a provider
                     would, signed with the same secret, to the same endpoint -
                     it shortcuts nothing except the provider's existence. -->
                <div class="sl-card sl-card-raised tw-mb-8">
                    <h2 class="t-heading-md tw-mb-2">Stand in for the payment provider</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        This build has no payment provider, so no confirmation will arrive by itself.
                        These buttons send the callback a provider would send - the signature check,
                        the idempotency key and the order transition are all the real ones.
                    </p>

                    <div class="tw-flex tw-gap-2 tw-flex-wrap">
                        <form method="post" action="<?= e(route('payment.simulate')) ?>" class="tw-m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="order_number" value="<?= e($orderNumber) ?>">
                            <input type="hidden" name="outcome" value="paid">
                            <button class="sl-btn sl-btn-aloe" type="submit">Confirm the payment</button>
                        </form>

                        <form method="post" action="<?= e(route('payment.simulate')) ?>" class="tw-m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="order_number" value="<?= e($orderNumber) ?>">
                            <input type="hidden" name="outcome" value="failed">
                            <button class="sl-btn sl-btn-outline-light" type="submit">Simulate a failure</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php foreach ($order['groups'] as $gi => $group) : ?>
            <?php $isPickup = $group['selected_fulfilment'] === 'pickup'; ?>

            <section class="sl-card sl-card-raised tw-mb-6">
                <header class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-pb-4 tw-mb-4"
                        style="border-bottom:1px solid var(--c-hairline-light)">
                    <div>
                        <p class="t-micro t-muted tw-mb-1">
                            Part <?= e((string) $group['part']) ?> of <?= e((string) count($order['groups'])) ?>
                            &middot; <span class="t-code"><?= e($group['sub_number']) ?></span>
                        </p>
                        <h2 class="t-heading-md tw-mb-0"><?= e($group['seller_name']) ?></h2>
                    </div>
                    <?= component('badge', ['status' => $group['status']]) ?>
                </header>

                <div class="tw-flex tw-items-start tw-gap-3 tw-mb-5">
                    <span class="tw-shrink-0 tw-mt-1 t-muted">
                        <?= component('icon', ['name' => $isPickup ? 'package' : 'truck', 'size' => 20]) ?>
                    </span>
                    <div>
                        <p class="t-body-strong tw-mb-1">
                            <?= e($isPickup ? 'Click and collect' : 'Home delivery') ?>
                        </p>
                        <p class="t-caption t-muted tw-mb-0">
                            <?php if ($isPickup) : ?>
                                You will get a collection code by email when the seller marks it ready.
                                Show the code at the counter to collect.
                            <?php else : ?>
                                Once the seller has packed it, a delivery agent is assigned and you will
                                get a code to give them on arrival.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <ul class="tw-list-none tw-p-0 tw-m-0">
                    <?php foreach ($group['items'] as $item) : ?>
                        <li class="tw-flex tw-justify-between tw-gap-3 tw-py-2"
                            style="border-top:1px solid var(--c-hairline-light)">
                            <span class="t-caption">
                                <?= e((string) $item['qty']) ?> &times; <?= e($item['name']) ?>
                                <span class="t-muted">(<?= e($item['pack_size']) ?>)</span>
                            </span>
                            <span class="t-caption tabular"><?= e(money($item['line_total'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

        <div class="sl-card sl-card-featured tw-mb-8">
            <div class="tw-flex tw-justify-between tw-gap-4">
                <span class="t-body-strong">Total</span>
                <span class="t-heading-xl tabular"><?= e(money($order['totals']['grand_total'])) ?></span>
            </div>
        </div>

        <h2 class="t-heading-xl tw-mb-4">What happens next</h2>
        <ol class="sl-timeline tw-mb-10">
            <li class="sl-timeline-item is-current">
                <p class="t-body-strong tw-mb-1">Payment confirmation</p>
                <p class="t-caption t-muted tw-mb-0">We are waiting for the provider to confirm to our server.</p>
            </li>
            <li class="sl-timeline-item">
                <p class="t-body-strong tw-mb-1">Seller accepts</p>
                <p class="t-caption t-muted tw-mb-0">Each seller accepts or declines their part, with a reason if declined.</p>
            </li>
            <li class="sl-timeline-item">
                <p class="t-body-strong tw-mb-1">Preparation</p>
                <p class="t-caption t-muted tw-mb-0">Your items are picked and packed.</p>
            </li>
            <li class="sl-timeline-item">
                <p class="t-body-strong tw-mb-1">Ready</p>
                <p class="t-caption t-muted tw-mb-0">You get a collection code, or the delivery agent is assigned.</p>
            </li>
            <li class="sl-timeline-item">
                <p class="t-body-strong tw-mb-1">Completed</p>
                <p class="t-caption t-muted tw-mb-0">Collected or delivered, confirmed with your code.</p>
            </li>
        </ol>

        <div class="tw-flex tw-flex-wrap tw-gap-3">
            <a class="sl-btn sl-btn-primary" href="<?= e(route('customer.orders.show', ['ref' => $orderNumber])) ?>">Track this order</a>
            <a class="sl-btn sl-btn-outline-light" href="<?= e(route('catalog.index')) ?>">Continue shopping</a>
            <a class="sl-btn sl-btn-outline-light" href="<?= e(route('page.contact')) ?>">Get help with this order</a>
        </div>

        <div class="tw-mt-8">
            <?= component('devnote', [
                'text' => 'This order is real and is in the database. No payment provider is connected, so a card or '
                        . 'mobile-money payment is settled by the sandbox driver rather than charged.',
            ]) ?>
        </div>
    </div>
</section>
