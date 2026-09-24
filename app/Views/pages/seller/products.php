<?php
declare(strict_types=1);
/**
 * Seller product list.
 *
 * Shows every state, not just the published ones. A draft that has been sitting
 * unnoticed for a fortnight is the most useful row on this page, and a list
 * filtered to "live" by default would hide exactly the products that need
 * attention.
 *
 * @var list<array<string,mixed>> $products
 * @var string                    $status    the active status filter, '' for all
 * @var int                       $total
 * @var bool                      $canTrade  an approved seller; a pending one cannot publish
 */
$counts = ['draft' => 0, 'published' => 0, 'archived' => 0, 'suspended' => 0];

foreach ($products as $p) {
    $counts[$p['status']] = ($counts[$p['status']] ?? 0) + 1;
}

$rows = array_map(static fn (array $p): array => [
    'id'       => $p['id'],
    'name'     => $p['name'],
    'meta'     => trim(($p['brand'] !== '' ? $p['brand'] . ' - ' : '') . $p['pack_size']),
    'sku'      => $p['sku'],
    'category' => $p['category_name'],
    'price'    => $p['price'],
    'state'    => $p['status'],
    'stock'    => $p['stock_state'],
    'qty'      => $p['qty_available'],
    'url'      => route('seller.products.form') . '?id=' . $p['id'],
    'public'   => $p['status'] === 'published' ? route('product.show', ['slug' => $p['slug']]) : null,
], $products);

$filters = ['' => 'All', 'draft' => 'Drafts', 'published' => 'Live', 'archived' => 'Archived'];
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Products',
        'subtitle' => 'Everything you have listed. Stock is held per store and managed separately.',
        'actions'  => [
            ['label' => 'Add a product', 'url' => route('seller.products.form'), 'style' => 'sl-btn-primary', 'icon' => 'plus'],
            ['label' => 'Manage stock', 'url' => route('seller.inventory'), 'icon' => 'grain'],
        ],
    ]) ?>

    <?php if (!$canTrade) : ?>
        <div class="sl-alert sl-alert-warn tw-mb-6">
            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'clock', 'size' => 18]) ?></span>
            <span>
                <strong>Your application is still being reviewed.</strong>
                You can build your catalogue now &mdash; add products, set prices, record stock &mdash;
                but nothing can be published until an administrator approves the account.
            </span>
        </div>
    <?php endif; ?>

    <?= component('stat-row', ['cols' => '4', 'stats' => [
        ['label' => 'Live',      'value' => (string) $counts['published'], 'hint' => 'Buyable right now'],
        ['label' => 'Drafts',    'value' => (string) $counts['draft'],     'hint' => 'Not yet published'],
        ['label' => 'In stock',  'value' => (string) count(array_filter($products, static fn (array $p): bool => $p['stock_state'] === 'in')), 'hint' => 'Across your stores'],
        ['label' => 'Out of stock', 'value' => (string) count(array_filter($products, static fn (array $p): bool => $p['stock_state'] === 'out')), 'hint' => 'Nothing left to sell'],
    ]]) ?>

    <nav class="tw-flex tw-gap-2 tw-flex-wrap tw-mb-5" aria-label="Filter products by state">
        <?php foreach ($filters as $key => $label) : ?>
            <a class="sl-chip <?= $status === $key ? 'is-active' : '' ?>"
               href="<?= e(route('seller.products')) ?><?= $key === '' ? '' : '?status=' . e($key) ?>">
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?= component('data-table', [
        'caption' => 'Your products',
        'columns' => [
            ['key' => 'name',     'label' => 'Product',  'href' => 'url', 'sub' => 'meta'],
            ['key' => 'sku',      'label' => 'SKU',      'type' => 'code'],
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'state',    'label' => 'State',    'type' => 'badge'],
            ['key' => 'stock',    'label' => 'Stock',    'type' => 'badge'],
            ['key' => 'qty',      'label' => 'Available', 'type' => 'number', 'align' => 'right'],
            ['key' => 'price',    'label' => 'Price',    'type' => 'money', 'align' => 'right'],
            ['type' => 'actions', 'label' => '', 'actions' => [
                ['label' => 'Edit', 'href' => static fn (array $r): string => $r['url']],
                ['label' => 'View', 'style' => 'sl-btn-ghost',
                 'href' => static fn (array $r): ?string => $r['public']],
            ]],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'basket', 'title' => 'No products listed yet',
            'text' => 'Add your first product and set how much of it each store holds.',
            'actionUrl' => route('seller.products.form'), 'actionLabel' => 'Add a product',
        ],
    ]) ?>

    <p class="t-caption t-muted tw-mt-4">
        <?= e((string) $total) ?> product<?= $total === 1 ? '' : 's' ?> in total.
        Publishing and archiving are on each product's own page, because both have
        conditions: a product needs stock somewhere before it can be published, and
        one with units already promised to orders cannot be archived out from under them.
    </p>
</div>
