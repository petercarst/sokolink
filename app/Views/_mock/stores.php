<?php

declare(strict_types=1);

/* =============================================================================
 *  MOCK DATA - PHASE 1 ONLY
 * -----------------------------------------------------------------------------
 *  Replaced in Phase 4 by:
 *    App\Repositories\StoreRepository::findBySlug()
 *    App\Repositories\StoreRepository::listForSeller()
 *    App\Repositories\StoreRepository::listPickupOptionsFor(array $productIds)
 *
 *  CONTRACT - one row per store:
 *    id              int
 *    seller_id       int
 *    seller_name     string
 *    seller_slug     string
 *    slug            string   unique
 *    name            string
 *    region          string
 *    district        string
 *    street          string
 *    landmark        string|null
 *    phone_masked    string   never the raw number in a public view
 *    hours           array<string,string>  day key => "08:00-20:00" or "closed"
 *    pickup_instructions string
 *    rating          float
 *    review_count    int
 *    product_count   int
 *    is_open_now     bool     computed server-side in the display timezone
 *    accepts         list<string>  subset of ['pickup','delivery']
 *
 *  Source tables (Phase 2): stores, sellers, store_hours
 * ===========================================================================*/

$standardHours = [
    'mon' => '07:30-20:00', 'tue' => '07:30-20:00', 'wed' => '07:30-20:00',
    'thu' => '07:30-20:00', 'fri' => '07:30-20:00', 'sat' => '08:00-21:00',
    'sun' => '09:00-17:00',
];

return [
    [
        'id' => 1, 'seller_id' => 1, 'seller_name' => 'Mama Lishe Provisions', 'seller_slug' => 'mama-lishe',
        'slug' => 'mama-lishe-kariakoo', 'name' => 'Mama Lishe - Kariakoo',
        'region' => 'Dar es Salaam', 'district' => 'Ilala', 'street' => 'Msimbazi Street 114',
        'landmark' => 'Opposite Kariakoo Market north gate',
        'phone_masked' => '+255 7** *** 412',
        'hours' => $standardHours,
        'pickup_instructions' => 'Collection counter is inside the main entrance on the left. Please have your collection code ready on your phone.',
        'rating' => 4.6, 'review_count' => 312, 'product_count' => 84,
        'is_open_now' => true, 'accepts' => ['pickup', 'delivery'],
    ],
    [
        'id' => 2, 'seller_id' => 1, 'seller_name' => 'Mama Lishe Provisions', 'seller_slug' => 'mama-lishe',
        'slug' => 'mama-lishe-mbezi', 'name' => 'Mama Lishe - Mbezi Beach',
        'region' => 'Dar es Salaam', 'district' => 'Kinondoni', 'street' => 'Africana Road 7',
        'landmark' => 'Next to the Shell station',
        'phone_masked' => '+255 7** *** 877',
        'hours' => array_merge($standardHours, ['sun' => 'closed']),
        'pickup_instructions' => 'Ring the bell at the side gate marked COLLECTIONS. Parking available for 10 minutes.',
        'rating' => 4.4, 'review_count' => 128, 'product_count' => 71,
        'is_open_now' => true, 'accepts' => ['pickup', 'delivery'],
    ],
    [
        'id' => 3, 'seller_id' => 2, 'seller_name' => 'Duka Kuu Wholesalers', 'seller_slug' => 'duka-kuu',
        'slug' => 'duka-kuu-arusha', 'name' => 'Duka Kuu - Arusha Central',
        'region' => 'Arusha', 'district' => 'Arusha City', 'street' => 'Sokoine Road 42',
        'landmark' => 'Ground floor, Uhuru Building',
        'phone_masked' => '+255 6** *** 203',
        'hours' => $standardHours,
        'pickup_instructions' => 'Collections are handled at the rear loading bay between 09:00 and 18:00.',
        'rating' => 4.8, 'review_count' => 204, 'product_count' => 112,
        'is_open_now' => false, 'accepts' => ['pickup', 'delivery'],
    ],
    [
        'id' => 4, 'seller_id' => 3, 'seller_name' => 'Bustani Fresh', 'seller_slug' => 'bustani-fresh',
        'slug' => 'bustani-mwanza', 'name' => 'Bustani Fresh - Mwanza',
        'region' => 'Mwanza', 'district' => 'Nyamagana', 'street' => 'Kenyatta Road 9',
        'landmark' => 'Beside the fish market entrance',
        'phone_masked' => '+255 7** *** 665',
        'hours' => array_merge($standardHours, ['mon' => '06:00-18:00', 'sat' => '06:00-18:00']),
        'pickup_instructions' => 'Fresh orders are held in the chiller. Please collect within 4 hours of the ready notification.',
        'rating' => 4.3, 'review_count' => 96, 'product_count' => 48,
        'is_open_now' => true, 'accepts' => ['pickup'],
    ],
];
