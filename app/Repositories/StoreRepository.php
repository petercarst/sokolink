<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Stores: the physical places goods are collected from and dispatched from.
 *
 * `offers_pickup` and `offers_delivery` are read from here at checkout, not
 * from the product. A product can allow collection while the store it sits in
 * does not offer it, and the store is the one that has to hand the bag over.
 */
final class StoreRepository extends Repository
{
    protected string $table = 'stores';

    /** @return array<string,mixed>|null */
    public function findPublishedBySlug(string $slug): ?array
    {
        return $this->selectOne(
            "SELECT s.*, sel.business_name, sel.rating_avg AS seller_rating, sel.prep_hours
               FROM stores s
               JOIN sellers sel ON sel.id = s.seller_id
              WHERE s.slug = :slug AND s.status = 'published' AND sel.status = 'active'",
            ['slug' => $slug]
        );
    }

    /**
     * Every published store, with its seller and a live product count.
     *
     * Used by the home page strip and the catalogue's seller filter. The count
     * is a correlated subquery rather than a stored column: a denormalised
     * count that nothing updates when a product is archived is worse than no
     * count at all.
     *
     * Passing a seller narrows it to that seller's branches - the "our other
     * collection points" list on a store page. Draft and paused branches are
     * excluded either way: a store page must not send somebody to a shop that
     * is not open for orders.
     *
     * @return list<array<string,mixed>>
     */
    public function allPublished(?int $sellerId = null): array
    {
        $scope    = $sellerId !== null ? 'AND s.seller_id = :seller' : '';
        $bindings = $sellerId !== null ? ['seller' => $sellerId] : [];

        return $this->select(
            "SELECT s.*, sel.business_name, sel.slug AS seller_slug,
                    (SELECT COUNT(DISTINCT i.product_id)
                       FROM inventory i
                       JOIN products p ON p.id = i.product_id
                      WHERE i.store_id = s.id AND p.status = 'published'
                    ) AS product_count
               FROM stores s
               JOIN sellers sel ON sel.id = s.seller_id
              WHERE s.status = 'published' AND sel.status = 'active'
                {$scope}
              ORDER BY s.region, s.district, s.name",
            $bindings
        );
    }

    /** @return array<string,mixed>|null */
    public function findPublished(int $id): ?array
    {
        return $this->selectOne(
            "SELECT s.*, sel.business_name, sel.prep_hours
               FROM stores s
               JOIN sellers sel ON sel.id = s.seller_id
              WHERE s.id = :id AND s.status = 'published' AND sel.status = 'active'",
            ['id' => $id]
        );
    }

    /**
     * Stores that can actually fulfil a given product by collection, with
     * enough stock.
     *
     * Every condition that makes a collection point offerable is here rather
     * than spread across the checkout: the store publishes, the store offers
     * collection, the product allows it, and the units exist. A store that
     * fails any one of them never appears in the picker.
     *
     * @return list<array<string,mixed>>
     */
    public function pickupOptionsFor(int $productId, int $quantity = 1): array
    {
        return $this->select(
            "SELECT s.id, s.slug, s.name, s.region, s.district, s.street, s.landmark,
                    s.phone, s.pickup_instructions, s.collection_window_hours,
                    s.latitude, s.longitude,
                    i.qty_available
               FROM stores s
               JOIN inventory i ON i.store_id = s.id AND i.product_id = :product
               JOIN products p  ON p.id = i.product_id
               JOIN sellers sel ON sel.id = s.seller_id
              WHERE s.status = 'published'
                AND sel.status = 'active'
                AND s.offers_pickup = 1
                AND p.allows_pickup = 1
                AND i.qty_available >= :quantity
              ORDER BY i.qty_available DESC, s.name",
            ['product' => $productId, 'quantity' => max(1, $quantity)]
        );
    }

    /**
     * The store a delivery order should be dispatched from.
     *
     * Picks the one with the most available stock, so a marginal store is not
     * chosen and then found to be short. Returns null when nothing qualifies,
     * which the checkout must treat as "this cannot be delivered" rather than
     * falling back to an arbitrary store.
     *
     * @return array<string,mixed>|null
     */
    public function bestDeliverySourceFor(int $productId, int $quantity = 1): ?array
    {
        return $this->selectOne(
            "SELECT s.id, s.slug, s.name, s.region, s.district, i.qty_available
               FROM stores s
               JOIN inventory i ON i.store_id = s.id AND i.product_id = :product
               JOIN products p  ON p.id = i.product_id
               JOIN sellers sel ON sel.id = s.seller_id
              WHERE s.status = 'published'
                AND sel.status = 'active'
                AND s.offers_delivery = 1
                AND p.allows_delivery = 1
                AND i.qty_available >= :quantity
              ORDER BY i.qty_available DESC
              LIMIT 1",
            ['product' => $productId, 'quantity' => max(1, $quantity)]
        );
    }

    /**
     * Opening hours for the week.
     *
     * These are LOCAL wall-clock times, not UTC. "We open at 07:30" is a local
     * fact about a shop; converting it to UTC would move a shop's opening time
     * if the offset ever changed. The column is commented in the schema for the
     * same reason.
     *
     * @return list<array<string,mixed>>
     */
    public function hoursFor(int $storeId): array
    {
        return $this->select(
            'SELECT day_of_week, opens_at, closes_at, is_closed
               FROM store_hours
              WHERE store_id = :id
              ORDER BY day_of_week',
            ['id' => $storeId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function forSeller(int $sellerId): array
    {
        return $this->select(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM inventory i WHERE i.store_id = s.id) AS product_lines,
                    (SELECT COUNT(*) FROM seller_orders so
                      WHERE so.store_id = s.id AND so.status NOT IN
                            (\'completed\', \'refunded\', \'expired_unpaid\', \'cancelled_customer\', \'rejected_seller\')
                    ) AS open_orders
               FROM stores s
              WHERE s.seller_id = :seller
              ORDER BY s.name',
            ['seller' => $sellerId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findOwnedBy(int $storeId, int $sellerId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM stores WHERE id = :id AND seller_id = :seller',
            ['id' => $storeId, 'seller' => $sellerId]
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertInto('stores', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateOwned(int $storeId, int $sellerId, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        return $this->updateWhere(
            'stores',
            $data,
            'id = :id AND seller_id = :seller',
            ['id' => $storeId, 'seller' => $sellerId]
        );
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql      = 'SELECT COUNT(*) FROM stores WHERE slug = :slug';
        $bindings = ['slug' => $slug];

        if ($exceptId !== null) {
            $sql               .= ' AND id <> :except';
            $bindings['except'] = $exceptId;
        }

        return (int) $this->scalar($sql, $bindings) > 0;
    }

    /**
     * Whether a store is open at a given local time. Used to warn a customer
     * that a collection point is closed rather than to stop them ordering -
     * a shop being shut now says nothing about tomorrow, when they will collect.
     */
    public function isOpenAt(int $storeId, int $weekday, string $localTime): bool
    {
        $row = $this->selectOne(
            'SELECT opens_at, closes_at, is_closed
               FROM store_hours
              WHERE store_id = :id AND day_of_week = :weekday',
            ['id' => $storeId, 'weekday' => $weekday]
        );

        if ($row === null || (int) $row['is_closed'] === 1
            || $row['opens_at'] === null || $row['closes_at'] === null) {
            return false;
        }

        return $localTime >= (string) $row['opens_at'] && $localTime <= (string) $row['closes_at'];
    }
}
