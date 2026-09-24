<?php
declare(strict_types=1);
/**
 * Customer reviews of this seller's products.
 *
 * A seller may reply ONCE to a review and cannot edit or remove it (FR-REV-03).
 * Reporting a review sends it to admin moderation rather than hiding it
 * directly - otherwise "moderation" would just mean "sellers delete criticism".
 *
 * @var list<array<string,mixed>> $reviews
 */
$avg = $reviews === []
    ? 0.0
    : array_sum(array_column($reviews, 'rating')) / count($reviews);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Customer reviews',
        'subtitle' => 'What customers said. You can reply once to each; you cannot edit or remove them.',
    ]) ?>

    <?= component('stat-row', ['cols' => '3', 'stats' => [
        ['label' => 'Average rating', 'value' => number_format($avg, 1), 'hint' => 'Across your products'],
        ['label' => 'Reviews', 'value' => (string) count($reviews), 'hint' => 'All verified purchases'],
        ['label' => 'Awaiting your reply', 'value' => (string) count(array_filter($reviews, static fn (array $r): bool => empty($r['seller_reply']))), 'hint' => 'A reply is optional'],
    ]]) ?>

    <?php if ($reviews === []) : ?>
        <?= component('empty-state', [
            'icon' => 'star', 'title' => 'No reviews yet',
            'text' => 'Customers can review a product once their order is completed.',
        ]) ?>
    <?php else : ?>
        <div class="tw-flex tw-flex-col tw-gap-4">
            <?php foreach ($reviews as $review) : ?>
                <article class="sl-card">
                    <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-mb-3">
                        <div class="tw-flex tw-gap-3">
                            <span class="sl-avatar"><?= e(initials($review['customer_name'])) ?></span>
                            <div>
                                <p class="t-body-strong tw-mb-1"><?= e($review['customer_name']) ?></p>
                                <div class="tw-flex tw-items-center tw-gap-3 tw-flex-wrap">
                                    <?= component('rating', ['rating' => (float) $review['rating'], 'showCount' => false]) ?>
                                    <?php if (!empty($review['is_verified'])) : ?>
                                        <span class="sl-chip sl-chip-mint">Verified purchase</span>
                                    <?php endif; ?>
                                    <span class="t-micro t-muted"><?= time_tag($review['created_at_utc'], true) ?></span>
                                </div>
                            </div>
                        </div>
                        <?= component('badge', ['status' => 'published']) ?>
                    </div>

                    <h2 class="t-heading-md tw-mb-2"><?= e($review['title']) ?></h2>
                    <p class="t-body-md t-muted tw-mb-4"><?= e($review['body']) ?></p>

                    <?php if (!empty($review['seller_reply'])) : ?>
                        <div class="sl-card sl-card-tight" style="background:#f7f7f8;border-color:transparent">
                            <p class="t-caption t-body-strong tw-mb-1">
                                Your reply
                                <?php if (!empty($review['seller_replied_at_utc'])) : ?>
                                    <span class="t-micro t-muted tw-ml-2"><?= time_tag($review['seller_replied_at_utc'], true) ?></span>
                                <?php endif; ?>
                            </p>
                            <p class="t-caption t-muted tw-mb-0"><?= e($review['seller_reply']) ?></p>
                        </div>
                    <?php else : ?>
                        <details>
                            <summary class="t-body-strong" style="cursor:pointer">Reply to this review</summary>
                            <form method="post" action="<?= e(route('seller.reviews.reply')) ?>" class="tw-mt-4" data-validate>
                                <?= csrf_field() ?>
                                <input type="hidden" name="review_id" value="<?= e((string) $review['id']) ?>">

                                <div class="sl-field">
                                    <label class="sl-label" for="reply-<?= e((string) $review['id']) ?>">
                                        Your reply<span class="sl-required" aria-hidden="true">*</span>
                                        <span class="visually-hidden"> (required)</span>
                                    </label>
                                    <textarea class="sl-textarea" id="reply-<?= e((string) $review['id']) ?>"
                                              name="body" required maxlength="800"></textarea>
                                    <span class="sl-help">
                                        Published publicly under the review. You get one reply, so make it count &mdash;
                                        a straight answer reads better than a defence.
                                    </span>
                                </div>

                                <div class="tw-flex tw-gap-2 tw-flex-wrap">
                                    <button class="sl-btn sl-btn-primary sl-btn-sm" type="submit">Publish reply</button>
                                    <button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit"
                                            name="feature" value="review_report">
                                        Report to moderation
                                    </button>
                                </div>
                            </form>
                        </details>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
