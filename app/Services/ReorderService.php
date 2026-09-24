<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\DomainRuleException;
use App\Repositories\CartRepository;
use App\Repositories\OrderRepository;
use App\Support\View\Present;

/**
 * The reorder engine's read side: what somebody bought before, re-checked.
 *
 * **Revalidation is the feature** (FR-CRM-11, USER_FLOWS.md Flow J). A reorder
 * button that adds last month's basket at last month's prices is a refund
 * request waiting to happen. Every line here is re-priced and re-checked
 * against stock as it is now, and the difference is SHOWN rather than absorbed:
 *
 *   - `available`     - same price, enough room, nothing to say.
 *   - `price_changed` - it costs something else now, and the page says both.
 *   - `partial`       - some room, less than they bought last time.
 *   - `unavailable`   - unpublished, seller inactive, or nothing left.
 *
 * Nothing is substituted. If the seller has stopped listing a product, the
 * answer is "we cannot get you this", not a different product with a similar
 * name - the customer asked to buy the thing they bought before.
 *
 * **Room, not stock.** The quantity each line reports is what pressing Add
 * would actually put in the basket: the best single store's stock, minus what
 * the basket already holds of that product. Reporting raw stock would let the
 * page promise three and the button deliver one, which is the exact failure
 * this screen exists to prevent - it would just have moved it one click later.
 *
 * Adding goes through CartService, which re-checks stock and price AGAIN at
 * that moment under its own rules. This class decides what to show; it is not
 * trusted to decide what a line costs.
 */
final class ReorderService
{
    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly CartRepository $carts = new CartRepository(),
        private readonly CartService $cart = new CartService(),
    ) {
    }

    /**
     * The reorder list, revalidated.
     *
     * @return list<array<string,mixed>>
     */
    public function revalidate(int $userId, int $limit = 24): array
    {
        $held = $this->carts->heldQuantitiesForUser($userId);

        return array_map(
            static function (array $row) use ($held): array {
                $productId = (int) $row['product_id'];

                $inStock = (int) $row['available'];
                $inCart  = $held[$productId] ?? 0;

                // What one press of Add could actually achieve.
                $room = max(0, $inStock - $inCart);

                $lastQty      = max(1, (int) ($row['last_qty'] ?? 1));
                $currentPrice = (string) $row['current_price'];
                $lastPrice    = $row['last_unit_price'] !== null
                    ? (string) $row['last_unit_price']
                    : $currentPrice;

                $buyable = (string) $row['product_status'] === 'published'
                    && (string) $row['seller_status'] === 'active';

                if (!$buyable || $room < 1) {
                    $outcome = 'unavailable';
                } elseif ($room < $lastQty) {
                    // Checked before the price: being told the price moved is
                    // no use if we cannot supply the quantity anyway.
                    $outcome = 'partial';
                } elseif (
                    // Compared in minor units. Two DECIMAL(12,2) values that
                    // differ by a rounding artefact are not a price change, and
                    // telling somebody their price changed when it did not is
                    // its own kind of wrong.
                    (int) round((float) $currentPrice * 100) !== (int) round((float) $lastPrice * 100)
                ) {
                    $outcome = 'price_changed';
                } else {
                    $outcome = 'available';
                }

                return [
                    'product_id'      => $productId,
                    'slug'            => (string) $row['slug'],
                    'product'         => (string) $row['name'],
                    'pack_size'       => (string) $row['pack_size'],
                    'seller'          => (string) $row['seller_name'],
                    'current_price'   => $currentPrice,
                    'last_price'      => $lastPrice,
                    'last_qty'        => $lastQty,
                    'in_stock'        => $inStock,
                    'in_basket'       => $inCart,
                    // Named `available` because that is what the page means by
                    // it: how many you can have, not how many exist.
                    'available'       => $room,
                    'times_bought'    => (int) $row['times_bought'],
                    'last_bought_utc' => (string) $row['last_bought_at'],
                    'outcome'         => $outcome,
                    'tone'            => Present::product(['slug' => (string) $row['slug']])['tone'],
                ];
            },
            $this->orders->reorderCandidatesFor($userId, $limit)
        );
    }

    /**
     * Adds one previously-bought product back to the basket.
     *
     * The quantity is whatever they bought last time, capped at the room the
     * page already showed them. It is capped rather than refused because
     * somebody reordering six who can have four would rather have four - and
     * the page said "partial" before they pressed it, so the cap is not a
     * surprise.
     *
     * The product id is checked against THEIR purchase history first. Without
     * that, this endpoint is an "add any product id to my basket" route with a
     * friendlier name.
     */
    public function addToBasket(int $userId, int $productId): string
    {
        $line = null;

        foreach ($this->revalidate($userId, 50) as $candidate) {
            if ($candidate['product_id'] === $productId) {
                $line = $candidate;
                break;
            }
        }

        if ($line === null) {
            throw new DomainRuleException(
                'That is not something you have bought before.',
                'not_previously_bought'
            );
        }

        if ($line['outcome'] === 'unavailable') {
            throw new DomainRuleException(
                $line['in_basket'] > 0 && $line['in_stock'] > 0
                    ? sprintf(
                        'Your basket already holds all %d of those the seller has left.',
                        $line['in_basket']
                    )
                    : 'The seller is no longer offering that, so there is nothing to add. '
                      . 'We will not substitute something else for it.',
                'unavailable'
            );
        }

        $qty = min($line['last_qty'], $line['available']);

        // CartService re-checks stock and takes today's price from the
        // database. Nothing computed above is trusted for the amount.
        $this->cart->add($this->cart->currentCartId(), $productId, $qty);

        if ($qty < $line['last_qty']) {
            return sprintf(
                '%d added - that is all %s has left, rather than the %d you bought last time.',
                $qty,
                $line['seller'],
                $line['last_qty']
            );
        }

        return sprintf('%d x %s added to your basket at today\'s price.', $qty, $line['product']);
    }
}
