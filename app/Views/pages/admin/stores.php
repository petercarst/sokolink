<?php
declare(strict_types=1);
/**
 * Store management.
 *
 * @var list<array<string,mixed>> $stores
 */
$rows = array_map(static fn (array $s): array => [
    'name'     => $s['name'],
    'location' => $s['district'] . ', ' . $s['region'],
    'seller'   => $s['seller_name'],
    'products' => $s['product_count'],
    'rating'   => number_format((float) $s['rating'], 1),
    'accepts'  => array_map(
        static fn (string $m): string => $m === 'delivery' ? 'Delivery' : 'Collect',
        $s['accepts']
    ),
    'open'     => $s['is_open_now'] ? 'active' : 'closed',
    'url'      => route('store.show', ['slug' => $s['slug']]),
], $stores);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Stores',
        'subtitle' => 'Every collection point on the platform.',
    ]) ?>

    <?= component('stat-row', ['cols' => '3', 'stats' => [
        ['label' => 'Stores', 'value' => (string) count($stores), 'hint' => 'Across all sellers'],
        ['label' => 'Open now', 'value' => (string) count(array_filter($stores, static fn (array $s): bool => $s['is_open_now'])), 'hint' => 'Accepting collections'],
        ['label' => 'Offering delivery', 'value' => (string) count(array_filter($stores, static fn (array $s): bool => in_array('delivery', $s['accepts'], true))), 'hint' => 'Agents collect from these'],
    ]]) ?>

    <?= component('data-table', [
        'caption' => 'Stores on the platform',
        'columns' => [
            ['key' => 'name',     'label' => 'Store',   'href' => 'url', 'sub' => 'location'],
            ['key' => 'seller',   'label' => 'Seller'],
            ['key' => 'products', 'label' => 'Products', 'type' => 'number', 'align' => 'right'],
            ['key' => 'rating',   'label' => 'Rating',   'align' => 'right'],
            ['key' => 'accepts',  'label' => 'Offers',   'type' => 'chips'],
            ['key' => 'open',     'label' => 'Right now', 'type' => 'badge'],
            ['type' => 'actions', 'label' => '', 'actions' => [
                ['label' => 'Suspend', 'feature' => 'store_suspend', 'style' => 'sl-btn-ghost'],
            ]],
        ],
        'rows'  => $rows,
        'empty' => ['icon' => 'store', 'title' => 'No stores yet', 'text' => 'Stores appear once a seller is approved and creates one.'],
    ]) ?>
</div>
