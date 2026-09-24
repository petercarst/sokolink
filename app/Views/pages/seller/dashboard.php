<?php
declare(strict_types=1);
/**
 * Seller overview.
 *
 * @var list<array<string,mixed>> $incoming
 * @var list<array<string,mixed>> $pickup
 * @var list<array<string,mixed>> $dispatch
 * @var list<array<string,mixed>> $series
 * @var list<array<string,mixed>> $lowStock
 * @var string                    $revenue        lifetime, summed in SQL
 * @var int                       $completedCount
 * @var string                    $storeName
 */
// A quiet fortnight is still a fortnight: without this floor every bar would
// be drawn at 100% of nothing.
$maxOrders = max(1, max(array_column($series, 'orders')));
?>
<div class="sl-container-wide sl-container">

    <?= partial('dash-page-header', [
        'title'    => $storeName,
        'subtitle' => 'What needs doing today, and how the week is going.',
        'actions'  => [
            ['label' => 'Add a product', 'url' => route('seller.products.form'), 'style' => 'sl-btn-primary', 'icon' => 'plus'],
            ['label' => 'Verify a collection', 'url' => route('seller.pickup.verify'), 'style' => 'sl-btn-aloe', 'icon' => 'qr'],
        ],
    ]) ?>

    <?= component('stat-row', ['stats' => [
        ['label' => 'Needs your action', 'value' => (string) count($incoming), 'hint' => 'Accept, prepare or mark ready', 'href' => route('seller.orders')],
        ['label' => 'Waiting for collection', 'value' => (string) count($pickup), 'hint' => 'Packed and coded', 'href' => route('seller.pickup')],
        ['label' => 'Out for delivery', 'value' => (string) count($dispatch), 'hint' => 'With an agent', 'href' => route('seller.dispatch')],
        ['label' => 'Revenue', 'value' => money_compact($revenue), 'hint' => $completedCount . ' completed orders', 'href' => route('seller.reports')],
    ]]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 340px;">
        <div>
            <section class="tw-mb-10">
                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-4">
                    <h2 class="t-heading-xl tw-mb-0">Orders needing you</h2>
                    <a class="t-micro sl-link-more" href="<?= e(route('seller.orders')) ?>">See all</a>
                </div>

                <?php if ($incoming === []) : ?>
                    <?= component('empty-state', [
                        'icon' => 'check-circle', 'title' => 'Nothing waiting',
                        'text' => 'Every order has been dealt with. New ones appear here as they arrive.',
                    ]) ?>
                <?php else : ?>
                    <div class="tw-flex tw-flex-col tw-gap-4">
                        <?php foreach ($incoming as $order) : ?>
                            <article class="sl-card sl-card-raised">
                                <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-mb-3">
                                    <div>
                                        <p class="t-micro t-muted tw-mb-1">
                                            <span class="t-code"><?= e($order['ref']) ?></span>
                                            &middot; <?= e($order['customer_name']) ?>
                                            &middot; <?= time_tag($order['placed_at_utc'], true) ?>
                                        </p>
                                        <h3 class="t-heading-md tw-mb-0">
                                            <?= e((string) count($order['items'])) ?> item<?= count($order['items']) === 1 ? '' : 's' ?>
                                            &middot; <?= e(money($order['total'])) ?>
                                            &middot; <?= e($order['fulfilment'] === 'pickup' ? 'Collect' : 'Delivery') ?>
                                        </h3>
                                    </div>
                                    <?= component('badge', ['status' => $order['status']]) ?>
                                </div>

                                <ul class="tw-list-none tw-p-0 tw-m-0 tw-mb-4">
                                    <?php foreach ($order['items'] as $item) : ?>
                                        <li class="t-caption t-muted">
                                            <?= e((string) $item['qty']) ?> &times; <?= e($item['name']) ?>
                                            <span class="t-code tw-ml-1"><?= e($item['sku']) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>

                                <div class="tw-flex tw-gap-2 tw-flex-wrap">
                                    <a class="sl-btn sl-btn-outline-light sl-btn-sm"
                                       href="<?= e(route('seller.orders.show', ['ref' => $order['ref']])) ?>">Open</a>

                                    <?php if ($order['status'] === 'awaiting_seller') : ?>
                                        <form method="post" action="<?= e(route('seller.orders.accept')) ?>" class="tw-m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="ref" value="<?= e($order['ref']) ?>">
                                            <button class="sl-btn sl-btn-aloe sl-btn-sm" type="submit">Accept</button>
                                        </form>
                                    <?php elseif ($order['status'] === 'confirmed') : ?>
                                        <form method="post" action="<?= e(route('seller.orders.prepare')) ?>" class="tw-m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="ref" value="<?= e($order['ref']) ?>">
                                            <button class="sl-btn sl-btn-primary sl-btn-sm" type="submit">Start preparing</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="sl-card">
                <h2 class="t-heading-xl tw-mb-2">This week</h2>
                <p class="t-caption t-muted tw-mb-5">Orders per day across your stores.</p>

                <div class="tw-flex tw-items-end tw-gap-3" style="height:180px">
                    <?php foreach ($series as $day) : ?>
                        <?php $pct = (int) round(($day['orders'] / $maxOrders) * 100); ?>
                        <div class="tw-flex-1 tw-flex tw-flex-col tw-items-center tw-gap-2 tw-h-full tw-justify-end">
                            <span class="t-micro tabular"><?= e((string) $day['orders']) ?></span>
                            <span style="width:100%;height:<?= e((string) $pct) ?>%;background:var(--c-aloe-10);border-radius:var(--r-sm) var(--r-sm) 0 0"
                                  role="img"
                                  aria-label="<?= e($day['label']) ?>: <?= e((string) $day['orders']) ?> orders, <?= e(money($day['revenue'])) ?>"></span>
                            <span class="t-micro t-muted"><?= e($day['label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <a class="sl-btn sl-btn-outline-light sl-btn-sm tw-mt-5" href="<?= e(route('seller.reports')) ?>">
                    Full sales report
                </a>
            </section>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Stock needing attention</h2>

                <?php if ($lowStock === []) : ?>
                    <p class="t-caption t-muted tw-mb-0">Everything is in stock.</p>
                <?php else : ?>
                    <ul class="tw-list-none tw-p-0 tw-m-0">
                        <?php foreach ($lowStock as $product) : ?>
                            <li class="tw-flex tw-items-center tw-justify-between tw-gap-3 tw-py-3"
                                style="border-bottom:1px solid var(--c-hairline-light)">
                                <span style="min-width:0">
                                    <span class="t-caption t-body-strong tw-block"><?= e(str_limit($product['name'], 28)) ?></span>
                                    <span class="t-micro t-muted"><?= e((string) $product['qty_available']) ?> left</span>
                                </span>
                                <?= component('badge', ['status' => $product['stock_state']]) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="sl-btn sl-btn-outline-light sl-btn-sm sl-btn-block tw-mt-4"
                       href="<?= e(route('seller.inventory')) ?>">Update stock</a>
                <?php endif; ?>
            </div>

            <div class="sl-card sl-card-band">
                <h2 class="t-heading-md tw-mb-3">Reorder reminders</h2>
                <p class="t-caption tw-mb-4">
                    Set how long a pack typically lasts and the platform times a single reminder for
                    customers who asked for one. It combines your guide with how often that customer
                    has actually reordered.
                </p>
                <a class="sl-btn sl-btn-outline-light sl-btn-sm" href="<?= e(route('seller.reminders')) ?>">
                    Set consumption guides
                </a>
            </div>
        </aside>
    </div>
</div>
