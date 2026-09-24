<?php
declare(strict_types=1);
/**
 * Store summary card.
 *
 * @var array<string,mixed> $store   shape defined in app/Views/_mock/stores.php
 * @var bool                $onDark
 */
$onDark = $onDark ?? false;
?>
<a class="<?= $onDark ? 'sl-card-cinematic' : 'sl-card sl-card-tight' ?> tw-block tw-h-full tw-no-underline"
   href="<?= e(route('store.show', ['slug' => $store['slug']])) ?>">

    <div class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-3">
        <span class="sl-avatar"><?= e(initials($store['name'])) ?></span>
        <?= component('badge', [
            'status' => $store['is_open_now'] ? 'active' : 'closed',
            'label'  => $store['is_open_now'] ? 'Open now' : 'Closed',
            'onDark' => $onDark,
        ]) ?>
    </div>

    <h3 class="t-heading-md tw-mb-1"><?= e($store['name']) ?></h3>

    <p class="t-caption <?= $onDark ? 't-muted-dark' : 't-muted' ?> tw-mb-3 tw-flex tw-items-start tw-gap-1">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'map-pin', 'size' => 14]) ?></span>
        <span><?= e($store['street']) ?>, <?= e($store['district']) ?>, <?= e($store['region']) ?></span>
    </p>

    <div class="tw-flex tw-items-center tw-gap-3 tw-flex-wrap">
        <?= component('rating', ['rating' => $store['rating'], 'count' => $store['review_count']]) ?>
        <span class="t-micro <?= $onDark ? 't-muted-dark' : 't-muted' ?>"><?= e((string) $store['product_count']) ?> products</span>
    </div>

    <div class="tw-flex tw-gap-2 tw-mt-3 tw-flex-wrap">
        <?php foreach ($store['accepts'] as $method) : ?>
            <span class="sl-chip <?= $onDark ? 'sl-chip-dark' : '' ?>">
                <?= component('icon', ['name' => $method === 'delivery' ? 'truck' : 'package', 'size' => 13]) ?>
                <?= e($method === 'delivery' ? 'Delivery' : 'Collect') ?>
            </span>
        <?php endforeach; ?>
    </div>
</a>
