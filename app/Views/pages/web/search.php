<?php
declare(strict_types=1);
/**
 * Search results - cinematic track.
 *
 * A search with no results is a dead end unless it offers a way forward, so
 * this page always shows suggestions rather than just "nothing found"
 * (USER_FLOWS.md Flow D).
 *
 * @var string                    $query
 * @var array<string,mixed>       $filters
 * @var array<string,mixed>       $result
 * @var list<array<string,mixed>> $suggestions
 * @var list<array<string,mixed>> $categoryOptions
 * @var list<array<string,mixed>> $stores
 */
?>

<section class="sl-section-tight">
    <div class="sl-container">
        <?= partial('breadcrumbs', [
            'crumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Search', 'url' => null],
            ],
            'onDark' => true,
        ]) ?>

        <h1 class="t-display-md tw-mb-6">
            <?php if ($query === '') : ?>
                Search
            <?php else : ?>
                Results for &ldquo;<?= e($query) ?>&rdquo;
            <?php endif; ?>
        </h1>

        <form method="get" action="<?= e(route('search')) ?>" role="search"
              class="tw-flex tw-flex-wrap tw-gap-3" style="max-width:40rem">
            <label class="visually-hidden" for="search-q">Search products</label>
            <input class="sl-input sl-input-dark tw-flex-1" style="border-radius:var(--r-pill);min-width:14rem"
                   type="search" id="search-q" name="q" value="<?= e($query) ?>"
                   placeholder="Cooking oil, rice, nappies..." autofocus>
            <button class="sl-btn sl-btn-outline-dark" type="submit">
                <?= component('icon', ['name' => 'search', 'size' => 18]) ?> Search
            </button>
        </form>
    </div>
</section>

<section class="tw-pb-20">
    <div class="sl-container">
        <div class="lg:tw-grid lg:tw-gap-10" style="grid-template-columns: 260px minmax(0, 1fr);">

            <aside class="tw-hidden lg:tw-block">
                <div class="sl-card-cinematic tw-sticky" style="top:96px">
                    <?= partial('filters', [
                        'filters'         => $filters,
                        'categoryOptions' => $categoryOptions,
                        'stores'          => $stores,
                        'action'          => route('search'),
                        'onDark'          => true,
                        'idPrefix'        => 'sd',
                    ]) ?>
                </div>
            </aside>

            <div>
                <?php if ($query === '') : ?>
                    <?= component('empty-state', [
                        'icon'  => 'search',
                        'title' => 'What are you looking for?',
                        'text'  => 'Search by product name, brand or category. You can narrow the results with the filters.',
                    ]) ?>

                    <h2 class="t-heading-xl tw-mt-12 tw-mb-6">Popular right now</h2>
                    <div class="sl-grid sl-grid-3">
                        <?php foreach ($suggestions as $product) : ?>
                            <?= component('product-card', ['product' => $product, 'onDark' => true]) ?>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($result['items'] === []) : ?>
                    <?= component('empty-state', [
                        'icon'           => 'search',
                        'title'          => 'Nothing matched that search',
                        'text'           => 'Check the spelling, try a shorter or more general word, or browse by category instead.',
                        'actionUrl'      => route('catalog.index'),
                        'actionLabel'    => 'Browse all products',
                    ]) ?>

                    <h2 class="t-heading-xl tw-mt-12 tw-mb-6">You might be looking for</h2>
                    <div class="sl-grid sl-grid-3">
                        <?php foreach ($suggestions as $product) : ?>
                            <?= component('product-card', ['product' => $product, 'onDark' => true]) ?>
                        <?php endforeach; ?>
                    </div>

                <?php else : ?>
                    <p class="t-caption t-muted-dark tw-mb-6" role="status">
                        <strong class="t-on-dark"><?= e((string) $result['total']) ?></strong>
                        product<?= (int) $result['total'] === 1 ? '' : 's' ?> found
                    </p>

                    <div class="sl-grid sl-grid-3">
                        <?php foreach ($result['items'] as $product) : ?>
                            <?= component('product-card', ['product' => $product, 'onDark' => true]) ?>
                        <?php endforeach; ?>
                    </div>

                    <?= partial('pagination', [
                        'page' => $result['page'], 'pages' => $result['pages'],
                        'total' => $result['total'], 'onDark' => true,
                    ]) ?>
                <?php endif; ?>

                <div class="tw-mt-10">
                    <?= component('devnote', [
                        'onDark' => true,
                        'text'   => 'Search currently does a substring match in PHP over the sample catalogue. '
                                  . 'Phase 3 replaces it with a MySQL FULLTEXT index and a LIKE fallback (FR-CAT-06).',
                    ]) ?>
                </div>
            </div>
        </div>
    </div>
</section>
