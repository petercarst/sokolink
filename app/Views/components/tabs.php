<?php
declare(strict_types=1);
/**
 * Tab strip. Real links to real pages, not JavaScript panels, so each tab is
 * bookmarkable and the back button works.
 *
 * @var list<array{label:string,url:string,current?:bool,count?:int}> $tabs
 * @var string $label  accessible name for the nav
 */
$tabs = $tabs ?? [];
?>
<nav class="sl-tabs" aria-label="<?= e($label ?? 'Sections') ?>">
    <?php foreach ($tabs as $tab) : ?>
        <a class="sl-tab <?= !empty($tab['current']) ? 'is-current' : '' ?>"
           href="<?= e($tab['url']) ?>"
           <?= !empty($tab['current']) ? 'aria-current="page"' : '' ?>>
            <?= e($tab['label']) ?>
            <?php if (isset($tab['count'])) : ?>
                <span class="sl-side-count"><?= e((string) $tab['count']) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
