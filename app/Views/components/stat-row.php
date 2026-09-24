<?php
declare(strict_types=1);
/**
 * A row of KPI tiles.
 *
 * @var list<array{label:string,value:string,delta?:string,dir?:string,hint?:string,href?:string}> $stats
 * @var string $cols  grid class suffix, default 4
 */
$stats = $stats ?? [];
$cols  = $cols ?? '4';
?>
<div class="sl-grid sl-grid-<?= e($cols) ?> tw-mb-8">
    <?php foreach ($stats as $stat) : ?>
        <?php $tag = !empty($stat['href']) ? 'a' : 'div'; ?>
        <<?= $tag ?> class="sl-stat<?= !empty($stat['href']) ? ' tw-no-underline tw-block' : '' ?>"
            <?= !empty($stat['href']) ? 'href="' . e($stat['href']) . '"' : '' ?>>
            <p class="sl-stat-label tw-mb-0"><?= e($stat['label']) ?></p>
            <p class="sl-stat-value tw-mb-0"><?= e($stat['value']) ?></p>
            <?php if (!empty($stat['delta'])) : ?>
                <p class="sl-stat-delta sl-stat-delta-<?= e($stat['dir'] ?? 'up') ?> tw-mb-0">
                    <?= e($stat['delta']) ?>
                </p>
            <?php elseif (!empty($stat['hint'])) : ?>
                <p class="t-micro t-muted tw-mt-1 tw-mb-0"><?= e($stat['hint']) ?></p>
            <?php endif; ?>
        </<?= $tag ?>>
    <?php endforeach; ?>
</div>
