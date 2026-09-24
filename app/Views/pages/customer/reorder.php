<?php
declare(strict_types=1);
/**
 * Reorder.
 *
 * The important behaviour here is the REVALIDATION SUMMARY: before anything
 * touches the basket, each line is re-checked against the current price, stock
 * and seller status, and the outcome is shown (FR-CRM-11, USER_FLOWS.md Flow J).
 * Nothing is silently substituted and no price change is quietly absorbed.
 *
 * The list comes from ReorderService::revalidate(), which decides the outcome.
 * Pressing Add re-checks price and stock a THIRD time inside CartService, so
 * nothing on this page is trusted to decide what a line costs.
 *
 * @var list<array<string,mixed>> $items
 */
$labels = [
    'available'     => ['success', 'Available at the same price'],
    'price_changed' => ['warn',    'Price has changed'],
    'partial'       => ['warn',    'Only part of the quantity is available'],
    'unavailable'   => ['danger',  'Not available'],
];
$addable = array_filter($items, static fn (array $i): bool => $i['outcome'] !== 'unavailable');
?>
<div class="sl-container-wide sl-container">

    <?= partial('dash-page-header', [
        'title'    => 'Reorder',
        'subtitle' => 'Things you have bought before. We have re-checked today\'s price and stock for each one.',
        'actions'  => $addable === [] ? [] : [[
            'label' => 'Basket (' . count($addable) . ' can be added)',
            'url' => route('cart'), 'style' => 'sl-btn-aloe', 'icon' => 'cart',
        ]],
    ]) ?>

    <div class="sl-alert sl-alert-info tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
        <span>
            Prices and availability below were checked just now against the seller's current
            listing, not against what you paid last time. Anything that changed is flagged, and
            nothing is added to your basket until you confirm.
        </span>
    </div>

    <div class="tw-flex tw-flex-col tw-gap-4">
        <?php foreach ($items as $item) : ?>
            <?php [$tone, $outcomeText] = $labels[$item['outcome']]; ?>
            <article class="sl-card <?= $item['outcome'] === 'unavailable' ? '' : 'sl-card-raised' ?>">
                <div class="tw-flex tw-flex-wrap tw-gap-4 tw-items-start">
                    <span class="sl-photo-frame tw-shrink-0" style="width:80px;height:80px;border-radius:var(--r-md)">
                        <?= component('product-image', ['tone' => $item['tone'], 'label' => $item['product'], 'mark' => false]) ?>
                    </span>

                    <div class="tw-flex-1" style="min-width:14rem">
                        <p class="t-body-strong tw-mb-1">
                            <a href="<?= e(route('product.show', ['slug' => $item['slug']])) ?>"><?= e($item['product']) ?></a>
                        </p>
                        <p class="t-micro t-muted tw-mb-2">
                            <?= e($item['pack_size']) ?> &middot; <?= e($item['seller']) ?>
                            &middot; last bought <?= e(local_date($item['last_bought_utc'])) ?>
                        </p>

                        <span class="sl-badge sl-badge-<?= e($tone) ?>"><?= e($outcomeText) ?></span>

                        <?php if ($item['outcome'] === 'price_changed') : ?>
                            <p class="t-caption tw-mt-3 tw-mb-0">
                                You paid <s class="t-muted"><?= e(money($item['last_price'])) ?></s>,
                                it is now <strong><?= e(money($item['current_price'])) ?></strong>.
                            </p>
                        <?php elseif ($item['outcome'] === 'partial') : ?>
                            <p class="t-caption tw-mt-3 tw-mb-0">
                                You bought <?= e((string) $item['last_qty']) ?> last time and we can
                                get you <?= e((string) $item['available']) ?>.
                                <?php if ($item['in_basket'] > 0) : ?>
                                    <?= e((string) $item['in_basket']) ?> are already in your basket,
                                    which is counted here &mdash; the number above is what pressing
                                    Add would actually put in, not what exists somewhere.
                                <?php else : ?>
                                    We will add that and say so, rather than silently reducing it.
                                <?php endif; ?>
                            </p>
                        <?php elseif ($item['outcome'] === 'unavailable') : ?>
                            <p class="t-caption tw-mt-3 tw-mb-0">
                                <?php if ($item['in_basket'] > 0 && $item['in_stock'] > 0) : ?>
                                    Your basket already holds all
                                    <?= e((string) $item['in_basket']) ?> the seller has left, so
                                    there is nothing more to add.
                                <?php else : ?>
                                    The seller is out of stock and has not relisted it. Nothing will
                                    be added, and we will not substitute a different product for it.
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="tw-text-right tw-shrink-0">
                        <p class="t-heading-md tabular tw-mb-3"><?= e(money($item['current_price'])) ?></p>
                        <?php if ($item['outcome'] === 'unavailable') : ?>
                            <a class="sl-btn sl-btn-outline-light sl-btn-sm"
                               href="<?= e(route('catalog.index')) ?>?q=<?= e(urlencode($item['product'])) ?>">
                                Find similar
                            </a>
                        <?php else : ?>
                            <form method="post" action="<?= e(route('customer.reorder.add')) ?>" class="tw-m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="product_id" value="<?= e((string) $item['product_id']) ?>">
                                <button class="sl-btn sl-btn-aloe sl-btn-sm" type="submit">
                                    <?= component('icon', ['name' => 'cart', 'size' => 15]) ?>
                                    Add <?= e((string) min($item['last_qty'], $item['available'])) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="sl-card sl-card-band tw-mt-8">
        <h2 class="t-heading-md tw-mb-3">How we decide when to remind you</h2>
        <p class="t-caption tw-mb-3">
            We do not assume you have run out just because a set number of days has passed. The
            estimate uses, in order of preference: how often <em>you</em> have actually reordered
            this product, then the seller's guide for how long a pack lasts scaled by how much you
            bought. If neither exists, we send nothing rather than guessing.
        </p>
        <a class="sl-btn sl-btn-outline-light sl-btn-sm" href="<?= e(route('customer.preferences')) ?>">
            Manage reminders
        </a>
    </div>
</div>
