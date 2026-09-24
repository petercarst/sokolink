<?php
declare(strict_types=1);
/**
 * Catalogue filter panel.
 *
 * A plain GET form. Filters are therefore bookmarkable, shareable, survive the
 * back button and work with JavaScript disabled. filters.js only adds
 * auto-submit convenience on top of behaviour that already works without it.
 *
 * @var array<string,mixed>                            $filters
 * @var list<array{slug:string,name:string,depth:int}> $categoryOptions
 * @var list<array<string,mixed>>                      $stores
 * @var string                                         $action
 * @var bool   $lockCategory  true on a category page
 * @var bool   $onDark        render for the cinematic track
 * @var string $idPrefix      REQUIRED when the panel appears more than once on a
 *        page (desktop sidebar plus mobile offcanvas). Duplicate element ids
 *        break label association, so every instance gets its own prefix.
 */
$filters         = $filters ?? [];
$categoryOptions = $categoryOptions ?? [];
$stores          = $stores ?? [];
$action          = $action ?? route('catalog.index');
$lockCategory    = $lockCategory ?? false;
$onDark          = $onDark ?? false;
$idPrefix        = $idPrefix ?? 'f';

$inputCls  = 'sl-input'  . ($onDark ? ' sl-input-dark' : '');
$selectCls = 'sl-select' . ($onDark ? ' sl-input-dark' : '');
$submitCls = $onDark ? 'sl-btn-outline-dark' : 'sl-btn-primary';

$sellers = [];
foreach ($stores as $store) {
    $sellers[$store['seller_slug']] = $store['seller_name'];
}

$activeCount = count(array_filter([
    $lockCategory ? '' : ($filters['category'] ?? ''),
    $filters['seller'] ?? '', $filters['min'] ?? '', $filters['max'] ?? '',
    $filters['stock'] ?? '', $filters['fulfilment'] ?? '', $filters['rating'] ?? '',
], static fn ($v): bool => $v !== '' && $v !== null));
?>
<form method="get" action="<?= e($action) ?>" data-filter-form>
    <?php if (!empty($filters['q'])) : ?>
        <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
    <?php endif; ?>
    <?php if ($lockCategory) : ?>
        <input type="hidden" name="category" value="<?= e((string) ($filters['category'] ?? '')) ?>">
    <?php endif; ?>

    <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-4">
        <h2 class="t-heading-md tw-mb-0">Filters</h2>
        <?php if ($activeCount > 0) : ?>
            <a class="t-micro sl-link-quiet <?= $onDark ? 't-muted-dark' : '' ?>" href="<?= e($action) ?>">
                Clear all (<?= e((string) $activeCount) ?>)
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$lockCategory) : ?>
        <div class="sl-field">
            <label class="sl-label <?= $onDark ? 't-on-dark' : '' ?>" for="<?= e($idPrefix) ?>-category">Category</label>
            <select class="<?= e($selectCls) ?>" id="<?= e($idPrefix) ?>-category" name="category">
                <option value="">All categories</option>
                <?php foreach ($categoryOptions as $option) : ?>
                    <option value="<?= e($option['slug']) ?>"<?= ($filters['category'] ?? '') === $option['slug'] ? ' selected' : '' ?>>
                        <?= $option['depth'] > 0 ? '&mdash; ' : '' ?><?= e($option['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <div class="sl-field">
        <label class="sl-label <?= $onDark ? 't-on-dark' : '' ?>" for="<?= e($idPrefix) ?>-seller">Seller</label>
        <select class="<?= e($selectCls) ?>" id="<?= e($idPrefix) ?>-seller" name="seller">
            <option value="">All sellers</option>
            <?php foreach ($sellers as $slug => $name) : ?>
                <option value="<?= e($slug) ?>"<?= ($filters['seller'] ?? '') === $slug ? ' selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <fieldset class="sl-field tw-border-0 tw-p-0 tw-m-0">
        <legend class="sl-label <?= $onDark ? 't-on-dark' : '' ?>">
            Price range (<?= e((string) config('app.currency_symbol')) ?>)
        </legend>
        <div class="tw-flex tw-items-center tw-gap-2">
            <label class="visually-hidden" for="<?= e($idPrefix) ?>-min">Minimum price</label>
            <input class="<?= e($inputCls) ?> tabular" type="number" id="<?= e($idPrefix) ?>-min" name="min"
                   min="0" step="100" placeholder="Min" value="<?= e((string) ($filters['min'] ?? '')) ?>">
            <span class="<?= $onDark ? 't-muted-dark' : 't-muted' ?>" aria-hidden="true">&ndash;</span>
            <label class="visually-hidden" for="<?= e($idPrefix) ?>-max">Maximum price</label>
            <input class="<?= e($inputCls) ?> tabular" type="number" id="<?= e($idPrefix) ?>-max" name="max"
                   min="0" step="100" placeholder="Max" value="<?= e((string) ($filters['max'] ?? '')) ?>">
        </div>
    </fieldset>

    <fieldset class="sl-field tw-border-0 tw-p-0 tw-m-0">
        <legend class="sl-label <?= $onDark ? 't-on-dark' : '' ?>">How you receive it</legend>
        <?php foreach (['' => 'Either', 'pickup' => 'Click and collect', 'delivery' => 'Home delivery'] as $value => $label) : ?>
            <label class="sl-check">
                <input type="radio" name="fulfilment" value="<?= e((string) $value) ?>"
                       <?= (string) ($filters['fulfilment'] ?? '') === (string) $value ? 'checked' : '' ?>>
                <span class="sl-check-label <?= $onDark ? 't-on-dark' : '' ?>"><?= e($label) ?></span>
            </label>
        <?php endforeach; ?>
    </fieldset>

    <fieldset class="sl-field tw-border-0 tw-p-0 tw-m-0">
        <legend class="sl-label <?= $onDark ? 't-on-dark' : '' ?>">Minimum rating</legend>
        <?php foreach (['' => 'Any rating', '4' => '4 stars and above', '4.5' => '4.5 stars and above'] as $value => $label) : ?>
            <label class="sl-check">
                <input type="radio" name="rating" value="<?= e((string) $value) ?>"
                       <?= (string) ($filters['rating'] ?? '') === (string) $value ? 'checked' : '' ?>>
                <span class="sl-check-label <?= $onDark ? 't-on-dark' : '' ?>"><?= e($label) ?></span>
            </label>
        <?php endforeach; ?>
    </fieldset>

    <div class="sl-field">
        <label class="sl-check">
            <input type="checkbox" name="stock" value="in" <?= ($filters['stock'] ?? '') === 'in' ? 'checked' : '' ?>>
            <span class="sl-check-label <?= $onDark ? 't-on-dark' : '' ?>">In stock only</span>
        </label>
    </div>

    <input type="hidden" name="sort" value="<?= e((string) ($filters['sort'] ?? 'relevance')) ?>">

    <button type="submit" class="sl-btn <?= e($submitCls) ?> sl-btn-block">Apply filters</button>
</form>
