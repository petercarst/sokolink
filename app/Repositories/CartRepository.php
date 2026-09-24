<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * The basket.
 *
 * `cart_items` stores `price_when_added`, and that value is used for exactly
 * one thing: telling the customer "this went up since you added it". It is
 * never used to charge them. The price charged is read live at checkout from
 * `products.price`, which is what makes "never trust prices submitted by the
 * client" easy to honour - the client has nowhere to submit one, and a stale
 * basket cannot lock in yesterday's price either.
 *
 * A guest basket is keyed by a hashed cookie value. On sign-in the guest basket
 * merges into the account basket rather than replacing it, because a customer
 * who added three things before logging in should still have the two things
 * they added last week.
 */
final class CartRepository extends Repository
{
    protected string $table = 'carts';

    /** @return array<string,mixed>|null */
    public function activeForUser(int $userId): ?array
    {
        return $this->selectOne(
            "SELECT * FROM carts WHERE user_id = :id AND status = 'active' ORDER BY id DESC LIMIT 1",
            ['id' => $userId]
        );
    }

    /** @return array<string,mixed>|null */
    public function activeForCookie(string $cookieHash): ?array
    {
        return $this->selectOne(
            "SELECT * FROM carts WHERE cookie_hash = :hash AND status = 'active' LIMIT 1",
            ['hash' => $cookieHash]
        );
    }

    public function createForUser(int $userId): int
    {
        return $this->insertInto('carts', ['user_id' => $userId, 'status' => 'active']);
    }

    public function createForCookie(string $cookieHash): int
    {
        return $this->insertInto('carts', ['cookie_hash' => $cookieHash, 'status' => 'active']);
    }

    /**
     * The basket contents, joined to live product data.
     *
     * `price` is the current price and `price_when_added` is what it was. Both
     * are returned so the page can say "this has gone up" honestly rather than
     * silently charging the new figure.
     *
     * @return list<array<string,mixed>>
     */
    public function itemsFor(int $cartId): array
    {
        return $this->select(
            "SELECT ci.id AS cart_item_id, ci.product_id, ci.store_id, ci.qty, ci.price_when_added,
                    p.name, p.slug, p.brand, p.price, p.unit, p.pack_size, p.weight_grams, p.status AS product_status,
                    p.allows_pickup, p.allows_delivery, p.seller_id,
                    sel.business_name, sel.slug AS seller_slug, sel.status AS seller_status,
                    sel.prep_hours, sel.commission_percent,
                    st.name AS store_name, st.status AS store_status,
                    st.offers_pickup, st.offers_delivery,
                    COALESCE(inv.qty_available, 0) AS available,
                    MIN(img.stored_path) AS image_path
               FROM cart_items ci
               JOIN products p  ON p.id = ci.product_id
               JOIN sellers sel ON sel.id = p.seller_id
               LEFT JOIN stores st   ON st.id = ci.store_id
               LEFT JOIN inventory inv ON inv.product_id = ci.product_id AND inv.store_id = ci.store_id
               LEFT JOIN product_images img ON img.product_id = p.id AND img.is_primary = 1
              WHERE ci.cart_id = :cart
              GROUP BY ci.id
              ORDER BY sel.business_name, ci.added_at",
            ['cart' => $cartId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findItem(int $cartId, int $productId, ?int $storeId): ?array
    {
        // A NULL store_id is a real value here - "I have not chosen where to
        // collect from yet" - so it needs the null-safe comparison rather than
        // `= NULL`, which is never true.
        return $this->selectOne(
            'SELECT * FROM cart_items
              WHERE cart_id = :cart AND product_id = :product AND store_id <=> :store',
            ['cart' => $cartId, 'product' => $productId, 'store' => $storeId]
        );
    }

    /**
     * What a customer's active basket holds, as product id => quantity.
     *
     * Read-only, and creates nothing: a page that merely asks what is in the
     * basket should not bring a basket into existence as a side effect. An
     * empty array means "no basket, or an empty one", and both answers are the
     * same to every caller.
     *
     * @return array<int,int>
     */
    public function heldQuantitiesForUser(int $userId): array
    {
        $rows = $this->select(
            "SELECT ci.product_id, SUM(ci.qty) AS qty
               FROM cart_items ci
               JOIN carts c ON c.id = ci.cart_id
              WHERE c.user_id = :user AND c.status = 'active'
              GROUP BY ci.product_id",
            ['user' => $userId]
        );

        $held = [];

        foreach ($rows as $row) {
            $held[(int) $row['product_id']] = (int) $row['qty'];
        }

        return $held;
    }

    public function addItem(int $cartId, int $productId, ?int $storeId, int $qty, string $priceWhenAdded): int
    {
        return $this->insertInto('cart_items', [
            'cart_id'          => $cartId,
            'product_id'       => $productId,
            'store_id'         => $storeId,
            'qty'              => $qty,
            'price_when_added' => $priceWhenAdded,
        ]);
    }

    public function setQuantity(int $cartItemId, int $cartId, int $qty): int
    {
        // The cart id is in the WHERE clause as well as the item id: a customer
        // who guesses another basket's item id changes nothing.
        return $this->statement(
            'UPDATE cart_items SET qty = :qty, updated_at = UTC_TIMESTAMP()
              WHERE id = :id AND cart_id = :cart',
            ['qty' => $qty, 'id' => $cartItemId, 'cart' => $cartId]
        );
    }

    public function setStore(int $cartItemId, int $cartId, ?int $storeId): int
    {
        return $this->statement(
            'UPDATE cart_items SET store_id = :store, updated_at = UTC_TIMESTAMP()
              WHERE id = :id AND cart_id = :cart',
            ['store' => $storeId, 'id' => $cartItemId, 'cart' => $cartId]
        );
    }

    public function removeItem(int $cartItemId, int $cartId): int
    {
        return $this->statement(
            'DELETE FROM cart_items WHERE id = :id AND cart_id = :cart',
            ['id' => $cartItemId, 'cart' => $cartId]
        );
    }

    public function clear(int $cartId): int
    {
        return $this->statement('DELETE FROM cart_items WHERE cart_id = :cart', ['cart' => $cartId]);
    }

    public function itemCount(int $cartId): int
    {
        return (int) $this->scalar(
            'SELECT COALESCE(SUM(qty), 0) FROM cart_items WHERE cart_id = :cart',
            ['cart' => $cartId]
        );
    }

    /**
     * Marks a basket converted once its order exists. Kept rather than deleted:
     * "what was in the basket that became this order" is a question worth being
     * able to answer, and the rows are small.
     */
    public function markConverted(int $cartId): int
    {
        return $this->statement(
            "UPDATE carts SET status = 'converted' WHERE id = :id AND status = 'active'",
            ['id' => $cartId]
        );
    }

    /**
     * Merges a guest basket into the signed-in one.
     *
     * Quantities are added rather than replaced, and the guest cart is marked
     * merged rather than deleted. Replacing would silently discard whatever the
     * customer had saved on another device.
     */
    public function merge(int $guestCartId, int $userCartId): int
    {
        $moved = 0;

        foreach ($this->itemsFor($guestCartId) as $item) {
            $existing = $this->findItem($userCartId, (int) $item['product_id'], $item['store_id'] === null ? null : (int) $item['store_id']);

            if ($existing !== null) {
                $this->setQuantity(
                    (int) $existing['id'],
                    $userCartId,
                    (int) $existing['qty'] + (int) $item['qty']
                );
            } else {
                $this->addItem(
                    $userCartId,
                    (int) $item['product_id'],
                    $item['store_id'] === null ? null : (int) $item['store_id'],
                    (int) $item['qty'],
                    (string) $item['price_when_added']
                );
            }

            $moved++;
        }

        $this->clear($guestCartId);
        $this->statement("UPDATE carts SET status = 'merged' WHERE id = :id", ['id' => $guestCartId]);

        return $moved;
    }

    /**
     * Housekeeping for the scheduled task: abandoned guest baskets that nobody
     * will come back to. Baskets belonging to an account are left alone, because
     * a customer returning after a month should find their list.
     */
    public function abandonStaleGuestCarts(int $olderThanDays = 30): int
    {
        return $this->statement(
            "UPDATE carts
                SET status = 'abandoned'
              WHERE status = 'active'
                AND user_id IS NULL
                AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)",
            ['days' => $olderThanDays]
        );
    }
}
