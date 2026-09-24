<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Repositories\CartRepository;
use App\Repositories\ProductRepository;
use App\Repositories\StoreRepository;

/**
 * The basket.
 *
 * Two things this service will not do, both deliberate:
 *
 *   1. It never accepts a price. Adding an item reads the price from the
 *      product row; the quantity is the only number the customer controls.
 *   2. It never reserves stock. A basket is an intention - reserving on "add to
 *      basket" would let one browser hold a shop's entire stock indefinitely.
 *      Stock is reserved at checkout, inside the order transaction, and not
 *      before.
 *
 * Availability IS checked when adding, because telling someone at checkout that
 * everything they picked an hour ago has gone is worse than telling them now.
 * That check is advisory: the one that counts runs under a row lock later.
 */
final class CartService
{
    private const MAX_QTY_PER_LINE = 99;
    private const MAX_LINES        = 50;

    public function __construct(
        private readonly CartRepository $carts = new CartRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly StoreRepository $stores = new StoreRepository(),
    ) {
    }

    /**
     * The current basket id, creating one if needed.
     *
     * A signed-in customer always gets their account basket. A guest gets one
     * keyed by a hashed cookie value, so the raw cookie is not a database key
     * somebody could enumerate.
     */
    public function currentCartId(?string $guestCookieHash = null): int
    {
        $userId = Auth::id();

        if ($userId !== null) {
            $cart = $this->carts->activeForUser($userId);

            return $cart !== null ? (int) $cart['id'] : $this->carts->createForUser($userId);
        }

        if ($guestCookieHash === null || $guestCookieHash === '') {
            throw new DomainRuleException('Enable cookies to use the basket.', 'no_cart_key');
        }

        $cart = $this->carts->activeForCookie($guestCookieHash);

        return $cart !== null ? (int) $cart['id'] : $this->carts->createForCookie($guestCookieHash);
    }

    /**
     * Adds a product, or increases the quantity if it is already there for the
     * same store.
     *
     * The same product from two different collection points is two lines, not
     * one, because they will be collected from two different counters.
     */
    public function add(int $cartId, int $productId, int $quantity = 1, ?int $storeId = null): void
    {
        $quantity = $this->assertQuantity($quantity);

        $product = $this->products->findPublished($productId);

        if ($product === null) {
            throw new DomainRuleException('That product is no longer available.', 'unavailable');
        }

        if ($storeId !== null) {
            $this->assertStoreCanSupply($product, $storeId);
        } else {
            // Every line needs a source: it is a specific store's inventory
            // that gets reserved, and checkout refuses a line without one.
            // Picking the best available source now - rather than leaving it
            // null and blocking checkout later - means "add to basket" from a
            // listing works, and the customer can still change it on the
            // basket page before they order.
            $storeId = $this->defaultSourceFor($product, $quantity);
        }

        $existing = $this->carts->findItem($cartId, $productId, $storeId);
        $wanted   = $quantity + ($existing !== null ? (int) $existing['qty'] : 0);

        if ($wanted > self::MAX_QTY_PER_LINE) {
            throw new DomainRuleException(
                sprintf('You can order up to %d of one item. For more, contact the seller.', self::MAX_QTY_PER_LINE),
                'quantity_cap'
            );
        }

        $this->assertStockLooksSufficient($product, $storeId, $wanted);

        if ($existing !== null) {
            $this->carts->setQuantity((int) $existing['id'], $cartId, $wanted);

            return;
        }

        if (count($this->carts->itemsFor($cartId)) >= self::MAX_LINES) {
            throw new DomainRuleException(
                sprintf('A basket can hold %d different items.', self::MAX_LINES),
                'line_cap'
            );
        }

        // price_when_added is a record of what the customer saw, for the
        // "this has gone up" notice. It is never what they are charged.
        $this->carts->addItem($cartId, $productId, $storeId, $quantity, (string) $product['price']);
    }

    /**
     * The store a line is sourced from when the customer has not chosen one.
     *
     * Collection first, because a store that can be collected from can also
     * usually dispatch, and the reverse is not true - picking a
     * delivery-only warehouse would quietly remove collection as an option for
     * that line. Null when nothing can supply it, which leaves the line
     * flagged rather than silently sourced from somewhere that cannot fill it.
     *
     * @param array<string,mixed> $product
     */
    private function defaultSourceFor(array $product, int $quantity): ?int
    {
        $productId = (int) $product['id'];

        if (!empty($product['allows_pickup'])) {
            $options = $this->stores->pickupOptionsFor($productId, $quantity);

            if ($options !== []) {
                // Ordered by stock descending, so this is the store least
                // likely to be short by the time checkout runs.
                return (int) $options[0]['id'];
            }
        }

        if (!empty($product['allows_delivery'])) {
            $source = $this->stores->bestDeliverySourceFor($productId, $quantity);

            if ($source !== null) {
                return (int) $source['id'];
            }
        }

        return null;
    }

    public function updateQuantity(int $cartId, int $cartItemId, int $quantity): void
    {
        if ($quantity <= 0) {
            $this->remove($cartId, $cartItemId);

            return;
        }

        $quantity = $this->assertQuantity($quantity);

        $item = $this->findOwnLine($cartId, $cartItemId);

        $product = $this->products->findPublished((int) $item['product_id']);

        if ($product === null) {
            throw new DomainRuleException('That product is no longer available.', 'unavailable');
        }

        $this->assertStockLooksSufficient(
            $product,
            $item['store_id'] === null ? null : (int) $item['store_id'],
            $quantity
        );

        $this->carts->setQuantity($cartItemId, $cartId, $quantity);
    }

    /** Changes which store a line will be collected from or dispatched from. */
    public function chooseStore(int $cartId, int $cartItemId, ?int $storeId): void
    {
        $item    = $this->findOwnLine($cartId, $cartItemId);
        $product = $this->products->findPublished((int) $item['product_id']);

        if ($product === null) {
            throw new DomainRuleException('That product is no longer available.', 'unavailable');
        }

        if ($storeId !== null) {
            $this->assertStoreCanSupply($product, $storeId);
            $this->assertStockLooksSufficient($product, $storeId, (int) $item['qty']);
        }

        $this->carts->setStore($cartItemId, $cartId, $storeId);
    }

    public function remove(int $cartId, int $cartItemId): void
    {
        if ($this->carts->removeItem($cartItemId, $cartId) === 0) {
            throw new DomainRuleException('That item is not in your basket.', 'not_found');
        }
    }

    public function clear(int $cartId): void
    {
        $this->carts->clear($cartId);
    }

    /**
     * The basket as a page needs it: lines, per-seller grouping, totals, and
     * every problem that would block checkout.
     *
     * Problems are returned rather than thrown. A basket with one sold-out line
     * should still render - with that line flagged - rather than replacing the
     * whole page with an error.
     *
     * @param array<int,string>        $methods seller_id => pickup|delivery
     * @param array<string,mixed>|null $address
     * @return array<string,mixed>
     */
    public function summary(int $cartId, array $methods = [], ?array $address = null): array
    {
        $items = $this->carts->itemsFor($cartId);

        if ($items === []) {
            return [
                'empty'    => true,
                'items'    => [],
                'groups'   => [],
                'problems' => [],
                'count'    => 0,
                'items_subtotal' => '0.00',
                'delivery_total' => '0.00',
                'grand_total'    => '0.00',
            ];
        }

        $problems = $this->problemsWith($items);

        try {
            $priced = (new PricingService())->priceBasket($items, $methods, $address);
        } catch (DomainRuleException $e) {
            // A missing address or an uncovered zone is a problem to show, not
            // a page to lose.
            $problems[] = ['type' => $e->reason(), 'message' => $e->getMessage()];

            $priced = (new PricingService())->priceBasket($items, []);
        }

        return array_merge($priced, [
            'empty'    => false,
            'items'    => $items,
            'problems' => $problems,
            'count'    => array_sum(array_map(static fn (array $i): int => (int) $i['qty'], $items)),
            'can_checkout' => $problems === [],
        ]);
    }

    /**
     * Merges a guest basket into the account basket after sign-in.
     *
     * Called from the login flow. Adds quantities rather than replacing, so
     * nothing a customer saved is silently discarded.
     */
    public function mergeGuestCart(string $guestCookieHash, int $userId): int
    {
        $guest = $this->carts->activeForCookie($guestCookieHash);

        if ($guest === null) {
            return 0;
        }

        return Database::transaction(function () use ($guest, $userId): int {
            $target = $this->carts->activeForUser($userId);
            $targetId = $target !== null ? (int) $target['id'] : $this->carts->createForUser($userId);

            return $this->carts->merge((int) $guest['id'], $targetId);
        });
    }

    public function count(int $cartId): int
    {
        return $this->carts->itemCount($cartId);
    }

    /**
     * Everything that would stop this basket becoming an order.
     *
     * All of them are reported together rather than one at a time. Making a
     * customer fix four problems in four round trips is how a basket gets
     * abandoned.
     *
     * @param  list<array<string,mixed>> $items
     * @return list<array{type:string,message:string,cart_item_id?:int}>
     */
    private function problemsWith(array $items): array
    {
        $problems = [];

        foreach ($items as $item) {
            $name = (string) $item['name'];

            if ((string) $item['product_status'] !== 'published') {
                $problems[] = [
                    'type'         => 'unavailable',
                    'message'      => sprintf('%s is no longer on sale. Remove it to continue.', $name),
                    'cart_item_id' => (int) $item['cart_item_id'],
                ];
                continue;
            }

            if ((string) $item['seller_status'] !== 'active') {
                $problems[] = [
                    'type'         => 'seller_unavailable',
                    'message'      => sprintf('%s is not taking orders at the moment.', (string) $item['business_name']),
                    'cart_item_id' => (int) $item['cart_item_id'],
                ];
                continue;
            }

            if ($item['store_id'] === null) {
                $problems[] = [
                    'type'         => 'store_required',
                    'message'      => sprintf('Choose where to get %s from.', $name),
                    'cart_item_id' => (int) $item['cart_item_id'],
                ];
                continue;
            }

            if ((string) $item['store_status'] !== 'published') {
                $problems[] = [
                    'type'         => 'store_unavailable',
                    'message'      => sprintf('%s is closed. Choose another branch for %s.', (string) $item['store_name'], $name),
                    'cart_item_id' => (int) $item['cart_item_id'],
                ];
                continue;
            }

            $available = (int) $item['available'];
            $wanted    = (int) $item['qty'];

            if ($available < $wanted) {
                $problems[] = [
                    'type'         => 'insufficient_stock',
                    'message'      => $available <= 0
                        ? sprintf('%s has sold out at %s.', $name, (string) $item['store_name'])
                        : sprintf('Only %d of %s left at %s.', $available, $name, (string) $item['store_name']),
                    'cart_item_id' => (int) $item['cart_item_id'],
                ];
            }
        }

        return $problems;
    }

    /** @return array<string,mixed> */
    private function findOwnLine(int $cartId, int $cartItemId): array
    {
        foreach ($this->carts->itemsFor($cartId) as $item) {
            if ((int) $item['cart_item_id'] === $cartItemId) {
                return $item;
            }
        }

        // The same answer for "not yours" as for "does not exist". There is
        // nothing to tell apart, so nothing leaks.
        throw new DomainRuleException('That item is not in your basket.', 'not_found');
    }

    private function assertQuantity(int $quantity): int
    {
        if ($quantity < 1) {
            throw new DomainRuleException('Enter a quantity of at least one.', 'invalid_quantity');
        }

        if ($quantity > self::MAX_QTY_PER_LINE) {
            throw new DomainRuleException(
                sprintf('You can order up to %d of one item.', self::MAX_QTY_PER_LINE),
                'quantity_cap'
            );
        }

        return $quantity;
    }

    /** @param array<string,mixed> $product */
    private function assertStoreCanSupply(array $product, int $storeId): void
    {
        $store = $this->stores->findPublished($storeId);

        if ($store === null) {
            throw new DomainRuleException('That store is not available.', 'store_unavailable');
        }

        if ((int) $store['seller_id'] !== (int) $product['seller_id']) {
            throw new DomainRuleException(
                'That store does not belong to the seller of this product.',
                'store_mismatch'
            );
        }
    }

    /**
     * An advisory stock check, so the customer is told now rather than at
     * checkout. The authoritative one runs under a row lock inside the order
     * transaction - this one can be stale by the time they press Pay, and that
     * is fine.
     *
     * @param array<string,mixed> $product
     */
    private function assertStockLooksSufficient(array $product, ?int $storeId, int $wanted): void
    {
        if ($storeId === null) {
            return;
        }

        $available = (int) (new \App\Repositories\InventoryRepository())
            ->availableFor((int) $product['id'], $storeId);

        if ($available >= $wanted) {
            return;
        }

        throw new DomainRuleException(
            $available <= 0
                ? sprintf('%s has sold out at that store.', (string) $product['name'])
                : sprintf('Only %d of %s left at that store.', $available, (string) $product['name']),
            'insufficient_stock'
        );
    }
}
