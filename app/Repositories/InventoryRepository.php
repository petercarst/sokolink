<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\Enums\StockMovementType;

/**
 * Stock levels and the append-only movement log.
 *
 * This is the file the whole oversell guarantee runs through, so the
 * interesting methods are short and the reasons are written down.
 *
 * Three defences, stacked (docs/DATABASE_DESIGN.md section 6):
 *
 *   1. lockForUpdate() takes the row locks, in a deterministic order, so two
 *      checkouts queue rather than both reading "1 available".
 *   2. reserve() repeats the quantity test inside the UPDATE's WHERE clause and
 *      requires exactly one affected row, so even a lock released early cannot
 *      let a second reservation through.
 *   3. CHECK (qty_reserved <= qty_on_hand) in the schema refuses the write if
 *      application code ever bypasses both.
 *
 * Every quantity change writes a stock_movements row. There is no code path
 * that changes stock without one - which is what makes "where did twelve units
 * go?" answerable.
 */
final class InventoryRepository extends Repository
{
    protected string $table = 'inventory';

    /**
     * Everything one store holds, for a stocktake.
     *
     * Archived products are included when they still have units on the shelf:
     * those exist physically whether or not they are listed, and a count that
     * silently omitted them would never reconcile.
     *
     * @return list<array<string,mixed>>
     */
    public function forStore(int $storeId): array
    {
        return $this->select(
            "SELECT i.product_id, i.qty_on_hand, i.qty_reserved, i.qty_available, i.low_stock_threshold,
                    p.name, p.slug, p.sku, p.pack_size, p.status
               FROM inventory i
               JOIN products p ON p.id = i.product_id
              WHERE i.store_id = :store
                AND (p.status <> 'archived' OR i.qty_on_hand > 0)
              ORDER BY p.name",
            ['store' => $storeId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findFor(int $productId, int $storeId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM inventory WHERE product_id = :p AND store_id = :s',
            ['p' => $productId, 's' => $storeId]
        );
    }

    public function availableFor(int $productId, int $storeId): int
    {
        $qty = $this->scalar(
            'SELECT qty_available FROM inventory WHERE product_id = :p AND store_id = :s',
            ['p' => $productId, 's' => $storeId]
        );

        return $qty === null ? 0 : (int) $qty;
    }

    /**
     * Availability of one product across every store that stocks it - what a
     * product page shows, and what the checkout uses to offer a collection
     * point.
     *
     * @return list<array<string,mixed>>
     */
    public function acrossStores(int $productId): array
    {
        return $this->select(
            "SELECT i.store_id, i.qty_on_hand, i.qty_reserved, i.qty_available, i.low_stock_threshold,
                    s.name AS store_name, s.slug AS store_slug, s.district, s.region,
                    s.offers_pickup, s.offers_delivery, s.collection_window_hours
               FROM inventory i
               JOIN stores s ON s.id = i.store_id
              WHERE i.product_id = :p AND s.status = 'published'
              ORDER BY i.qty_available DESC, s.name",
            ['p' => $productId]
        );
    }

    /**
     * Takes row locks on every line in a basket, in a deterministic order.
     *
     * The ORDER BY is not decoration. Two checkouts containing the same two
     * products in different orders would deadlock without it: one locks A then
     * waits for B, the other locks B then waits for A. Locking in product id
     * order means the second simply waits for the first to finish.
     *
     * MariaDB 10.4 has no SKIP LOCKED, so waiting is the behaviour we want
     * anyway - the second customer should be told the truth a moment later
     * rather than be told "unavailable" because somebody else held the row.
     *
     * @param  list<array{product_id:int,store_id:int}> $lines
     * @return array<string,array<string,mixed>> keyed "productId:storeId"
     */
    public function lockForUpdate(array $lines): array
    {
        Database::requireTransaction('Locking inventory');

        if ($lines === []) {
            return [];
        }

        // Sort first, then build the IN list from the sorted set, so the lock
        // order is a property of the data rather than of how the caller
        // happened to build the basket.
        usort($lines, static fn (array $a, array $b): int
            => [$a['product_id'], $a['store_id']] <=> [$b['product_id'], $b['store_id']]);

        $conditions = [];
        $bindings   = [];

        foreach ($lines as $i => $line) {
            $conditions[]          = "(product_id = :p{$i} AND store_id = :s{$i})";
            $bindings["p{$i}"]     = $line['product_id'];
            $bindings["s{$i}"]     = $line['store_id'];
        }

        $rows = $this->select(
            'SELECT id, product_id, store_id, qty_on_hand, qty_reserved
               FROM inventory
              WHERE ' . implode(' OR ', $conditions) . '
              ORDER BY product_id, store_id
                FOR UPDATE',
            $bindings
        );

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row['product_id'] . ':' . $row['store_id']] = $row;
        }

        return $keyed;
    }

    /**
     * Reserves stock, or returns false.
     *
     * The guard is repeated in the WHERE clause and the affected-row count is
     * checked. That check is the whole point: a reservation that "probably
     * worked" is how two customers are sold the same last unit.
     *
     * Returns false rather than throwing, because "it went while you were
     * deciding" is an ordinary outcome the caller must report per line, not an
     * exceptional one.
     */
    public function reserve(int $productId, int $storeId, int $quantity, string $reference): bool
    {
        Database::requireTransaction('Reserving stock');

        if ($quantity < 1) {
            return false;
        }

        $affected = $this->statement(
            'UPDATE inventory
                SET qty_reserved = qty_reserved + :qty
              WHERE product_id = :p
                AND store_id = :s
                AND (qty_on_hand - qty_reserved) >= :qty2',
            ['qty' => $quantity, 'p' => $productId, 's' => $storeId, 'qty2' => $quantity]
        );

        if ($affected !== 1) {
            return false;
        }

        $this->logMovement(
            $productId,
            $storeId,
            StockMovementType::Reservation,
            -$quantity,
            $reference,
            'Reserved for ' . $reference
        );

        return true;
    }

    /**
     * Returns reserved units to the shelf - a rejection, a cancellation, an
     * unpaid expiry.
     *
     * The GREATEST() floor stops a double release from driving qty_reserved
     * negative. It should not be reachable, and if it ever is, silently going
     * below zero would hide the bug rather than contain it.
     */
    public function release(int $productId, int $storeId, int $quantity, string $reference, string $note = ''): bool
    {
        Database::requireTransaction('Releasing stock');

        if ($quantity < 1) {
            return false;
        }

        $affected = $this->statement(
            'UPDATE inventory
                SET qty_reserved = GREATEST(0, CAST(qty_reserved AS SIGNED) - :qty)
              WHERE product_id = :p AND store_id = :s',
            ['qty' => $quantity, 'p' => $productId, 's' => $storeId]
        );

        if ($affected < 1) {
            return false;
        }

        $this->logMovement(
            $productId,
            $storeId,
            StockMovementType::Release,
            $quantity,
            $reference,
            $note !== '' ? $note : 'Released from ' . $reference
        );

        return true;
    }

    /**
     * Converts a reservation into a sale: the units leave both the reserved
     * count and the shelf.
     *
     * Called when goods are handed over - collected or delivered - never
     * before. Until then the customer might still not turn up, and the stock is
     * promised rather than gone.
     */
    public function consume(int $productId, int $storeId, int $quantity, string $reference): bool
    {
        Database::requireTransaction('Fulfilling stock');

        if ($quantity < 1) {
            return false;
        }

        $affected = $this->statement(
            'UPDATE inventory
                SET qty_on_hand  = GREATEST(0, CAST(qty_on_hand AS SIGNED) - :qty),
                    qty_reserved = GREATEST(0, CAST(qty_reserved AS SIGNED) - :qty2)
              WHERE product_id = :p AND store_id = :s',
            ['qty' => $quantity, 'qty2' => $quantity, 'p' => $productId, 's' => $storeId]
        );

        if ($affected < 1) {
            return false;
        }

        $this->logMovement(
            $productId,
            $storeId,
            StockMovementType::Fulfilment,
            -$quantity,
            $reference,
            'Handed over on ' . $reference
        );

        return true;
    }

    /**
     * A seller adding stock, or correcting a count.
     *
     * An adjustment can be negative - a breakage, a miscount - but it can never
     * take qty_on_hand below what is already reserved, because those units are
     * promised to orders that exist. The guard refuses rather than silently
     * clamping, so the seller finds out.
     */
    public function adjust(
        int $productId,
        int $storeId,
        int $delta,
        StockMovementType $type,
        string $reasonCode = '',
        string $note = ''
    ): bool {
        Database::requireTransaction('Adjusting stock');

        if ($delta === 0) {
            return false;
        }

        $affected = $this->statement(
            'UPDATE inventory
                SET qty_on_hand = CAST(qty_on_hand AS SIGNED) + :delta
              WHERE product_id = :p
                AND store_id = :s
                AND CAST(qty_on_hand AS SIGNED) + :delta2 >= CAST(qty_reserved AS SIGNED)',
            ['delta' => $delta, 'p' => $productId, 's' => $storeId, 'delta2' => $delta]
        );

        if ($affected !== 1) {
            return false;
        }

        $this->logMovement($productId, $storeId, $type, $delta, '', $note, $reasonCode);

        return true;
    }

    /**
     * Creates the inventory row for a product at a store, if it does not exist.
     * Separate from adjust() because "this store stocks this product" and "this
     * store has six of them" are different facts.
     */
    public function ensureRow(int $productId, int $storeId, int $lowStockThreshold = 0): int
    {
        $this->statement(
            'INSERT IGNORE INTO inventory (product_id, store_id, qty_on_hand, qty_reserved, low_stock_threshold)
             VALUES (:p, :s, 0, 0, :threshold)',
            ['p' => $productId, 's' => $storeId, 'threshold' => $lowStockThreshold]
        );

        return (int) $this->scalar(
            'SELECT id FROM inventory WHERE product_id = :p AND store_id = :s',
            ['p' => $productId, 's' => $storeId]
        );
    }

    public function setLowStockThreshold(int $productId, int $storeId, int $threshold): int
    {
        return $this->statement(
            'UPDATE inventory SET low_stock_threshold = :t WHERE product_id = :p AND store_id = :s',
            ['t' => max(0, $threshold), 'p' => $productId, 's' => $storeId]
        );
    }

    // ---- Seller-scoped reads ------------------------------------------------

    /**
     * Stock across a seller's own stores. The seller id is a parameter and it
     * is in the WHERE clause - rows belonging to other sellers are never
     * fetched, not fetched and then filtered.
     *
     * @return list<array<string,mixed>>
     */
    public function forSeller(int $sellerId, bool $lowStockOnly = false): array
    {
        $extra = $lowStockOnly ? 'AND i.qty_available <= i.low_stock_threshold' : '';

        return $this->select(
            "SELECT i.product_id, i.store_id, i.qty_on_hand, i.qty_reserved, i.qty_available,
                    i.low_stock_threshold, i.updated_at,
                    p.name AS product_name, p.sku, p.status AS product_status,
                    s.name AS store_name
               FROM inventory i
               JOIN products p ON p.id = i.product_id
               JOIN stores   s ON s.id = i.store_id
              WHERE p.seller_id = :seller AND s.seller_id = :seller2
                {$extra}
              ORDER BY (i.qty_available <= i.low_stock_threshold) DESC, p.name, s.name",
            ['seller' => $sellerId, 'seller2' => $sellerId]
        );
    }

    public function lowStockCountFor(int $sellerId): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*)
               FROM inventory i
               JOIN products p ON p.id = i.product_id
              WHERE p.seller_id = :seller AND i.qty_available <= i.low_stock_threshold',
            ['seller' => $sellerId]
        );
    }

    /**
     * The movement history for one product at one store, newest first. This is
     * the answer to "where did the stock go", so it includes who did it.
     *
     * @return list<array<string,mixed>>
     */
    public function movementsFor(int $productId, int $storeId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        return $this->select(
            "SELECT m.movement_type, m.qty_delta, m.qty_after, m.reason_code, m.note,
                    m.reference_type, m.reference_id, m.created_at,
                    CONCAT(COALESCE(u.first_name, 'System'), ' ', COALESCE(u.last_name, '')) AS actor
               FROM stock_movements m
               LEFT JOIN users u ON u.id = m.actor_user_id
              WHERE m.product_id = :p AND m.store_id = :s
              ORDER BY m.created_at DESC, m.id DESC
              LIMIT {$limit}",
            ['p' => $productId, 's' => $storeId]
        );
    }

    /**
     * Writes the movement row.
     *
     * qty_after is read back rather than calculated, so the log records what
     * the database actually holds rather than what the caller believed it
     * would hold. If those two ever differ, the log is the one worth trusting.
     */
    private function logMovement(
        int $productId,
        int $storeId,
        StockMovementType $type,
        int $delta,
        string $reference = '',
        string $note = '',
        string $reasonCode = ''
    ): void {
        $qtyAfter = (int) $this->scalar(
            'SELECT qty_available FROM inventory WHERE product_id = :p AND store_id = :s',
            ['p' => $productId, 's' => $storeId]
        );

        [$referenceType, $referenceId] = $this->parseReference($reference);

        $this->insertInto('stock_movements', [
            'product_id'     => $productId,
            'store_id'       => $storeId,
            'movement_type'  => $type->value,
            'qty_delta'      => $delta,
            'qty_after'      => $qtyAfter,
            'reason_code'    => $reasonCode !== '' ? mb_substr($reasonCode, 0, 40) : null,
            'note'           => $note !== '' ? mb_substr($note, 0, 500) : null,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'actor_user_id'  => Auth::id(),
        ]);
    }

    /**
     * References arrive as "seller_order:41" or as a free-text note. Both are
     * accepted; only the structured form becomes a linkable reference.
     *
     * @return array{0:?string,1:?int}
     */
    private function parseReference(string $reference): array
    {
        if ($reference === '' || !str_contains($reference, ':')) {
            return [null, null];
        }

        [$type, $id] = explode(':', $reference, 2);

        return ctype_digit($id) ? [mb_substr($type, 0, 40), (int) $id] : [null, null];
    }
}
