<?php

declare(strict_types=1);

/* =============================================================================
 *  MOCK DATA - PHASE 1 ONLY
 * -----------------------------------------------------------------------------
 *  Replaced in Phase 4 by:
 *    App\Repositories\CartRepository::currentFor(?int $userId, string $cookieId)
 *    App\Services\Cart\CartPricingService::priceCart(Cart $cart)
 *
 *  IMPORTANT - the totals below are illustrative only. In the real system every
 *  one of them is recomputed server-side from database prices at checkout, and
 *  any figure posted by the browser is discarded (FR-CART-03, FR-CART-08).
 *
 *  CONTRACT:
 *    groups[]                      one per seller - becomes a sub-order
 *      seller_id / seller_name / seller_slug
 *      fulfilment_options          list<string>
 *      selected_fulfilment         'pickup'|'delivery'
 *      selected_store_id           int|null
 *      available_stores            list<{id,name,district,stocks_all_lines:bool}>
 *      items[]
 *        product_id, slug, name, brand, pack_size, unit, tone
 *        unit_price                string DECIMAL
 *        qty                       int
 *        line_total                string DECIMAL
 *        qty_available             int
 *        stock_state               'in'|'low'|'out'
 *        price_changed_from        string|null  set when the price moved since
 *                                               the item was added (FR-CART-10)
 *      subtotal                    string
 *      delivery_fee                string  '0.00' for pickup
 *    totals: items_subtotal, delivery_total, discount_total, grand_total
 *    item_count                    int
 *    currency                      string
 * ===========================================================================*/

return [
    'item_count' => 4,
    'currency'   => 'TZS',
    'groups' => [
        [
            'seller_id' => 1, 'seller_name' => 'Mama Lishe Provisions', 'seller_slug' => 'mama-lishe',
            'fulfilment_options'  => ['pickup', 'delivery'],
            'selected_fulfilment' => 'pickup',
            'selected_store_id'   => 1,
            'available_stores' => [
                ['id' => 1, 'name' => 'Mama Lishe - Kariakoo',    'district' => 'Ilala',      'stocks_all_lines' => true],
                ['id' => 2, 'name' => 'Mama Lishe - Mbezi Beach', 'district' => 'Kinondoni',  'stocks_all_lines' => false],
            ],
            'items' => [
                [
                    'product_id' => 101, 'slug' => 'alizeti-sunflower-oil-5l',
                    'name' => 'Alizeti Pure Sunflower Cooking Oil', 'brand' => 'Alizeti',
                    'pack_size' => '5 L', 'unit' => 'jerrycan', 'tone' => 'amber',
                    'unit_price' => '28500.00', 'qty' => 1, 'line_total' => '28500.00',
                    'qty_available' => 64, 'stock_state' => 'in', 'price_changed_from' => null,
                ],
                [
                    'product_id' => 105, 'slug' => 'chai-bora-loose-leaf-500g',
                    'name' => 'Chai Bora Loose Leaf Tea', 'brand' => 'Chai Bora',
                    'pack_size' => '500 g', 'unit' => 'pack', 'tone' => 'forest',
                    'unit_price' => '6200.00', 'qty' => 2, 'line_total' => '12400.00',
                    'qty_available' => 52, 'stock_state' => 'in', 'price_changed_from' => '5900.00',
                ],
            ],
            // The fee that WOULD apply if this group switched to delivery.
            // Only counted in the totals when delivery is the selected method.
            'subtotal' => '40900.00', 'delivery_fee' => '5000.00',
        ],
        [
            'seller_id' => 2, 'seller_name' => 'Duka Kuu Wholesalers', 'seller_slug' => 'duka-kuu',
            'fulfilment_options'  => ['pickup', 'delivery'],
            'selected_fulfilment' => 'delivery',
            'selected_store_id'   => 3,
            'available_stores' => [
                ['id' => 3, 'name' => 'Duka Kuu - Arusha Central', 'district' => 'Arusha City', 'stocks_all_lines' => true],
            ],
            'items' => [
                [
                    'product_id' => 115, 'slug' => 'mtoto-nappies-size4-50pk',
                    'name' => 'Mtoto Dry Nappies Size 4', 'brand' => 'Mtoto',
                    'pack_size' => '50 nappies', 'unit' => 'pack', 'tone' => 'sky',
                    'unit_price' => '34000.00', 'qty' => 1, 'line_total' => '34000.00',
                    'qty_available' => 26, 'stock_state' => 'in', 'price_changed_from' => null,
                ],
            ],
            'subtotal' => '34000.00', 'delivery_fee' => '6000.00',
        ],
    ],
    'totals' => [
        'items_subtotal' => '74900.00',
        'delivery_total' => '6000.00',
        'discount_total' => '0.00',
        'grand_total'    => '80900.00',
    ],
];
