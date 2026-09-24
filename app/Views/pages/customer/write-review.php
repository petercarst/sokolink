<?php
declare(strict_types=1);
/**
 * Write a review.
 *
 * Reaching this form at all requires a completed order containing the product.
 * In Phase 3 that check is re-run server-side on submit, because a form the
 * browser can reach is a form the browser can forge (FR-REV-01).
 *
 * @var array<string,mixed> $product
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'My reviews', 'url' => route('customer.reviews')],
            ['label' => 'Write a review', 'url' => null],
        ],
        'title'    => 'Write a review',
        'subtitle' => 'Your review is published with a verified-purchase mark, because it is tied to a completed order.',
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 320px;">
        <section class="sl-card">
            <form method="post" action="<?= e(route('preview.submit')) ?>" data-validate>
                <?= csrf_field() ?>
                <input type="hidden" name="feature" value="review">
                <input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">

                <fieldset class="sl-field tw-border-0 tw-p-0 tw-m-0">
                    <legend class="sl-label">
                        Your rating<span class="sl-required" aria-hidden="true">*</span>
                        <span class="visually-hidden"> (required)</span>
                    </legend>
                    <div class="tw-flex tw-flex-col tw-gap-2">
                        <?php
                        $scale = [
                            5 => 'Excellent - exactly what I wanted',
                            4 => 'Good - a small niggle',
                            3 => 'Fair - it did the job',
                            2 => 'Poor - several problems',
                            1 => 'Bad - would not buy again',
                        ];
                        foreach ($scale as $value => $label) : ?>
                            <label class="sl-option">
                                <input type="radio" class="tw-mt-1" name="rating" value="<?= e((string) $value) ?>" required>
                                <span class="tw-flex-1 tw-flex tw-items-center tw-gap-3">
                                    <?= component('rating', ['rating' => (float) $value, 'showCount' => false]) ?>
                                    <span class="t-caption"><?= e($label) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <?= component('field', [
                    'name' => 'title', 'label' => 'Headline', 'required' => true,
                    'placeholder' => 'Sum it up in a few words',
                    'attrs' => ['maxlength' => '80'],
                ]) ?>

                <div class="sl-field">
                    <label class="sl-label" for="review-body">
                        Your review<span class="sl-required" aria-hidden="true">*</span>
                        <span class="visually-hidden"> (required)</span>
                    </label>
                    <textarea class="sl-textarea" id="review-body" name="body" required
                              maxlength="1500" data-counter="review-count" aria-describedby="review-count"
                              placeholder="What was it like? Was the quantity right? How was collection or delivery?"></textarea>
                    <span class="sl-help" id="review-count">1500 characters remaining</span>
                </div>

                <div class="sl-alert sl-alert-info tw-mb-6">
                    <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
                    <span class="t-caption">
                        Your first name and last initial are shown with the review. Your email
                        address and full name are never published.
                    </span>
                </div>

                <div class="tw-flex tw-gap-3 tw-flex-wrap">
                    <button class="sl-btn sl-btn-primary" type="submit">Publish review</button>
                    <a class="sl-btn sl-btn-ghost" href="<?= e(route('customer.reviews')) ?>">Cancel</a>
                </div>
            </form>
        </section>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card">
                <div class="sl-photo-frame tw-mb-4" style="aspect-ratio:4/3;border-radius:var(--r-md)">
                    <?= component('product-image', ['tone' => $product['tone'], 'label' => $product['name'], 'mark' => false]) ?>
                </div>
                <p class="t-body-strong tw-mb-1"><?= e($product['name']) ?></p>
                <p class="t-micro t-muted tw-mb-3">
                    <?= e($product['brand']) ?> &middot; <?= e($product['pack_size']) ?>
                </p>
                <?= component('rating', ['rating' => $product['rating'], 'count' => $product['review_count']]) ?>
                <a class="sl-btn sl-btn-outline-light sl-btn-sm sl-btn-block tw-mt-4"
                   href="<?= e(route('product.show', ['slug' => $product['slug']])) ?>">
                    View the product page
                </a>
            </div>
        </aside>
    </div>
</div>
