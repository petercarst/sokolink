<?php
declare(strict_types=1);
/**
 * Star rating.
 *
 * @var float $rating
 * @var int   $count      optional review count
 * @var bool  $showCount  optional
 *
 * The numeric value is inside the accessible name, not only in the star
 * shapes - stars alone are not a reliable signal for screen-reader users.
 */
$rating    = (float) ($rating ?? 0);
$count     = (int) ($count ?? 0);
$showCount = $showCount ?? true;
$rounded   = (int) round($rating);

$aria = 'Rated ' . number_format($rating, 1) . ' out of 5';
if ($showCount && $count > 0) {
    $aria .= ' from ' . $count . ' reviews';
}
?>
<span class="sl-rating" role="img" aria-label="<?= e($aria) ?>">
    <?php for ($i = 1; $i <= 5; $i++) : ?>
        <span class="<?= $i <= $rounded ? '' : 'sl-rating-empty' ?>" aria-hidden="true">
            <svg width="15" height="15" viewBox="0 0 24 24"
                 fill="<?= $i <= $rounded ? 'currentColor' : 'none' ?>"
                 stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"
                 aria-hidden="true" focusable="false">
                <path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.7l5.9-.9z"/>
            </svg>
        </span>
    <?php endfor; ?>
    <span class="t-micro tw-ml-1" aria-hidden="true"><?= e(number_format($rating, 1)) ?></span>
    <?php if ($showCount && $count > 0) : ?>
        <span class="t-micro t-muted" aria-hidden="true">(<?= e((string) $count) ?>)</span>
    <?php endif; ?>
</span>
