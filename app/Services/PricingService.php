<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\DomainRuleException;
use App\Repositories\SettingsRepository;
use App\Repositories\ZoneRepository;

/**
 * Every figure the customer is charged.
 *
 * The rule this file exists to enforce: **nothing here reads a price, a fee, a
 * discount or a total from the request.** Line prices come from `products`,
 * delivery fees come from `delivery_zones`, and the totals are computed from
 * those. A client that posts `total=1` posts a field that is never read.
 *
 * Money is handled as integer minor units internally and returned as a
 * DECIMAL-shaped string. TZS has no subunit in practice, but the schema stores
 * two decimal places and the arithmetic is done in integers either way - adding
 * three floats and comparing the result to a fourth is how a basket ends up a
 * shilling short of its own lines.
 */
final class PricingService
{
    public function __construct(
        private readonly ZoneRepository $zones = new ZoneRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
    ) {
    }

    /**
     * Prices a basket, grouped by seller, ready to become an order.
     *
     * @param list<array<string,mixed>> $items       rows from CartRepository::itemsFor()
     * @param array<int,string>         $methods     seller_id => 'pickup'|'delivery'
     * @param array<string,mixed>|null  $address     the delivery address, when any line is delivered
     *
     * @return array{
     *     groups: list<array<string,mixed>>,
     *     items_subtotal: string,
     *     delivery_total: string,
     *     discount_total: string,
     *     grand_total: string,
     *     currency: string
     * }
     */
    public function priceBasket(array $items, array $methods = [], ?array $address = null): array
    {
        if ($items === []) {
            throw new DomainRuleException('Your basket is empty.', 'empty_basket');
        }

        $bySeller = [];

        foreach ($items as $item) {
            $sellerId = (int) $item['seller_id'];

            // The LIVE price, from the product row. Never the cart's
            // price_when_added, and never anything the client sent.
            $unitPrice = $this->toMinor((string) $item['price']);
            $quantity  = max(1, (int) $item['qty']);
            $lineTotal = $unitPrice * $quantity;

            $bySeller[$sellerId] ??= [
                'seller_id'         => $sellerId,
                'business_name'     => (string) $item['business_name'],
                'seller_slug'       => (string) ($item['seller_slug'] ?? ''),
                'commission_percent' => (string) ($item['commission_percent'] ?? '0.00'),
                'prep_hours'        => (int) ($item['prep_hours'] ?? 4),
                'store_id'          => $item['store_id'] === null ? null : (int) $item['store_id'],
                'store_name'        => $item['store_name'] ?? null,
                'lines'             => [],
                'subtotal_minor'    => 0,
                'weight_grams'      => 0,
            ];

            $bySeller[$sellerId]['lines'][] = [
                'product_id'   => (int) $item['product_id'],
                'store_id'     => $item['store_id'] === null ? null : (int) $item['store_id'],
                'name'         => (string) $item['name'],
                'slug'         => (string) $item['slug'],
                'pack_size'    => (string) ($item['pack_size'] ?? ''),
                'unit'         => (string) ($item['unit'] ?? ''),
                'quantity'     => $quantity,
                'unit_price'   => $this->toDecimal($unitPrice),
                'line_total'   => $this->toDecimal($lineTotal),
                'available'    => (int) ($item['available'] ?? 0),
                'price_changed' => $this->priceChanged($item),
            ];

            $bySeller[$sellerId]['subtotal_minor'] += $lineTotal;
            $bySeller[$sellerId]['weight_grams']   += ((int) ($item['weight_grams'] ?? 0)) * $quantity;
        }

        $itemsSubtotal = 0;
        $deliveryTotal = 0;
        $groups        = [];

        foreach ($bySeller as $sellerId => $group) {
            $method = $methods[$sellerId] ?? 'pickup';

            $deliveryMinor = $method === 'delivery'
                ? $this->deliveryFeeMinor($group['subtotal_minor'], $group['weight_grams'], $address)
                : 0;

            $subtotal   = $group['subtotal_minor'];
            $total      = $subtotal + $deliveryMinor;
            $commission = $this->commissionMinor($subtotal, (string) $group['commission_percent']);

            $groups[] = [
                'seller_id'         => $sellerId,
                'business_name'     => $group['business_name'],
                'seller_slug'       => $group['seller_slug'],
                'store_id'          => $group['store_id'],
                'store_name'        => $group['store_name'],
                'prep_hours'        => $group['prep_hours'],
                'fulfilment_method' => $method,
                'lines'             => $group['lines'],
                'weight_grams'      => $group['weight_grams'],
                'subtotal'          => $this->toDecimal($subtotal),
                'delivery_fee'      => $this->toDecimal($deliveryMinor),
                'commission_amount' => $this->toDecimal($commission),
                'total'             => $this->toDecimal($total),
            ];

            $itemsSubtotal += $subtotal;
            $deliveryTotal += $deliveryMinor;
        }

        return [
            'groups'         => $groups,
            'items_subtotal' => $this->toDecimal($itemsSubtotal),
            'delivery_total' => $this->toDecimal($deliveryTotal),
            'discount_total' => $this->toDecimal(0),
            'grand_total'    => $this->toDecimal($itemsSubtotal + $deliveryTotal),
            'currency'       => (string) $this->settings->get('platform.currency', 'TZS'),
        ];
    }

    /**
     * The delivery fee for one seller's parcel.
     *
     * Three rules, in this order, and all from the zone row rather than from
     * anything the customer chose:
     *
     *   1. A basket over the zone's free threshold pays nothing.
     *   2. A parcel over the zone's heavy threshold pays a surcharge.
     *   3. Otherwise the zone's base fee.
     *
     * An address in no known zone is not given a guessed fee - it throws, and
     * the checkout tells the customer that delivery is not available there.
     * Inventing a fee for an area with no agents is a promise nobody can keep.
     *
     * @param array<string,mixed>|null $address
     */
    private function deliveryFeeMinor(int $subtotalMinor, int $weightGrams, ?array $address): int
    {
        if ($address === null) {
            throw new DomainRuleException(
                'Choose a delivery address before we can work out the delivery fee.',
                'address_required'
            );
        }

        $zone = $this->zones->forAddress(
            (string) ($address['region'] ?? ''),
            (string) ($address['district'] ?? ''),
            isset($address['zone_id']) && $address['zone_id'] !== null ? (int) $address['zone_id'] : null
        );

        if ($zone === null) {
            throw new DomainRuleException(
                sprintf(
                    'We do not deliver to %s yet. Choose collection, or a different address.',
                    (string) ($address['district'] ?? 'that area')
                ),
                'zone_unavailable'
            );
        }

        $freeThreshold = $zone['free_threshold'] !== null ? $this->toMinor((string) $zone['free_threshold']) : null;

        if ($freeThreshold !== null && $subtotalMinor >= $freeThreshold) {
            return 0;
        }

        $fee = $this->toMinor((string) $zone['base_fee']);

        if ($weightGrams > (int) $zone['heavy_threshold_grams']) {
            $fee += $this->toMinor((string) $zone['heavy_surcharge']);
        }

        return $fee;
    }

    /**
     * The platform's cut, recorded per sub-order.
     *
     * Payouts are not built in v1 (open question OQ-04), but the number is
     * recorded from the first order. Computing it later from a commission rate
     * that has since changed would give the wrong answer for every historical
     * order, and there would be no way to tell.
     */
    private function commissionMinor(int $subtotalMinor, string $percent): int
    {
        // intdiv on the scaled product rather than a float multiply: 5% of
        // 40,000 should be 2,000 every time, not 1,999.9999999998.
        $basisPoints = (int) round(((float) $percent) * 100);

        return intdiv($subtotalMinor * $basisPoints, 10000);
    }

    /**
     * Whether the price has moved since the item went into the basket.
     *
     * Reported, not charged: the customer pays today's price, and the page says
     * so. Silently charging more than the basket showed is the complaint that
     * ends up with a bank.
     *
     * @param  array<string,mixed> $item
     * @return array{changed:bool,direction:string,was:string}|null
     */
    private function priceChanged(array $item): ?array
    {
        $now  = $this->toMinor((string) $item['price']);
        $then = $this->toMinor((string) $item['price_when_added']);

        if ($now === $then) {
            return null;
        }

        return [
            'changed'   => true,
            'direction' => $now > $then ? 'up' : 'down',
            'was'       => $this->toDecimal($then),
        ];
    }

    /** "1500.00" -> 150000 minor units. */
    public function toMinor(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    /** 150000 minor units -> "1500.00", ready for a DECIMAL(12,2) column. */
    public function toDecimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
