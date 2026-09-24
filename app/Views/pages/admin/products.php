<?php
declare(strict_types=1);
/**
 * Product moderation.
 *
 * Admins can unpublish or flag any product with a reason (FR-CAT-10). They do
 * not edit a seller's listing - the seller owns their content, and silently
 * rewriting it would make the seller responsible for words they did not write.
 * Unpublish with a reason, and let them fix it.
 *
 * @var list<array<string,mixed>> $products
 */
$rows = array_map(static fn (array $p): array => [
    'name'     => $p['name'],
    'meta'     => $p['brand'] . ' - ' . $p['pack_size'],
    'seller'   => $p['seller_name'],
    'category' => $p['category_name'],
    'price'    => $p['price'],
    'rating'   => number_format((float) $p['rating'], 1),
    'stock'    => $p['stock_state'],
    'url'      => route('product.show', ['slug' => $p['slug']]),
], $products);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Product moderation',
        'subtitle' => 'Every listing on the marketplace. You can unpublish with a reason; you cannot rewrite a seller\'s words.',
    ]) ?>

    <?= component('stat-row', ['cols' => '4', 'stats' => [
        ['label' => 'Listed', 'value' => (string) count($products), 'hint' => 'Across all sellers'],
        ['label' => 'Out of stock', 'value' => (string) count(array_filter($products, static fn (array $p): bool => $p['stock_state'] === 'out')), 'hint' => 'Hidden from browsing'],
        ['label' => 'Reported', 'value' => '1', 'hint' => 'Flagged by a customer or seller'],
        ['label' => 'Average rating', 'value' => number_format(array_sum(array_column($products, 'rating')) / max(1, count($products)), 1), 'hint' => 'Marketplace-wide'],
    ]]) ?>

    <div class="sl-alert sl-alert-warn tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
        <span>
            <strong>One product has been reported</strong> and is waiting for a decision. Reported
            listings stay visible until you act, so that reporting cannot be used as a way to take a
            competitor down.
        </span>
    </div>

    <?= component('data-table', [
        'caption' => 'Products for moderation',
        'columns' => [
            ['key' => 'name',     'label' => 'Product',  'href' => 'url', 'sub' => 'meta'],
            ['key' => 'seller',   'label' => 'Seller'],
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'rating',   'label' => 'Rating',   'align' => 'right'],
            ['key' => 'stock',    'label' => 'Stock',    'type' => 'badge'],
            ['key' => 'price',    'label' => 'Price',    'type' => 'money', 'align' => 'right'],
            ['type' => 'actions', 'label' => '', 'actions' => [
                ['label' => 'View', 'href' => static fn (array $r): string => $r['url'], 'style' => 'sl-btn-ghost'],
                ['label' => 'Unpublish', 'feature' => 'product_unpublish', 'style' => 'sl-btn-danger'],
            ]],
        ],
        'rows'  => $rows,
        'empty' => ['icon' => 'basket', 'title' => 'No products listed', 'text' => 'Listings appear once approved sellers publish them.'],
    ]) ?>

    <section class="sl-card tw-mt-8">
        <h2 class="t-heading-xl tw-mb-2">Unpublishing a listing</h2>
        <p class="t-caption t-muted tw-mb-5">
            The seller is told the reason and can fix the listing and republish. Existing orders for
            the product are unaffected &mdash; unpublishing hides it from browsing, it does not
            cancel business already done.
        </p>

        <form method="post" action="<?= e(route('preview.submit')) ?>" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="feature" value="product_unpublish">

            <?= component('field', [
                'name' => 'moderation_reason', 'label' => 'Reason', 'type' => 'select', 'required' => true,
                'options' => [
                    ''               => 'Choose a reason',
                    'prohibited'     => 'Prohibited or unlawful item',
                    'misleading'     => 'Description does not match the product',
                    'safety'         => 'Safety or labelling concern',
                    'wrong_category' => 'Listed in the wrong category',
                    'image'          => 'Image is not of the product',
                    'other'          => 'Something else',
                ],
            ]) ?>

            <?= component('field', [
                'name' => 'moderation_note', 'label' => 'Note to the seller', 'type' => 'textarea', 'required' => true,
                'help' => 'Required. Tell them what to change so they can fix it and republish.',
            ]) ?>

            <button class="sl-btn sl-btn-danger" type="submit">Unpublish the listing</button>
        </form>
    </section>
</div>
