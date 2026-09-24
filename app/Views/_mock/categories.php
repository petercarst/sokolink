<?php

declare(strict_types=1);

/* =============================================================================
 *  MOCK DATA - PHASE 1 ONLY
 * -----------------------------------------------------------------------------
 *  Replaced in Phase 4 by App\Repositories\CategoryRepository::tree()
 *
 *  CONTRACT the real query must satisfy - one row per category:
 *    id                int
 *    parent_id         int|null
 *    slug              string   unique, URL segment
 *    name              string
 *    icon              string   short key used by components/icon.php
 *    product_count     int      published products with stock, across all stores
 *    children          list<self>
 *
 *  Source tables (Phase 2): categories
 *  Max depth 3 (FR-CAT-01).
 * ===========================================================================*/

return [
    [
        'id' => 1, 'parent_id' => null, 'slug' => 'food-cupboard', 'name' => 'Food Cupboard',
        'icon' => 'basket', 'product_count' => 148,
        'children' => [
            ['id' => 11, 'parent_id' => 1, 'slug' => 'cooking-oil', 'name' => 'Cooking Oil & Fats', 'icon' => 'bottle', 'product_count' => 24, 'children' => []],
            ['id' => 12, 'parent_id' => 1, 'slug' => 'rice-grains', 'name' => 'Rice & Grains',      'icon' => 'grain',  'product_count' => 31, 'children' => []],
            ['id' => 13, 'parent_id' => 1, 'slug' => 'flour',       'name' => 'Flour & Baking',     'icon' => 'grain',  'product_count' => 22, 'children' => []],
            ['id' => 14, 'parent_id' => 1, 'slug' => 'sugar-salt',  'name' => 'Sugar, Salt & Spices', 'icon' => 'spice', 'product_count' => 40, 'children' => []],
            ['id' => 15, 'parent_id' => 1, 'slug' => 'tea-coffee',  'name' => 'Tea & Coffee',       'icon' => 'cup',    'product_count' => 31, 'children' => []],
        ],
    ],
    [
        'id' => 2, 'parent_id' => null, 'slug' => 'household', 'name' => 'Household & Cleaning',
        'icon' => 'home', 'product_count' => 86,
        'children' => [
            ['id' => 21, 'parent_id' => 2, 'slug' => 'laundry',   'name' => 'Laundry',           'icon' => 'shirt', 'product_count' => 28, 'children' => []],
            ['id' => 22, 'parent_id' => 2, 'slug' => 'cleaning',  'name' => 'Surface Cleaning',  'icon' => 'spray', 'product_count' => 34, 'children' => []],
            ['id' => 23, 'parent_id' => 2, 'slug' => 'paper',     'name' => 'Paper & Disposables', 'icon' => 'roll', 'product_count' => 24, 'children' => []],
        ],
    ],
    [
        'id' => 3, 'parent_id' => null, 'slug' => 'personal-care', 'name' => 'Personal Care',
        'icon' => 'heart', 'product_count' => 72,
        'children' => [
            ['id' => 31, 'parent_id' => 3, 'slug' => 'soap-bath', 'name' => 'Soap & Bath',   'icon' => 'drop',  'product_count' => 30, 'children' => []],
            ['id' => 32, 'parent_id' => 3, 'slug' => 'hair-care', 'name' => 'Hair Care',     'icon' => 'drop',  'product_count' => 21, 'children' => []],
            ['id' => 33, 'parent_id' => 3, 'slug' => 'oral-care', 'name' => 'Oral Care',     'icon' => 'smile', 'product_count' => 21, 'children' => []],
        ],
    ],
    [
        'id' => 4, 'parent_id' => null, 'slug' => 'fresh', 'name' => 'Fresh Produce',
        'icon' => 'leaf', 'product_count' => 64,
        'children' => [
            ['id' => 41, 'parent_id' => 4, 'slug' => 'vegetables', 'name' => 'Vegetables', 'icon' => 'leaf', 'product_count' => 26, 'children' => []],
            ['id' => 42, 'parent_id' => 4, 'slug' => 'fruit',      'name' => 'Fruit',      'icon' => 'leaf', 'product_count' => 22, 'children' => []],
            ['id' => 43, 'parent_id' => 4, 'slug' => 'dairy-eggs', 'name' => 'Dairy & Eggs', 'icon' => 'egg', 'product_count' => 16, 'children' => []],
        ],
    ],
    [
        'id' => 5, 'parent_id' => null, 'slug' => 'baby', 'name' => 'Baby & Child',
        'icon' => 'baby', 'product_count' => 38,
        'children' => [
            ['id' => 51, 'parent_id' => 5, 'slug' => 'nappies',    'name' => 'Nappies & Wipes', 'icon' => 'baby', 'product_count' => 18, 'children' => []],
            ['id' => 52, 'parent_id' => 5, 'slug' => 'baby-food',  'name' => 'Baby Food',       'icon' => 'bowl', 'product_count' => 20, 'children' => []],
        ],
    ],
    [
        'id' => 6, 'parent_id' => null, 'slug' => 'beverages', 'name' => 'Drinks',
        'icon' => 'cup', 'product_count' => 54,
        'children' => [
            ['id' => 61, 'parent_id' => 6, 'slug' => 'water',      'name' => 'Water',         'icon' => 'drop', 'product_count' => 14, 'children' => []],
            ['id' => 62, 'parent_id' => 6, 'slug' => 'soft-drinks', 'name' => 'Soft Drinks',  'icon' => 'cup',  'product_count' => 26, 'children' => []],
            ['id' => 63, 'parent_id' => 6, 'slug' => 'juice',      'name' => 'Juice',         'icon' => 'cup',  'product_count' => 14, 'children' => []],
        ],
    ],
];
