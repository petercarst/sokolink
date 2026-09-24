<?php
declare(strict_types=1);
/**
 * Product card.
 *
 * @var array<string,mixed> $product  shape produced by Support\View\Present::product()
 * @var bool                $onDark   render the cinematic variant
 *
 * The whole card is a link to the product page; the add-to-basket control is a
 * separate form so it is not nested inside the anchor (nesting interactive
 * elements breaks keyboard and screen-reader behaviour).
 */
$onDark = $onDark ?? false;
$out    = $product['stock_state'] === 'out';
?>
<article class="tw-flex tw-flex-col tw-h-full">
    <a class="sl-product-card<?= $onDark ? ' sl-product-card-dark' : '' ?>"
       href="<?= e(route('product.show', ['slug' => $product['slug']])) ?>">

        <div class="sl-product-media">
            <?= component('product-image', [
                'path'  => $product['image_path'] ?? null,
                'tone'  => $product['tone'],
                'label' => $product['name'],
            ]) ?>

            <?php if ($product['badges'] !== [] || $out) : ?>
                <div class="sl-product-flags">
                    <?php if ($out) : ?>
                        <?= component('badge', ['status' => 'out']) ?>
                    <?php elseif ($product['stock_state'] === 'low') : ?>
                        <?= component('badge', ['status' => 'low', 'label' => 'Only ' . $product['qty_available'] . ' left']) ?>
                    <?php endif; ?>

                    <?php foreach ($product['badges'] as $badge) : ?>
                        <span class="sl-chip sl-chip-mint"><?= e($badge) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="sl-product-body">
            <p class="sl-product-meta tw-mb-0"><?= e($product['brand']) ?> &middot; <?= e($product['pack_size']) ?></p>
            <h3 class="sl-product-title"><?= e($product['name']) ?></h3>

            <?= component('rating', ['rating' => $product['rating'], 'count' => $product['review_count']]) ?>

            <div class="tw-mt-auto tw-pt-2">
                <p class="tw-mb-1">
                    <span class="sl-product-price"><?= e(money($product['price'])) ?></span>
                    <?php if (!empty($product['compare_at_price'])) : ?>
                        <span class="sl-product-compare"><?= e(money($product['compare_at_price'])) ?></span>
                    <?php endif; ?>
                </p>
                <p class="sl-product-meta tw-mb-0 tw-flex tw-items-center tw-gap-2 tw-flex-wrap">
                    <span class="tw-inline-flex tw-items-center tw-gap-1">
                        <?= component('icon', ['name' => 'store', 'size' => 13]) ?><?= e($product['seller_name']) ?>
                    </span>
                    <?php if (in_array('delivery', $product['fulfilment'], true)) : ?>
                        <span class="tw-inline-flex tw-items-center tw-gap-1" title="Home delivery available">
                            <?= component('icon', ['name' => 'truck', 'size' => 13]) ?>Delivery
                        </span>
                    <?php endif; ?>
                    <?php if (in_array('pickup', $product['fulfilment'], true)) : ?>
                        <span class="tw-inline-flex tw-items-center tw-gap-1" title="Click and collect available">
                            <?= component('icon', ['name' => 'package', 'size' => 13]) ?>Collect
                        </span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </a>

    <div class="tw-pt-3">
        <?php if ($out) : ?>
            <button type="button" class="sl-btn sl-btn-outline-light sl-btn-sm sl-btn-block" disabled>
                Out of stock
            </button>
        <?php else : ?>
            <form method="post" action="<?= e(route('cart.add')) ?>" class="tw-m-0">
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">
                <input type="hidden" name="qty" value="1">
                <button type="submit" class="sl-btn sl-btn-primary sl-btn-sm sl-btn-block">
                    <?= component('icon', ['name' => 'cart', 'size' => 16]) ?>
                    Add to basket
                </button>
            </form>
        <?php endif; ?>
    </div>
</article>
