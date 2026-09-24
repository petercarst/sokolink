<?php
declare(strict_types=1);
/**
 * Product detail - cinematic track.
 *
 * @var array<string,mixed>       $product
 * @var list<array<string,mixed>> $stores    collection points, each with its own stock
 * @var list<array<string,mixed>> $reviews
 * @var list<array<string,mixed>> $related
 */
$out    = $product['stock_state'] === 'out';
$crumbs = [
    ['label' => 'Home', 'url' => route('home')],
    ['label' => 'All products', 'url' => route('catalog.index')],
    ['label' => $product['category_name'], 'url' => route('catalog.category', ['slug' => $product['category_slug']])],
    ['label' => $product['name'], 'url' => null],
];

$ratingBuckets = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
foreach ($reviews as $review) {
    $ratingBuckets[$review['rating']] = ($ratingBuckets[$review['rating']] ?? 0) + 1;
}
$reviewTotal = max(1, count($reviews));
?>

<section class="sl-section-tight">
    <div class="sl-container">
        <?= partial('breadcrumbs', ['crumbs' => $crumbs, 'onDark' => true]) ?>

        <div class="lg:tw-grid lg:tw-gap-12" style="grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);">

            <!-- Gallery -->
            <div class="tw-mb-10 lg:tw-mb-0">
                <div class="sl-photo-frame tw-mb-4" style="aspect-ratio:4/3">
                    <?= component('product-image', [
                        'path'  => $product['image_path'],
                        'tone'  => $product['tone'],
                        'label' => $product['name'],
                    ]) ?>
                </div>
                <div class="tw-grid tw-grid-cols-4 tw-gap-3">
                    <?php foreach (['a', 'b', 'c', 'd'] as $i => $variant) : ?>
                        <button class="sl-photo-frame tw-border-0 tw-p-0" style="aspect-ratio:1/1;border-radius:var(--r-md)"
                                type="button" aria-label="View image <?= e((string) ($i + 1)) ?> of <?= e($product['name']) ?>"
                                <?= $i === 0 ? 'aria-current="true"' : '' ?>>
                            <?= component('product-image', [
                                'tone' => $product['tone'], 'label' => $product['name'] . $variant, 'mark' => false,
                            ]) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="t-micro t-muted-dark tw-mt-3">
                    Placeholder imagery. Real product photography replaces this once supplied (OQ-06c).
                </p>
            </div>

            <!-- Buy panel -->
            <div>
                <p class="t-eyebrow t-muted-dark tw-mb-3">
                    <?= e($product['brand']) ?> &middot; <?= e($product['pack_size']) ?>
                </p>

                <h1 class="t-display-md tw-mb-4"><?= e($product['name']) ?></h1>

                <div class="tw-flex tw-items-center tw-gap-4 tw-flex-wrap tw-mb-6">
                    <?= component('rating', ['rating' => $product['rating'], 'count' => $product['review_count']]) ?>
                    <a class="t-caption sl-link-quiet t-muted-dark tw-inline-block tw-py-1" href="#reviews">Read reviews</a>
                    <?= component('badge', ['status' => $product['stock_state'], 'onDark' => true]) ?>
                </div>

                <p class="tw-mb-2">
                    <span class="t-display-md"><?= e(money($product['price'])) ?></span>
                    <?php if (!empty($product['compare_at_price'])) : ?>
                        <span class="sl-product-compare t-muted-dark" style="font-size:18px">
                            <?= e(money($product['compare_at_price'])) ?>
                        </span>
                    <?php endif; ?>
                </p>
                <p class="t-caption t-muted-dark tw-mb-8">
                    Per <?= e($product['unit']) ?> &middot; <?= e($product['pack_size']) ?>
                    &middot; SKU <span class="t-code"><?= e($product['sku']) ?></span>
                </p>

                <!-- Seller -->
                <a class="sl-card-cinematic tw-flex tw-items-center tw-gap-4 tw-no-underline tw-mb-8"
                   style="padding:var(--s-lg)"
                   href="<?= e(route('store.show', ['slug' => $stores[0]['slug'] ?? ''])) ?>">
                    <span class="sl-avatar"><?= e(initials($product['seller_name'])) ?></span>
                    <span class="tw-flex-1">
                        <span class="t-body-strong tw-block"><?= e($product['seller_name']) ?></span>
                        <span class="t-caption t-muted-dark">
                            <?= e((string) count($stores)) ?> collection point<?= count($stores) === 1 ? '' : 's' ?>
                        </span>
                    </span>
                    <span class="t-muted-dark"><?= component('icon', ['name' => 'chevron-right', 'size' => 18]) ?></span>
                </a>

                <!-- Fulfilment -->
                <h2 class="t-heading-md tw-mb-3">How you can receive it</h2>
                <div class="tw-flex tw-flex-col tw-gap-2 tw-mb-8">
                    <?php if (in_array('pickup', $product['fulfilment'], true)) : ?>
                        <div class="sl-card-cinematic tw-flex tw-items-start tw-gap-3" style="padding:var(--s-lg)">
                            <span class="tw-shrink-0 tw-mt-1" style="color:var(--c-shade-40)">
                                <?= component('icon', ['name' => 'package', 'size' => 20]) ?>
                            </span>
                            <span>
                                <span class="t-body-strong tw-block">Click and collect</span>
                                <span class="t-caption t-muted-dark">
                                    Choose a store at checkout. You get a collection code when it is packed.
                                </span>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array('delivery', $product['fulfilment'], true)) : ?>
                        <div class="sl-card-cinematic tw-flex tw-items-start tw-gap-3" style="padding:var(--s-lg)">
                            <span class="tw-shrink-0 tw-mt-1" style="color:var(--c-shade-40)">
                                <?= component('icon', ['name' => 'truck', 'size' => 20]) ?>
                            </span>
                            <span>
                                <span class="t-body-strong tw-block">Home delivery</span>
                                <span class="t-caption t-muted-dark">
                                    Charge depends on your delivery zone and is shown before you pay.
                                </span>
                            </span>
                        </div>
                    <?php else : ?>
                        <div class="sl-card-cinematic tw-flex tw-items-start tw-gap-3" style="padding:var(--s-lg);opacity:.6">
                            <span class="tw-shrink-0 tw-mt-1" style="color:var(--c-shade-40)">
                                <?= component('icon', ['name' => 'truck', 'size' => 20]) ?>
                            </span>
                            <span>
                                <span class="t-body-strong tw-block">Home delivery not available</span>
                                <span class="t-caption t-muted-dark">
                                    This seller offers collection only for this product.
                                </span>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Add to basket -->
                <?php if ($out) : ?>
                    <div class="sl-alert sl-alert-danger tw-mb-4">
                        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                        <span>
                            <strong>Out of stock.</strong>
                            This product is unavailable at every store that lists it.
                        </span>
                    </div>
                    <button class="sl-btn sl-btn-outline-dark sl-btn-lg sl-btn-block" type="button" disabled>
                        Out of stock
                    </button>
                <?php else : ?>
                    <form method="post" action="<?= e(route('cart.add')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">

                        <?php if (in_array('pickup', $product['fulfilment'], true) && $stores !== []) : ?>
                            <div class="sl-field">
                                <label class="sl-label" for="add-store">Collect from (optional)</label>
                                <select class="sl-select sl-input-dark" id="add-store" name="store_id">
                                    <option value="">Decide at checkout</option>
                                    <?php foreach ($stores as $st) : ?>
                                        <option value="<?= e((string) $st['id']) ?>" <?= $st['stock_state'] === 'out' ? 'disabled' : '' ?>>
                                            <?= e($st['name']) ?> - <?= e($st['district']) ?><?= $st['stock_state'] === 'out' ? ' (out of stock)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="tw-mb-4">
                            <?= component('quantity', [
                                'value'     => 1,
                                'max'       => (int) $product['qty_available'],
                                'name'      => 'qty',
                                'label'     => $product['name'],
                                'unitPrice' => $product['price'],
                                'onDark'    => true,
                            ]) ?>
                        </div>

                        <button class="sl-btn sl-btn-outline-dark sl-btn-lg sl-btn-block" type="submit">
                            <?= component('icon', ['name' => 'cart', 'size' => 20]) ?> Add to basket
                        </button>
                    </form>
                <?php endif; ?>

                <!-- Stock by store -->
                <h2 class="t-heading-md tw-mt-10 tw-mb-3">Availability by store</h2>
                <ul class="tw-list-none tw-p-0 tw-m-0 tw-flex tw-flex-col tw-gap-2">
                    <?php foreach ($stores as $store) : ?>
                        <li class="tw-flex tw-items-center tw-justify-between tw-gap-3 tw-py-3"
                            style="border-bottom:1px solid var(--c-hairline-dark)">
                            <span>
                                <a class="t-body-strong tw-no-underline t-on-dark"
                                   href="<?= e(route('store.show', ['slug' => $store['slug']])) ?>"><?= e($store['name']) ?></a>
                                <span class="t-caption t-muted-dark tw-block"><?= e($store['district']) ?>, <?= e($store['region']) ?></span>
                            </span>
                            <?= component('badge', [
                                'status' => $store['stock_state'],
                                'label'  => $store['stock_state'] === 'low'
                                    ? 'Only ' . $store['qty_available'] . ' left'
                                    : null,
                                'onDark' => true,
                            ]) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if (!empty($product['is_consumable']) && !empty($product['typical_consumption_days'])) : ?>
                    <div class="sl-card-cinematic tw-mt-8 tw-flex tw-items-start tw-gap-3" style="padding:var(--s-lg)">
                        <span class="tw-shrink-0 tw-mt-1" style="color:var(--c-shade-40)">
                            <?= component('icon', ['name' => 'repeat', 'size' => 20]) ?>
                        </span>
                        <span>
                            <span class="t-body-strong tw-block">Reorder reminders available</span>
                            <span class="t-caption t-muted-dark">
                                A <?= e($product['pack_size']) ?> <?= e($product['unit']) ?> typically lasts a household
                                about <?= e((string) $product['typical_consumption_days']) ?> days. If you turn reminders
                                on, we use that together with how often you have actually reordered to time a single
                                nudge. You can stop them at any time.
                            </span>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Description -->
<section class="sl-section">
    <div class="sl-container">
        <div class="lg:tw-grid lg:tw-gap-12" style="grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);">
            <div>
                <h2 class="t-display-md tw-mb-6">About this product</h2>
                <p class="t-body-lg t-muted-dark"><?= e($product['description']) ?></p>
            </div>
            <div>
                <h2 class="t-heading-xl tw-mb-6">Details</h2>
                <dl class="tw-m-0">
                    <?php
                    $details = [
                        'Brand'     => $product['brand'],
                        'Pack size' => $product['pack_size'],
                        'Sold as'   => ucfirst((string) $product['unit']),
                        'Category'  => $product['category_name'],
                        'SKU'       => $product['sku'],
                        'Seller'    => $product['seller_name'],
                    ];
                    foreach ($details as $label => $value) : ?>
                        <div class="tw-flex tw-justify-between tw-gap-4 tw-py-3"
                             style="border-bottom:1px solid var(--c-hairline-dark)">
                            <dt class="t-caption t-muted-dark tw-m-0"><?= e($label) ?></dt>
                            <dd class="t-body-md tw-m-0 tw-text-right"><?= e((string) $value) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>
    </div>
</section>

<!-- Reviews -->
<section class="sl-section" id="reviews">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-8">Customer reviews</h2>

        <div class="lg:tw-grid lg:tw-gap-12" style="grid-template-columns: 300px minmax(0, 1fr);">
            <div class="tw-mb-8 lg:tw-mb-0">
                <div class="sl-card-cinematic">
                    <p class="t-display-md tw-mb-2"><?= e(number_format((float) $product['rating'], 1)) ?></p>
                    <?= component('rating', ['rating' => $product['rating'], 'showCount' => false]) ?>
                    <p class="t-caption t-muted-dark tw-mt-2 tw-mb-6">
                        Based on <?= e((string) $product['review_count']) ?> reviews
                    </p>

                    <?php foreach ([5, 4, 3, 2, 1] as $star) : ?>
                        <?php $pct = (int) round(($ratingBuckets[$star] / $reviewTotal) * 100); ?>
                        <div class="tw-flex tw-items-center tw-gap-2 tw-mb-2">
                            <span class="t-micro t-muted-dark" style="width:1.5rem"><?= e((string) $star) ?></span>
                            <span class="tw-flex-1" style="height:6px;background:var(--c-hairline-dark);border-radius:var(--r-pill)">
                                <span style="display:block;height:6px;width:<?= e((string) $pct) ?>%;background:var(--c-shade-40);border-radius:var(--r-pill)"></span>
                            </span>
                            <span class="t-micro t-muted-dark tabular" style="width:2.5rem;text-align:right"><?= e((string) $pct) ?>%</span>
                        </div>
                    <?php endforeach; ?>

                    <p class="t-micro t-muted-dark tw-mt-6 tw-mb-0">
                        Only customers whose order was completed can review a product.
                    </p>
                </div>
            </div>

            <div>
                <?php if ($reviews === []) : ?>
                    <?= component('empty-state', [
                        'icon'  => 'star',
                        'title' => 'No reviews yet',
                        'text'  => 'This product has not been reviewed. Reviews can only be left by customers whose order was completed.',
                    ]) ?>
                <?php else : ?>
                    <?php foreach ($reviews as $review) : ?>
                        <article class="tw-pb-8 tw-mb-8" style="border-bottom:1px solid var(--c-hairline-dark)">
                            <div class="tw-flex tw-items-start tw-gap-4 tw-mb-3">
                                <span class="sl-avatar"><?= e(initials($review['customer_name'])) ?></span>
                                <div class="tw-flex-1">
                                    <p class="t-body-strong tw-mb-1"><?= e($review['customer_name']) ?></p>
                                    <div class="tw-flex tw-items-center tw-gap-3 tw-flex-wrap">
                                        <?= component('rating', ['rating' => (float) $review['rating'], 'showCount' => false]) ?>
                                        <?php if (!empty($review['is_verified'])) : ?>
                                            <span class="sl-chip sl-chip-dark">
                                                <?= component('icon', ['name' => 'check', 'size' => 12]) ?> Verified purchase
                                            </span>
                                        <?php endif; ?>
                                        <span class="t-micro t-muted-dark"><?= time_tag($review['created_at_utc'], true) ?></span>
                                    </div>
                                </div>
                            </div>

                            <h3 class="t-heading-md tw-mb-2"><?= e($review['title']) ?></h3>
                            <p class="t-body-md t-muted-dark tw-mb-0"><?= e($review['body']) ?></p>

                            <?php if (!empty($review['seller_reply'])) : ?>
                                <div class="sl-card-cinematic sl-card-cinematic-el tw-mt-4" style="padding:var(--s-lg)">
                                    <p class="t-caption t-body-strong tw-mb-1">
                                        Reply from <?= e($product['seller_name']) ?>
                                        <span class="t-micro t-muted-dark tw-ml-2"><?= time_tag($review['seller_replied_at_utc'], true) ?></span>
                                    </p>
                                    <p class="t-caption t-muted-dark tw-mb-0"><?= e($review['seller_reply']) ?></p>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Related -->
<?php if ($related !== []) : ?>
    <section class="sl-section">
        <div class="sl-container">
            <h2 class="t-display-md tw-mb-8">Others also bought</h2>
            <div class="sl-grid sl-grid-4">
                <?php foreach ($related as $item) : ?>
                    <?= component('product-card', ['product' => $item, 'onDark' => true]) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>
