<?php
declare(strict_types=1);
/**
 * Server-side pagination (NFR-PRF-03).
 *
 * Links preserve the rest of the active filters via query_with(), so paging
 * does not silently drop the user's category or price range.
 *
 * @var int  $page
 * @var int  $pages
 * @var int  $total
 * @var bool $onDark
 */
$page   = (int) ($page ?? 1);
$pages  = (int) ($pages ?? 1);
$total  = (int) ($total ?? 0);
$onDark = $onDark ?? false;

if ($pages <= 1) {
    return;
}

// A window around the current page, so 200 pages do not render 200 links.
$window = 2;
$start  = max(1, $page - $window);
$end    = min($pages, $page + $window);
?>
<nav aria-label="Pagination" class="tw-mt-10 tw-flex tw-flex-wrap tw-items-center tw-justify-center tw-gap-2">
    <?php if ($page > 1) : ?>
        <a class="sl-btn <?= $onDark ? 'sl-btn-outline-dark' : 'sl-btn-outline-light' ?> sl-btn-sm"
           href="<?= e(query_with(['page' => $page - 1])) ?>" rel="prev">
            <?= component('icon', ['name' => 'chevron-left', 'size' => 16]) ?> Previous
        </a>
    <?php endif; ?>

    <?php if ($start > 1) : ?>
        <a class="sl-btn <?= $onDark ? 'sl-btn-ghost-dark' : 'sl-btn-ghost' ?> sl-btn-sm"
           href="<?= e(query_with(['page' => 1])) ?>">1</a>
        <?php if ($start > 2) : ?>
            <span class="<?= $onDark ? 't-muted-dark' : 't-muted' ?>" aria-hidden="true">...</span>
        <?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++) : ?>
        <?php if ($i === $page) : ?>
            <span class="sl-btn <?= $onDark ? 'sl-btn-outline-dark' : 'sl-btn-primary' ?> sl-btn-sm"
                  aria-current="page"><?= e((string) $i) ?></span>
        <?php else : ?>
            <a class="sl-btn <?= $onDark ? 'sl-btn-ghost-dark' : 'sl-btn-ghost' ?> sl-btn-sm"
               href="<?= e(query_with(['page' => $i])) ?>"
               aria-label="Page <?= e((string) $i) ?>"><?= e((string) $i) ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($end < $pages) : ?>
        <?php if ($end < $pages - 1) : ?>
            <span class="<?= $onDark ? 't-muted-dark' : 't-muted' ?>" aria-hidden="true">...</span>
        <?php endif; ?>
        <a class="sl-btn <?= $onDark ? 'sl-btn-ghost-dark' : 'sl-btn-ghost' ?> sl-btn-sm"
           href="<?= e(query_with(['page' => $pages])) ?>"><?= e((string) $pages) ?></a>
    <?php endif; ?>

    <?php if ($page < $pages) : ?>
        <a class="sl-btn <?= $onDark ? 'sl-btn-outline-dark' : 'sl-btn-outline-light' ?> sl-btn-sm"
           href="<?= e(query_with(['page' => $page + 1])) ?>" rel="next">
            Next <?= component('icon', ['name' => 'chevron-right', 'size' => 16]) ?>
        </a>
    <?php endif; ?>
</nav>

<p class="t-micro <?= $onDark ? 't-muted-dark' : 't-muted' ?> tw-text-center tw-mt-3">
    Page <?= e((string) $page) ?> of <?= e((string) $pages) ?> &middot; <?= e((string) $total) ?> products
</p>
