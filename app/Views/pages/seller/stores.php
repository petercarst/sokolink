<?php
declare(strict_types=1);
/**
 * Seller store list.
 *
 * @var list<array<string,mixed>> $stores
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Stores',
        'subtitle' => 'Your collection points. Each one holds its own stock and its own opening hours.',
        'actions'  => [['label' => 'Add a store', 'feature' => 'store_add', 'style' => 'sl-btn-primary', 'icon' => 'plus']],
    ]) ?>

    <div class="sl-grid sl-grid-2">
        <?php foreach ($stores as $store) : ?>
            <article class="sl-card sl-card-raised">
                <div class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-4">
                    <div class="tw-flex tw-items-center tw-gap-3">
                        <span class="sl-avatar"><?= e(initials($store['name'])) ?></span>
                        <div>
                            <h2 class="t-heading-md tw-mb-0"><?= e($store['name']) ?></h2>
                            <p class="t-micro t-muted tw-mb-0"><?= e($store['district']) ?>, <?= e($store['region']) ?></p>
                        </div>
                    </div>
                    <?= component('badge', [
                        'status' => $store['is_open_now'] ? 'active' : 'closed',
                        'label'  => $store['is_open_now'] ? 'Open now' : 'Closed now',
                    ]) ?>
                </div>

                <?= component('detail-list', ['items' => [
                    ['label' => 'Address', 'value' => $store['street']],
                    ['label' => 'Products stocked', 'value' => (string) $store['product_count']],
                    ['label' => 'Rating', 'value' => number_format((float) $store['rating'], 1) . ' from ' . $store['review_count'] . ' reviews'],
                    ['label' => 'Offers', 'value' => implode(', ', array_map(
                        static fn (string $m): string => $m === 'delivery' ? 'Home delivery' : 'Click and collect',
                        $store['accepts']
                    ))],
                ]]) ?>

                <div class="tw-flex tw-gap-2 tw-flex-wrap tw-mt-5">
                    <a class="sl-btn sl-btn-outline-light sl-btn-sm"
                       href="<?= e(route('seller.stores.edit', ['slug' => $store['slug']])) ?>">Edit store</a>
                    <a class="sl-btn sl-btn-ghost sl-btn-sm"
                       href="<?= e(route('store.show', ['slug' => $store['slug']])) ?>">
                        View public page <?= component('icon', ['name' => 'external', 'size' => 14]) ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
