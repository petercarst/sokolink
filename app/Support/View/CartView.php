<?php

declare(strict_types=1);

namespace App\Support\View;

use App\Repositories\StoreRepository;

/**
 * CartService::summary() output, shaped for the basket and checkout pages.
 *
 * The interesting work here is the collection picker. A seller group can only
 * be collected from a store that stocks *every* line in that group - offering a
 * store that has three of the four items would send someone across town for a
 * partial order (FR-CART-06). Working that out needs the per-store stock for
 * each line, which is why this presenter is allowed a repository, unlike
 * Present.
 *
 * It still computes no money. Every amount here comes from PricingService,
 * which is the only thing in the system permitted to add prices up.
 */
final class CartView
{
    public function __construct(
        private readonly StoreRepository $stores = new StoreRepository(),
    ) {
    }

    /**
     * @param  array<string,mixed> $summary  from CartService::summary()
     * @param  array<int,string>   $methods  seller_id => pickup|delivery, as chosen so far
     * @return array<string,mixed>
     */
    public function present(array $summary, array $methods = []): array
    {
        if (!empty($summary['empty'])) {
            return self::emptyBasket((string) ($summary['currency'] ?? 'TZS'));
        }

        /** @var list<array<string,mixed>> $items */
        $items = $summary['items'];

        // Lines keyed by seller, so a group can be matched to its priced
        // counterpart without a nested search per line.
        $linesBySeller = [];
        foreach ($items as $item) {
            $linesBySeller[(int) $item['seller_id']][] = $item;
        }

        $groups = [];

        foreach ($summary['groups'] as $group) {
            $sellerId  = (int) $group['seller_id'];
            $rawLines  = $linesBySeller[$sellerId] ?? [];
            $options   = self::fulfilmentOptions($rawLines);
            $selected  = $methods[$sellerId] ?? $group['fulfilment_method'] ?? 'pickup';

            // A method the seller cannot actually do is not a choice. Fall back
            // to whatever they do offer rather than letting checkout refuse it
            // three screens later.
            if (!in_array($selected, $options, true)) {
                $selected = $options[0] ?? 'pickup';
            }

            $stores = $this->storeOptionsFor($rawLines);

            $groups[] = [
                'seller_id'   => $sellerId,
                'seller_name' => (string) $group['business_name'],
                'seller_slug' => (string) $group['seller_slug'],
                'prep_hours'  => (int) $group['prep_hours'],

                'fulfilment_options'  => $options,
                'selected_fulfilment' => $selected,
                'selected_store_id'   => self::selectedStore($rawLines, $stores),
                'available_stores'    => $stores,

                'items'        => self::lines($group['lines'], $rawLines),
                'subtotal'     => (string) $group['subtotal'],
                'delivery_fee' => (string) $group['delivery_fee'],
                'total'        => (string) $group['total'],
            ];
        }

        return [
            'item_count' => (int) $summary['count'],
            'currency'   => (string) $summary['currency'],
            'groups'     => $groups,
            'problems'   => $summary['problems'],
            'can_checkout' => (bool) ($summary['can_checkout'] ?? false),
            'totals'     => [
                'items_subtotal' => (string) $summary['items_subtotal'],
                'delivery_total' => (string) $summary['delivery_total'],
                'discount_total' => (string) $summary['discount_total'],
                'grand_total'    => (string) $summary['grand_total'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function emptyBasket(string $currency = 'TZS'): array
    {
        return [
            'item_count' => 0,
            'currency'   => $currency,
            'groups'     => [],
            'problems'   => [],
            'can_checkout' => false,
            'totals'     => [
                'items_subtotal' => '0.00',
                'delivery_total' => '0.00',
                'discount_total' => '0.00',
                'grand_total'    => '0.00',
            ],
        ];
    }

    /**
     * Priced lines merged with the basket rows they came from.
     *
     * PricingService owns the money; the cart row owns everything else the page
     * shows - the image, the brand, which line to remove, what the price was
     * when it went in the basket.
     *
     * @param  list<array<string,mixed>> $priced
     * @param  list<array<string,mixed>> $raw
     * @return list<array<string,mixed>>
     */
    private static function lines(array $priced, array $raw): array
    {
        $byProduct = [];
        foreach ($raw as $row) {
            $byProduct[(int) $row['product_id'] . ':' . (string) ($row['store_id'] ?? '')] = $row;
        }

        $out = [];

        foreach ($priced as $line) {
            $key = (int) $line['product_id'] . ':' . (string) ($line['store_id'] ?? '');
            $row = $byProduct[$key] ?? null;

            $wasPrice = $row !== null ? (string) $row['price_when_added'] : null;
            $nowPrice = (string) $line['unit_price'];

            $out[] = [
                'cart_item_id' => $row !== null ? (int) $row['cart_item_id'] : 0,
                'product_id'   => (int) $line['product_id'],
                'store_id'     => $line['store_id'] === null ? null : (int) $line['store_id'],
                'slug'         => (string) $line['slug'],
                'name'         => (string) $line['name'],
                'brand'        => $row !== null ? (string) ($row['brand'] ?? '') : '',
                'pack_size'    => (string) $line['pack_size'],
                'unit'         => (string) $line['unit'],
                'image_path'   => $row !== null && ($row['image_path'] ?? null) !== null ? (string) $row['image_path'] : null,
                'tone'         => self::toneFor((string) $line['slug']),

                'unit_price' => $nowPrice,
                'qty'        => (int) $line['quantity'],
                'line_total' => (string) $line['line_total'],

                'qty_available' => (int) $line['available'],
                'stock_state'   => match (true) {
                    (int) $line['available'] <= 0 => 'out',
                    (int) $line['available'] <= 5 => 'low',
                    default                       => 'in',
                },

                // Only set when it actually moved, so the template can show the
                // notice by presence rather than by comparing two strings.
                'price_changed_from' => ($wasPrice !== null && $wasPrice !== $nowPrice) ? $wasPrice : null,
            ];
        }

        return $out;
    }

    /**
     * What this seller can do for this particular set of lines.
     *
     * The intersection, not the union: if one product in the group cannot be
     * delivered, the group cannot be delivered.
     *
     * @param  list<array<string,mixed>> $lines
     * @return list<string>
     */
    private static function fulfilmentOptions(array $lines): array
    {
        if ($lines === []) {
            return ['pickup'];
        }

        $pickup   = true;
        $delivery = true;

        foreach ($lines as $line) {
            $pickup   = $pickup   && (bool) $line['allows_pickup'];
            $delivery = $delivery && (bool) $line['allows_delivery'];
        }

        $options = [];
        if ($pickup) {
            $options[] = 'pickup';
        }
        if ($delivery) {
            $options[] = 'delivery';
        }

        return $options === [] ? ['pickup'] : $options;
    }

    /**
     * Collection points for a seller group, each flagged with whether it
     * stocks every line.
     *
     * A store that cannot supply the whole group is still listed, disabled and
     * labelled. Hiding it would leave a customer wondering why the shop they
     * walk past every day is not an option.
     *
     * @param  list<array<string,mixed>> $lines
     * @return list<array{id:int,name:string,district:string,stocks_all_lines:bool}>
     */
    private function storeOptionsFor(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        /** @var array<int,array<string,mixed>> $seen */
        $seen = [];
        /** @var array<int,int> $satisfies  store_id => how many lines it can supply */
        $satisfies = [];

        foreach ($lines as $line) {
            $options = $this->stores->pickupOptionsFor((int) $line['product_id'], (int) $line['qty']);

            foreach ($options as $store) {
                $storeId = (int) $store['id'];

                $seen[$storeId] ??= $store;
                $satisfies[$storeId] = ($satisfies[$storeId] ?? 0) + 1;
            }
        }

        $lineCount = count($lines);
        $out       = [];

        foreach ($seen as $storeId => $store) {
            $out[] = [
                'id'               => $storeId,
                'name'             => (string) $store['name'],
                'district'         => (string) $store['district'],
                'stocks_all_lines' => ($satisfies[$storeId] ?? 0) === $lineCount,
            ];
        }

        // Stores that can supply everything first: the usable options should
        // not be below the fold in a long list.
        usort($out, static fn (array $a, array $b): int => ($b['stocks_all_lines'] <=> $a['stocks_all_lines']) ?: strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * The collection point already chosen, or the first that works.
     *
     * @param  list<array<string,mixed>> $lines
     * @param  list<array{id:int,stocks_all_lines:bool}> $stores
     */
    private static function selectedStore(array $lines, array $stores): ?int
    {
        foreach ($lines as $line) {
            if ($line['store_id'] !== null) {
                return (int) $line['store_id'];
            }
        }

        foreach ($stores as $store) {
            if ($store['stocks_all_lines']) {
                return $store['id'];
            }
        }

        return null;
    }

    private static function toneFor(string $slug): string
    {
        // Same derivation as Present::tone(), reached through the public API so
        // a basket line and its product card cannot drift apart.
        return (string) Present::product(['slug' => $slug])['tone'];
    }
}
