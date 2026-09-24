<?php
declare(strict_types=1);
/**
 * My reviews.
 *
 * Only products from a COMPLETED order can be reviewed (FR-REV-01). The
 * "waiting for your review" list is built from completed orders for exactly
 * that reason - there is no way to reach the review form for something you did
 * not buy.
 *
 * @var list<array<string,mixed>> $written
 * @var list<array<string,mixed>> $pending  completed orders
 */
$pendingItems = [];
foreach ($pending as $order) {
    foreach ($order['items'] as $item) {
        $pendingItems[] = ['name' => $item['name'], 'tone' => $item['tone'], 'order' => $order['parent_ref']];
    }
}
$pendingItems = array_slice($pendingItems, 0, 4);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'My reviews',
        'subtitle' => 'What you have written, and what you can review.',
    ]) ?>

    <?php if ($pendingItems !== []) : ?>
        <section class="tw-mb-10">
            <h2 class="t-heading-xl tw-mb-2">Waiting for your review</h2>
            <p class="t-caption t-muted tw-mb-4">
                From orders that completed. Reviews are marked as verified purchases because of that.
            </p>

            <div class="sl-grid sl-grid-4">
                <?php foreach ($pendingItems as $item) : ?>
                    <div class="sl-card sl-card-tight">
                        <div class="sl-photo-frame tw-mb-3" style="aspect-ratio:4/3;border-radius:var(--r-md)">
                            <?= component('product-image', ['tone' => $item['tone'], 'label' => $item['name'], 'mark' => false]) ?>
                        </div>
                        <p class="t-body-strong tw-mb-1"><?= e(str_limit($item['name'], 38)) ?></p>
                        <p class="t-micro t-muted tw-mb-3">Order <span class="t-code"><?= e($item['order']) ?></span></p>
                        <a class="sl-btn sl-btn-aloe sl-btn-sm sl-btn-block" href="<?= e(route('customer.reviews.write')) ?>">
                            <?= component('icon', ['name' => 'star', 'size' => 15]) ?> Write a review
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section>
        <h2 class="t-heading-xl tw-mb-4">Reviews you have written</h2>

        <?php if ($written === []) : ?>
            <?= component('empty-state', [
                'icon' => 'star', 'title' => 'You have not written a review yet',
                'text' => 'Once an order completes, the products in it appear above ready to review.',
            ]) ?>
        <?php else : ?>
            <div class="tw-flex tw-flex-col tw-gap-4">
                <?php foreach ($written as $review) : ?>
                    <article class="sl-card">
                        <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-mb-3">
                            <div>
                                <?= component('rating', ['rating' => (float) $review['rating'], 'showCount' => false]) ?>
                                <h3 class="t-heading-md tw-mt-2 tw-mb-1"><?= e($review['title']) ?></h3>
                                <p class="t-micro t-muted tw-mb-0">
                                    Published <?= time_tag($review['created_at_utc'], true) ?>
                                    <?php if (!empty($review['is_verified'])) : ?>
                                        &middot; <span class="sl-chip sl-chip-mint">Verified purchase</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?= component('badge', ['status' => 'published']) ?>
                        </div>

                        <p class="t-body-md t-muted tw-mb-4"><?= e($review['body']) ?></p>

                        <?php if (!empty($review['seller_reply'])) : ?>
                            <div class="sl-card sl-card-tight tw-mb-4" style="background:#f7f7f8;border-color:transparent">
                                <p class="t-caption t-body-strong tw-mb-1">
                                    Reply from the seller
                                    <span class="t-micro t-muted tw-ml-2"><?= time_tag($review['seller_replied_at_utc'], true) ?></span>
                                </p>
                                <p class="t-caption t-muted tw-mb-0"><?= e($review['seller_reply']) ?></p>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="feature" value="review">
                            <button class="sl-btn sl-btn-outline-light sl-btn-sm" type="submit">Edit review</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
