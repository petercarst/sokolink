<?php
declare(strict_types=1);
/**
 * Product listing - cinematic track.
 *
 * Shared by /products and /category/{slug}.
 *
 * All four states are reachable for review:
 *   ?state=loading   skeleton
 *   ?state=empty     empty state
 *   (default)        results
 *   a bad filter     still renders, with the empty state explaining why
 *
 * @var string                    $heading
 * @var string                    $intro
 * @var array<string,mixed>|null  $category
 * @var array<string,mixed>       $filters
 * @var array<string,mixed>       $result
 * @var string                    $demoState
 * @var list<array<string,mixed>> $categoryOptions
 * @var list<array<string,mixed>> $stores
 */
$crumbs = [['label' => 'Home', 'url' => route('home')]];

if ($category !== null) {
    $crumbs[] = ['label' => 'All products', 'url' => route('catalog.index')];
    if (!empty($category['parent'])) {
        $crumbs[] = ['label' => $category['parent']['name'], 'url' => route('catalog.category', ['slug' => $category['parent']['slug']])];
    }
    $crumbs[] = ['label' => $category['name'], 'url' => null];
} else {
    $crumbs[] = ['label' => 'All products', 'url' => null];
}

$sortOptions = [
    'relevance'  => 'Most relevant',
    'price_asc'  => 'Price: low to high',
    'price_desc' => 'Price: high to low',
    'rating'     => 'Highest rated',
    'newest'     => 'Newest first',
];

$filterArgs = [
    'filters'         => $filters,
    'categoryOptions' => $categoryOptions,
    'stores'          => $stores,
    'action'          => $category !== null
        ? route('catalog.category', ['slug' => $category['slug']])
        : route('catalog.index'),
    'lockCategory'    => $category !== null,
    'onDark'          => true,
];
?>

<section class="sl-section-tight">
    <div class="sl-container">
        <?= partial('breadcrumbs', ['crumbs' => $crumbs, 'onDark' => true]) ?>

        <h1 class="t-display-md tw-mb-4"><?= e($heading) ?></h1>
        <p class="t-body-lg t-muted-dark tw-mb-0" style="max-width:60ch"><?= e($intro) ?></p>
    </div>
</section>

<section class="tw-pb-20">
    <div class="sl-container">
        <div class="lg:tw-grid lg:tw-gap-10" style="grid-template-columns: 260px minmax(0, 1fr);">

            <!-- Filters: sidebar on desktop, offcanvas on mobile -->
            <aside class="tw-hidden lg:tw-block">
                <div class="sl-card-cinematic tw-sticky" style="top:96px">
                    <?= partial('filters', array_merge($filterArgs, ['idPrefix' => 'fd'])) ?>
                </div>
            </aside>

            <div>
                <!-- Toolbar -->
                <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-mb-6">
                    <p class="t-caption t-muted-dark tw-mb-0" role="status">
                        <?php if ($demoState === 'loading') : ?>
                            Loading products...
                        <?php else : ?>
                            <strong class="t-on-dark"><?= e((string) $result['total']) ?></strong>
                            product<?= (int) $result['total'] === 1 ? '' : 's' ?>
                            <?php if (!empty($filters['q'])) : ?>
                                matching &ldquo;<?= e($filters['q']) ?>&rdquo;
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>

                    <div class="tw-flex tw-items-center tw-gap-2">
                        <button class="sl-btn sl-btn-outline-dark sl-btn-sm lg:tw-hidden" type="button"
                                data-bs-toggle="offcanvas" data-bs-target="#filterPanel" aria-controls="filterPanel">
                            <?= component('icon', ['name' => 'filter', 'size' => 16]) ?> Filters
                        </button>

                        <form method="get" class="tw-flex tw-items-center tw-gap-2" data-sort-form>
                            <?php foreach ($filters as $key => $value) : ?>
                                <?php if ($key !== 'sort' && $value !== '' && $value !== null) : ?>
                                    <input type="hidden" name="<?= e($key) ?>" value="<?= e((string) $value) ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <label class="t-caption t-muted-dark tw-mb-0" for="sort">Sort</label>
                            <select class="sl-select sl-input-dark" id="sort" name="sort" style="width:auto" data-auto-submit>
                                <?php foreach ($sortOptions as $value => $label) : ?>
                                    <option value="<?= e($value) ?>"<?= ($filters['sort'] ?? '') === $value ? ' selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <noscript><button class="sl-btn sl-btn-outline-dark sl-btn-sm" type="submit">Apply</button></noscript>
                        </form>
                    </div>
                </div>

                <!-- States -->
                <?php if ($demoState === 'loading') : ?>
                    <?= component('skeleton-grid', ['count' => 8]) ?>

                <?php elseif ($result['items'] === []) : ?>
                    <?= component('empty-state', [
                        'icon'           => 'search',
                        'title'          => 'No products match those filters',
                        'text'           => 'Try widening the price range, clearing a filter, or browsing the full catalogue.',
                        'actionUrl'      => route('catalog.index'),
                        'actionLabel'    => 'Browse all products',
                        'secondaryUrl'   => route('home'),
                        'secondaryLabel' => 'Back to homepage',
                    ]) ?>

                <?php else : ?>
                    <div class="sl-grid sl-grid-3">
                        <?php foreach ($result['items'] as $product) : ?>
                            <?= component('product-card', ['product' => $product, 'onDark' => true]) ?>
                        <?php endforeach; ?>
                    </div>

                    <?= partial('pagination', [
                        'page'   => $result['page'],
                        'pages'  => $result['pages'],
                        'total'  => $result['total'],
                        'onDark' => true,
                    ]) ?>
                <?php endif; ?>

                <div class="tw-mt-10">
                    <?= component('devnote', [
                        'onDark' => true,
                        'text'   => 'Sample catalogue. Filtering and sorting run in PHP over a fixed dataset; '
                                  . 'Phase 3 replaces this with indexed SQL and a FULLTEXT search. '
                                  . 'Add ?state=loading or ?state=empty to this address to review those states.',
                    ]) ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Mobile filter panel -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="filterPanel" aria-labelledby="filterPanelLabel"
     style="background-color:var(--c-canvas-night);color:var(--c-on-dark)">
    <div class="offcanvas-header">
        <h2 class="t-heading-md" id="filterPanelLabel">Filters</h2>
        <button type="button" class="sl-btn sl-btn-ghost-dark sl-btn-icon" data-bs-dismiss="offcanvas"
                aria-label="Close filters">
            <?= component('icon', ['name' => 'close', 'size' => 20]) ?>
        </button>
    </div>
    <div class="offcanvas-body">
        <?= partial('filters', array_merge($filterArgs, ['idPrefix' => 'fm'])) ?>
    </div>
</div>
