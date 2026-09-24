<?php

declare(strict_types=1);

/* =============================================================================
 *  MOCK DATA - PHASE 1 ONLY
 * -----------------------------------------------------------------------------
 *  Replaced in Phase 4 by App\Repositories\ReviewRepository::publishedForProduct()
 *
 *  CONTRACT - one row per published review:
 *    id                int
 *    product_id        int
 *    customer_name     string   display name only, never the email (see
 *                               USER_ROLES_AND_PERMISSIONS.md section 4.1)
 *    rating            int      1-5
 *    title             string
 *    body              string
 *    is_verified       bool     true only when a completed sub-order contained
 *                               this product (FR-REV-01)
 *    created_at_utc    string   UTC datetime - Clock converts at render
 *    seller_reply      string|null
 *    seller_replied_at_utc string|null
 *
 *  Source tables (Phase 2): reviews, review_replies, users
 * ===========================================================================*/

return [
    [
        'id' => 1, 'product_id' => 101, 'customer_name' => 'Asha M.', 'rating' => 5,
        'title' => 'Good value for the 5 litre size',
        'body' => 'I have bought this four times now. The seal was intact each time and the oil is clean with no smell. Collecting from Kariakoo took about two minutes once I had the code.',
        'is_verified' => true, 'created_at_utc' => '2026-08-28 09:14:00',
        'seller_reply' => 'Thank you Asha, we always check the seals before the collection counter.',
        'seller_replied_at_utc' => '2026-08-29 06:40:00',
    ],
    [
        'id' => 2, 'product_id' => 101, 'customer_name' => 'Baraka J.', 'rating' => 4,
        'title' => 'Fine, but the handle is weak',
        'body' => 'Oil itself is good and the price is fair. The jerrycan handle bent when I carried it far, so bring a bag if you are walking.',
        'is_verified' => true, 'created_at_utc' => '2026-08-11 15:02:00',
        'seller_reply' => null, 'seller_replied_at_utc' => null,
    ],
    [
        'id' => 3, 'product_id' => 101, 'customer_name' => 'Neema K.', 'rating' => 5,
        'title' => 'Reorder reminder was actually useful',
        'body' => 'Got a message just as I was running low and reordered in one tap. That is the first time an online shop has got the timing right for me.',
        'is_verified' => true, 'created_at_utc' => '2026-07-30 18:47:00',
        'seller_reply' => null, 'seller_replied_at_utc' => null,
    ],
    [
        'id' => 4, 'product_id' => 101, 'customer_name' => 'Joseph S.', 'rating' => 3,
        'title' => 'Delivery was slower than the estimate',
        'body' => 'Product is what it says. The delivery took an extra day and I had to call to find out where it was. The oil was fine when it arrived.',
        'is_verified' => true, 'created_at_utc' => '2026-07-19 11:23:00',
        'seller_reply' => 'Sorry about that Joseph - the Mbezi route was disrupted that week. We have added a second agent to it since.',
        'seller_replied_at_utc' => '2026-07-20 08:05:00',
    ],
    [
        'id' => 5, 'product_id' => 102, 'customer_name' => 'Fatuma H.', 'rating' => 5,
        'title' => 'Clean rice, no stones',
        'body' => 'Sifted properly. I cooked from it the same evening and did not have to pick through it.',
        'is_verified' => true, 'created_at_utc' => '2026-09-02 07:55:00',
        'seller_reply' => null, 'seller_replied_at_utc' => null,
    ],
    [
        'id' => 6, 'product_id' => 105, 'customer_name' => 'Grace T.', 'rating' => 5,
        'title' => 'Strong and proper',
        'body' => 'Brews dark the way it should. The 500 g pack lasts my household about six weeks.',
        'is_verified' => true, 'created_at_utc' => '2026-09-08 16:30:00',
        'seller_reply' => null, 'seller_replied_at_utc' => null,
    ],
];
