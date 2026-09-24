<?php
declare(strict_types=1);
/**
 * Breadcrumb trail.
 *
 * @var list<array{label:string,url:?string}> $crumbs
 * @var bool $onDark
 */
$crumbs = $crumbs ?? [];
$onDark = $onDark ?? false;

if ($crumbs === []) {
    return;
}
?>
<nav aria-label="Breadcrumb" class="tw-mb-6">
    <ol class="tw-flex tw-flex-wrap tw-items-center tw-gap-1 tw-list-none tw-p-0 tw-m-0 t-caption">
        <?php foreach ($crumbs as $i => $crumb) : ?>
            <li class="tw-flex tw-items-center tw-gap-1">
                <?php if ($i > 0) : ?>
                    <span class="<?= $onDark ? 't-muted-dark' : 't-muted' ?>" aria-hidden="true">
                        <?= component('icon', ['name' => 'chevron-right', 'size' => 14]) ?>
                    </span>
                <?php endif; ?>

                <?php if (!empty($crumb['url'])) : ?>
                    <a class="<?= $onDark ? 't-muted-dark' : 't-muted' ?> tw-no-underline hover:tw-underline"
                       href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a>
                <?php else : ?>
                    <span aria-current="page"><?= e($crumb['label']) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
