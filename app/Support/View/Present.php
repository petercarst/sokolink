<?php

declare(strict_types=1);

namespace App\Support\View;

use App\Core\Config;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Database rows in, view-model arrays out.
 *
 * Phase 1 pages were built against explicit contracts documented in
 * app/Views/_mock/*.php, on the promise that Phase 4 would satisfy those
 * contracts rather than rewrite eighty templates. This class is where that
 * promise is kept.
 *
 * It exists because the two vocabularies genuinely differ, and neither is
 * wrong. A query says `business_name`, `available` and `rating_avg` because
 * those are the column names; a product card says `seller_name`,
 * `qty_available` and `rating` because those are what a card shows. Translating
 * in one file means a renamed column changes one line here instead of forty
 * lines across the views.
 *
 * Rules for everything in this file:
 *
 *   - No database access. Rows arrive already fetched.
 *   - No HTML and no escaping. Templates escape at the point of output.
 *   - No money formatting. Amounts stay DECIMAL strings; money() renders them.
 *   - Pure functions. The same row always produces the same view model.
 */
final class Present
{
    /** Tone keys understood by the product-image placeholder component. */
    private const TONES = ['amber', 'sand', 'cream', 'clay', 'forest', 'mint', 'rose', 'sky', 'slate'];

    /** At or below this many units, a card says how few are left. */
    private const LOW_STOCK_AT = 5;

    /**
     * One product, shaped for a card or a product page.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function product(array $row): array
    {
        $available = (int) ($row['qty_available'] ?? $row['available'] ?? 0);
        $price     = (string) ($row['price'] ?? '0.00');
        $compare   = isset($row['compare_at_price']) && $row['compare_at_price'] !== null
            ? (string) $row['compare_at_price']
            : null;

        $fulfilment = [];
        if (!empty($row['allows_pickup'])) {
            $fulfilment[] = 'pickup';
        }
        if (!empty($row['allows_delivery'])) {
            $fulfilment[] = 'delivery';
        }

        return [
            'id'          => (int) ($row['id'] ?? $row['product_id'] ?? 0),
            'slug'        => (string) ($row['slug'] ?? ''),
            'name'        => (string) ($row['name'] ?? ''),
            'brand'       => (string) ($row['brand'] ?? ''),
            'sku'         => (string) ($row['sku'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),

            'category_id'   => (int) ($row['category_id'] ?? 0),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'category_slug' => (string) ($row['category_slug'] ?? ''),

            'price'            => $price,
            'compare_at_price' => $compare,
            'is_on_offer'      => $compare !== null && (float) $compare > (float) $price,

            'unit'      => (string) ($row['unit'] ?? ''),
            'pack_size' => (string) ($row['pack_size'] ?? ''),

            'seller_id'   => (int) ($row['seller_id'] ?? 0),
            'seller_name' => (string) ($row['business_name'] ?? $row['seller_name'] ?? ''),
            'seller_slug' => (string) ($row['seller_slug'] ?? ''),

            'qty_available' => $available,
            'stock_state'   => self::stockState($available),

            'rating'       => round((float) ($row['rating_avg'] ?? 0), 1),
            'review_count' => (int) ($row['rating_count'] ?? 0),

            'fulfilment' => $fulfilment,

            'is_consumable'            => (bool) ($row['is_consumable'] ?? false),
            'typical_consumption_days' => isset($row['typical_consumption_days']) && $row['typical_consumption_days'] !== null
                ? (int) $row['typical_consumption_days']
                : null,

            // Until sellers upload photography, a card still needs a panel of
            // the right shape and a tone that does not change between page
            // loads. When an image does exist, image_path wins and the tone is
            // never used - see the product-image component.
            'image_path' => isset($row['image_path']) && $row['image_path'] !== null && $row['image_path'] !== ''
                ? (string) $row['image_path']
                : null,
            'tone'   => self::tone((string) ($row['slug'] ?? '')),
            'badges' => self::badges($row, $price, $compare),
        ];
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function products(array $rows): array
    {
        return array_map(self::product(...), $rows);
    }

    /**
     * A store, for the store card and the store page.
     *
     * `phone_masked` is masked here rather than in the query, because the
     * seller's own dashboard reads the same column and needs it whole.
     *
     * @param  array<string,mixed>       $row
     * @param  list<array<string,mixed>> $hours  store_hours rows, if loaded
     * @return array<string,mixed>
     */
    public static function store(array $row, array $hours = [], int $productCount = 0): array
    {
        $accepts = [];
        if (!empty($row['offers_pickup'])) {
            $accepts[] = 'pickup';
        }
        if (!empty($row['offers_delivery'])) {
            $accepts[] = 'delivery';
        }

        return [
            'id'          => (int) ($row['id'] ?? 0),
            'slug'        => (string) ($row['slug'] ?? ''),
            'name'        => (string) ($row['name'] ?? ''),
            'seller_id'   => (int) ($row['seller_id'] ?? 0),
            'seller_name' => (string) ($row['business_name'] ?? ''),
            'seller_slug' => (string) ($row['seller_slug'] ?? ''),

            'region'   => (string) ($row['region'] ?? ''),
            'district' => (string) ($row['district'] ?? ''),
            'street'   => (string) ($row['street'] ?? ''),
            'landmark' => (string) ($row['landmark'] ?? ''),

            'phone_masked'        => self::maskPhone((string) ($row['phone'] ?? '')),
            'pickup_instructions' => (string) ($row['pickup_instructions'] ?? ''),
            'collection_window_hours' => (int) ($row['collection_window_hours'] ?? 72),

            'accepts'       => $accepts,
            'rating'        => round((float) ($row['rating_avg'] ?? 0), 1),
            'review_count'  => (int) ($row['rating_count'] ?? 0),
            'product_count' => $productCount,

            'hours'       => self::hours($hours),
            'is_open_now' => self::isOpenNow($hours),
        ];
    }

    /**
     * A list of stores, each carrying its own product_count from the query.
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function stores(array $rows): array
    {
        return array_map(
            static fn (array $row): array => self::store($row, [], (int) ($row['product_count'] ?? 0)),
            $rows
        );
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function review(array $row): array
    {
        return [
            'id'                    => (int) ($row['id'] ?? 0),
            'rating'                => (int) ($row['rating'] ?? 0),
            'title'                 => (string) ($row['title'] ?? ''),
            'body'                  => (string) ($row['body'] ?? ''),
            'customer_name'         => (string) ($row['author'] ?? 'A customer'),
            'is_verified'           => (bool) ($row['is_verified_purchase'] ?? false),
            'created_at_utc'        => (string) ($row['created_at'] ?? ''),
            'seller_reply'          => ($row['seller_reply'] ?? null) !== null ? (string) $row['seller_reply'] : null,
            'seller_replied_at_utc' => ($row['seller_replied_at'] ?? null) !== null ? (string) $row['seller_replied_at'] : null,
        ];
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function reviews(array $rows): array
    {
        return array_map(self::review(...), $rows);
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function category(array $row): array
    {
        return [
            'id'            => (int) ($row['id'] ?? 0),
            'slug'          => (string) ($row['slug'] ?? ''),
            'name'          => (string) ($row['name'] ?? ''),
            'icon'          => (string) ($row['icon'] ?? 'package'),
            'product_count' => (int) ($row['product_count'] ?? 0),
            'parent'        => null,
        ];
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function categories(array $rows): array
    {
        return array_map(self::category(...), $rows);
    }

    // ---- derivations -----------------------------------------------------

    private static function stockState(int $available): string
    {
        return match (true) {
            $available <= 0                  => 'out',
            $available <= self::LOW_STOCK_AT => 'low',
            default                          => 'in',
        };
    }

    /**
     * A stable tone per product.
     *
     * crc32 of the slug, not a random pick: the same product must look the same
     * on the home page, in a listing and in the basket, and it must not change
     * when the page is reloaded.
     */
    private static function tone(string $slug): string
    {
        if ($slug === '') {
            return 'slate';
        }

        return self::TONES[crc32($slug) % count(self::TONES)];
    }

    /**
     * Badges are derived from facts, never authored.
     *
     * Only two qualify. "Save N%" restates the two prices already on the card,
     * and "Top rated" needs enough reviews to mean anything - a single
     * five-star review is not a distinction.
     *
     * @param  array<string,mixed> $row
     * @return list<string>
     */
    private static function badges(array $row, string $price, ?string $compare): array
    {
        $badges = [];

        if ($compare !== null && (float) $compare > (float) $price && (float) $compare > 0) {
            $percent = (int) round(((float) $compare - (float) $price) / (float) $compare * 100);

            if ($percent >= 5) {
                $badges[] = 'Save ' . $percent . '%';
            }
        }

        if ((float) ($row['rating_avg'] ?? 0) >= 4.5 && (int) ($row['rating_count'] ?? 0) >= 10) {
            $badges[] = 'Top rated';
        }

        return $badges;
    }

    /**
     * Keeps the last three digits, which is enough for someone to recognise
     * their own number and not enough to dial anybody else's.
     */
    private static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (mb_strlen($digits) < 4) {
            return '';
        }

        return mb_substr($phone, 0, 4) . '** *** ' . mb_substr($digits, -3);
    }

    /**
     * Opening hours keyed the way the store page reads them: `mon` .. `sun`,
     * each either "08:00-20:00" or the literal "closed".
     *
     * Always all seven keys. A day with no row is closed, not missing - a
     * template should not have to decide what an absent Sunday means.
     *
     * @param  list<array<string,mixed>> $rows store_hours, day_of_week 0 = Monday
     * @return array<string,string>
     */
    private static function hours(array $rows): array
    {
        $keys  = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $byDay = [];

        foreach ($rows as $row) {
            $byDay[(int) $row['day_of_week']] = $row;
        }

        $out = [];

        foreach ($keys as $index => $key) {
            $row = $byDay[$index] ?? null;

            $out[$key] = ($row === null || (bool) $row['is_closed']
                || $row['opens_at'] === null || $row['closes_at'] === null)
                ? 'closed'
                : self::clock((string) $row['opens_at']) . '-' . self::clock((string) $row['closes_at']);
        }

        return $out;
    }

    /**
     * Opening hours are local wall-clock, so this compares against the display
     * timezone rather than UTC. Storing them UTC would shift them twice a year
     * in places that observe DST, which is exactly the bug the schema comment
     * on store_hours warns about.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function isOpenNow(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone((string) Config::get('app.display_timezone', 'UTC'))
        );

        // PHP's 'N' is 1 = Monday; the column is 0 = Monday.
        $weekday = (int) $now->format('N') - 1;
        $time    = $now->format('H:i:s');

        foreach ($rows as $row) {
            if ((int) $row['day_of_week'] !== $weekday) {
                continue;
            }

            if ((bool) $row['is_closed'] || $row['opens_at'] === null || $row['closes_at'] === null) {
                return false;
            }

            return $time >= (string) $row['opens_at'] && $time <= (string) $row['closes_at'];
        }

        return false;
    }

    private static function clock(string $time): string
    {
        return mb_substr($time, 0, 5);
    }
}
