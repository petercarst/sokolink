<?php
declare(strict_types=1);
/**
 * Customer overview.
 *
 * @var list<array<string,mixed>> $active
 * @var list<array<string,mixed>> $inbox
 * @var list<array<string,mixed>> $reorder
 * @var string                    $spend       lifetime spend, summed in SQL
 * @var int                       $historyCount
 * @var string                    $firstName
 */
$unread = count(array_filter($inbox, static fn (array $n): bool => !$n['read']));
?>
<div class="sl-container-wide sl-container">

    <?= partial('dash-page-header', [
        'title'    => 'Hello, ' . $firstName,
        'subtitle' => 'Everything you have on the go, and the things you buy often.',
        'actions'  => [
            ['label' => 'Browse products', 'url' => route('catalog.index'), 'style' => 'sl-btn-primary', 'icon' => 'basket'],
        ],
    ]) ?>

    <?= component('stat-row', ['stats' => [
        ['label' => 'Active orders', 'value' => (string) count($active), 'hint' => 'Being prepared or on the way', 'href' => route('customer.orders')],
        ['label' => 'Unread messages', 'value' => (string) $unread, 'hint' => 'Order updates and reminders', 'href' => route('customer.notifications')],
        ['label' => 'Completed orders', 'value' => (string) $historyCount, 'hint' => 'All time', 'href' => route('customer.history')],
        ['label' => 'Lifetime spend', 'value' => money_compact($spend), 'hint' => 'Across all sellers', 'href' => route('customer.payments')],
    ]]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 380px;">
        <div>
            <h2 class="t-heading-xl tw-mb-4">What is happening now</h2>

            <?php if ($active === []) : ?>
                <?= component('empty-state', [
                    'icon' => 'package', 'title' => 'Nothing on the go',
                    'text' => 'When you place an order it will appear here with its live status.',
                    'actionUrl' => route('catalog.index'), 'actionLabel' => 'Start shopping',
                ]) ?>
            <?php else : ?>
                <div class="tw-flex tw-flex-col tw-gap-4 tw-mb-10">
                    <?php foreach ($active as $order) : ?>
                        <article class="sl-card sl-card-raised">
                            <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-mb-4">
                                <div>
                                    <p class="t-micro t-muted tw-mb-1">
                                        <span class="t-code"><?= e($order['parent_ref']) ?></span>
                                        &middot; <?= e($order['seller_name']) ?>
                                    </p>
                                    <h3 class="t-heading-md tw-mb-0">
                                        <?= e($order['fulfilment'] === 'pickup' ? 'Collect from ' . $order['store_name'] : 'Delivery to your address') ?>
                                    </h3>
                                </div>
                                <?= component('badge', ['status' => $order['status']]) ?>
                            </div>

                            <?php if ($order['status'] === 'ready_for_pickup') : ?>
                                <div class="sl-alert sl-alert-success tw-mb-4">
                                    <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'qr', 'size' => 18]) ?></span>
                                    <span>
                                        <strong>Ready to collect.</strong>
                                        Your collection code was emailed to you - only its hash is stored, so we
                                        cannot show it here. Bring it to <?= e($order['store_name']) ?>.
                                    </span>
                                </div>
                            <?php endif; ?>

                            <p class="t-caption t-muted tw-mb-4">
                                <?= e((string) $order['line_count']) ?> item<?= (int) $order['line_count'] === 1 ? '' : 's' ?>
                                &middot; <?= e(money($order['total'])) ?>
                                &middot; placed <?= time_tag($order['placed_at_utc'], true) ?>
                            </p>

                            <a class="sl-btn sl-btn-outline-light sl-btn-sm"
                               href="<?= e(route('customer.orders.show', ['ref' => $order['ref']])) ?>">
                                Track this order
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h2 class="t-heading-xl tw-mb-2">Buy again</h2>
            <p class="t-caption t-muted tw-mb-4">
                Things you have bought before, with today's price and availability checked.
            </p>

            <div class="sl-grid sl-grid-3 tw-mb-4">
                <?php foreach ($reorder as $item) : ?>
                    <div class="sl-card sl-card-tight">
                        <div class="sl-photo-frame tw-mb-3" style="aspect-ratio:4/3;border-radius:var(--r-md)">
                            <?= component('product-image', ['tone' => $item['tone'], 'label' => $item['product'], 'mark' => false]) ?>
                        </div>
                        <p class="t-body-strong tw-mb-1"><?= e(str_limit($item['product'], 40)) ?></p>
                        <p class="t-micro t-muted tw-mb-2">
                            <?= e($item['pack_size']) ?> &middot; last bought <?= e(local_date($item['last_bought_utc'])) ?>
                        </p>
                        <p class="t-body-strong tw-mb-3"><?= e(money($item['current_price'])) ?></p>
                        <a class="sl-btn sl-btn-aloe sl-btn-sm sl-btn-block" href="<?= e(route('customer.reorder')) ?>">
                            <?= component('icon', ['name' => 'repeat', 'size' => 15]) ?> Reorder
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <a class="sl-btn sl-btn-ghost" href="<?= e(route('customer.reorder')) ?>">
                See everything you can reorder <?= component('icon', ['name' => 'arrow-right', 'size' => 16]) ?>
            </a>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-4">
                    <h2 class="t-heading-md tw-mb-0">Recent messages</h2>
                    <a class="t-micro sl-link-more" href="<?= e(route('customer.notifications')) ?>">See all</a>
                </div>

                <ul class="tw-list-none tw-p-0 tw-m-0">
                    <?php foreach ($inbox as $note) : ?>
                        <li class="tw-py-3" style="border-bottom:1px solid var(--c-hairline-light)">
                            <p class="t-body-strong tw-mb-1">
                                <?php if (!$note['read']) : ?>
                                    <span class="sl-side-count tw-mr-1" style="min-width:8px;height:8px;padding:0;background:var(--c-ink)">
                                        <span class="visually-hidden">Unread</span>
                                    </span>
                                <?php endif; ?>
                                <?= e($note['title']) ?>
                            </p>
                            <p class="t-micro t-muted tw-mb-0"><?= e(str_limit($note['body'], 90)) ?></p>
                            <p class="t-micro t-muted tw-mb-0"><?= time_tag($note['at_utc'], true) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="sl-card sl-card-band">
                <h2 class="t-heading-md tw-mb-3">Reorder reminders</h2>
                <p class="t-caption tw-mb-4">
                    You have reminders switched on. We work out roughly when you will run low from
                    how much you bought and how often you have reordered before - not from a fixed
                    calendar.
                </p>
                <a class="sl-btn sl-btn-outline-light sl-btn-sm" href="<?= e(route('customer.preferences')) ?>">
                    Change what you receive
                </a>
            </div>
        </aside>
    </div>
</div>
