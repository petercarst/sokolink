<?php
declare(strict_types=1);
/**
 * Category management.
 *
 * Max depth 3 (FR-CAT-01). Sellers pick a leaf category; the tree drives search,
 * filtering and the reorder engine's category defaults.
 *
 * @var list<array<string,mixed>> $categories
 */
$totalProducts = array_sum(array_column($categories, 'product_count'));
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Categories',
        'subtitle' => 'The tree that drives browsing, filtering and search. Maximum three levels deep.',
        'actions'  => [['label' => 'Add a top-level category', 'feature' => 'category_add', 'style' => 'sl-btn-primary', 'icon' => 'plus']],
    ]) ?>

    <?= component('stat-row', ['cols' => '3', 'stats' => [
        ['label' => 'Top level', 'value' => (string) count($categories), 'hint' => 'Main sections'],
        ['label' => 'Sub-categories', 'value' => (string) array_sum(array_map(static fn (array $c): int => count($c['children']), $categories)), 'hint' => 'What sellers actually pick'],
        ['label' => 'Products classified', 'value' => (string) $totalProducts, 'hint' => 'Across the marketplace'],
    ]]) ?>

    <div class="tw-flex tw-flex-col tw-gap-4">
        <?php foreach ($categories as $category) : ?>
            <section class="sl-card">
                <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-mb-4">
                    <div class="tw-flex tw-items-center tw-gap-3">
                        <span style="color:var(--c-shade-60)"><?= component('icon', ['name' => $category['icon'], 'size' => 22]) ?></span>
                        <div>
                            <h2 class="t-heading-md tw-mb-0"><?= e($category['name']) ?></h2>
                            <p class="t-micro t-muted tw-mb-0">
                                <span class="t-code"><?= e($category['slug']) ?></span>
                                &middot; <?= e((string) $category['product_count']) ?> products
                            </p>
                        </div>
                    </div>

                    <div class="tw-flex tw-gap-2 tw-flex-wrap">
                        <a class="sl-btn sl-btn-ghost sl-btn-sm"
                           href="<?= e(route('catalog.category', ['slug' => $category['slug']])) ?>">
                            View <?= component('icon', ['name' => 'external', 'size' => 14]) ?>
                        </a>
                        <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="feature" value="category_edit">
                            <button class="sl-btn sl-btn-outline-light sl-btn-sm" type="submit">Edit</button>
                        </form>
                    </div>
                </div>

                <div class="tw-flex tw-flex-wrap tw-gap-2">
                    <?php foreach ($category['children'] as $child) : ?>
                        <a class="sl-chip" href="<?= e(route('catalog.category', ['slug' => $child['slug']])) ?>">
                            <?= e($child['name']) ?>
                            <span class="t-micro tw-ml-1">(<?= e((string) $child['product_count']) ?>)</span>
                        </a>
                    <?php endforeach; ?>

                    <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="feature" value="category_add">
                        <button class="sl-chip" type="submit" style="border:1px dashed var(--c-shade-40);background:transparent">
                            <?= component('icon', ['name' => 'plus', 'size' => 12]) ?> Add sub-category
                        </button>
                    </form>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="sl-card sl-card-band tw-mt-8">
        <h2 class="t-heading-md tw-mb-3">Before you delete a category</h2>
        <p class="t-caption tw-mb-0">
            A category with products cannot simply be removed &mdash; those products would become
            unfindable. Move them first, or merge the category into another, which reassigns them in
            one transaction. Both actions are audited.
        </p>
    </div>
</div>
