<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Domain\Enums\StockMovementType;
use App\Repositories\InventoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\StoreRepository;

/**
 * Reserving, releasing and consuming stock.
 *
 * The one method that matters is reserveAll(). It is the place the brief's
 * "prevent inventory from being oversold during concurrent purchases" is
 * actually honoured, and it is written to be read:
 *
 *   1. Lock every line, in product order, inside the caller's transaction.
 *   2. Reserve each line with the quantity guard repeated in the UPDATE.
 *   3. If any line fails, throw - the caller's transaction rolls the rest back.
 *
 * Step 3 is why this takes the whole basket rather than one line at a time.
 * Reserving three of four lines and then failing would leave stock held for an
 * order that will never exist.
 *
 * The failure message names the product and says how many are actually left,
 * because "something in your basket is unavailable" makes a customer re-check
 * every line themselves.
 */
final class InventoryService
{
    public function __construct(
        private readonly InventoryRepository $inventory = new InventoryRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly StoreRepository $stores = new StoreRepository(),
    ) {
    }

    /**
     * Reserves every line of a basket, or none of them.
     *
     * @param list<array{product_id:int,store_id:int,quantity:int,name?:string}> $lines
     * @param string $reference "seller_order:41", written into every movement row
     *
     * @throws DomainRuleException naming the first line that could not be met
     */
    public function reserveAll(array $lines, string $reference): void
    {
        Database::requireTransaction('Reserving stock for an order');

        if ($lines === []) {
            throw new DomainRuleException('There is nothing to reserve.', 'empty');
        }

        // Take every lock before changing anything. Interleaving locks and
        // updates is what turns a queue into a deadlock.
        $locked = $this->inventory->lockForUpdate(array_map(
            static fn (array $line): array => [
                'product_id' => $line['product_id'],
                'store_id'   => $line['store_id'],
            ],
            $lines
        ));

        foreach ($lines as $line) {
            $key = $line['product_id'] . ':' . $line['store_id'];

            if (!isset($locked[$key])) {
                throw new DomainRuleException(
                    sprintf('%s is not stocked at the store you chose.', $this->nameFor($line)),
                    'not_stocked'
                );
            }

            $row       = $locked[$key];
            $available = (int) $row['qty_on_hand'] - (int) $row['qty_reserved'];

            if ($available < $line['quantity']) {
                throw new DomainRuleException(
                    $this->shortfallMessage($this->nameFor($line), $available),
                    'insufficient_stock'
                );
            }

            // The guard is repeated inside the UPDATE. Belt and braces on
            // purpose: the read above could in principle be stale, the UPDATE
            // cannot.
            if (!$this->inventory->reserve($line['product_id'], $line['store_id'], $line['quantity'], $reference)) {
                throw new DomainRuleException(
                    $this->shortfallMessage(
                        $this->nameFor($line),
                        $this->inventory->availableFor($line['product_id'], $line['store_id'])
                    ),
                    'insufficient_stock'
                );
            }
        }
    }

    /**
     * Returns reserved units to the shelf.
     *
     * Never throws on a line that cannot be released. By the time this runs the
     * order is already being cancelled or rejected, and failing here would turn
     * one problem into two - a cancelled order that did not cancel. Failures
     * are logged instead.
     *
     * @param list<array{product_id:int,store_id:int,quantity:int}> $lines
     */
    public function releaseAll(array $lines, string $reference, string $note = ''): int
    {
        Database::requireTransaction('Releasing stock');

        $released = 0;

        foreach ($lines as $line) {
            if ($this->inventory->release($line['product_id'], $line['store_id'], $line['quantity'], $reference, $note)) {
                $released++;
                continue;
            }

            Audit::record(
                'inventory.release.failed',
                'inventory',
                $line['product_id'],
                sprintf('Could not release %d at store %d for %s', $line['quantity'], $line['store_id'], $reference)
            );
        }

        return $released;
    }

    /**
     * Converts reservations into sales. Called at handover - collection or
     * delivery - and never before.
     *
     * @param list<array{product_id:int,store_id:int,quantity:int}> $lines
     */
    public function consumeAll(array $lines, string $reference): int
    {
        Database::requireTransaction('Fulfilling stock');

        $consumed = 0;

        foreach ($lines as $line) {
            if ($this->inventory->consume($line['product_id'], $line['store_id'], $line['quantity'], $reference)) {
                $consumed++;
            }
        }

        return $consumed;
    }

    /**
     * A seller receiving new stock.
     *
     * @throws DomainRuleException if the product or store is not theirs
     */
    public function receiveStock(int $sellerId, int $productId, int $storeId, int $quantity, string $note = ''): void
    {
        if ($quantity < 1) {
            throw new DomainRuleException('Enter how many units arrived.', 'invalid_quantity');
        }

        $this->assertOwnership($sellerId, $productId, $storeId);

        Database::transaction(function () use ($productId, $storeId, $quantity, $note): void {
            $this->inventory->ensureRow($productId, $storeId);

            if (!$this->inventory->adjust($productId, $storeId, $quantity, StockMovementType::Receipt, 'restock', $note)) {
                throw new DomainRuleException('The stock level could not be updated.', 'update_failed');
            }

            Audit::record(
                'inventory.received',
                'inventory',
                $productId,
                sprintf('+%d at store %d', $quantity, $storeId)
            );
        });
    }

    /**
     * A correction: a miscount, a breakage, a loss.
     *
     * A negative adjustment cannot take the shelf below what is already
     * reserved. Those units are promised to orders that exist, and quietly
     * clamping would leave the seller believing a correction was applied when
     * it was not - so this refuses and says why.
     */
    public function adjustStock(
        int $sellerId,
        int $productId,
        int $storeId,
        int $delta,
        string $reasonCode,
        string $note
    ): void {
        if ($delta === 0) {
            throw new DomainRuleException('Enter an adjustment other than zero.', 'invalid_quantity');
        }

        if (trim($note) === '') {
            throw new DomainRuleException('Say why the count is changing.', 'reason_required');
        }

        $this->assertOwnership($sellerId, $productId, $storeId);

        Database::transaction(function () use ($productId, $storeId, $delta, $reasonCode, $note): void {
            $type = $delta > 0 ? StockMovementType::Adjustment : StockMovementType::Loss;

            if (!$this->inventory->adjust($productId, $storeId, $delta, $type, $reasonCode, $note)) {
                $row = $this->inventory->findFor($productId, $storeId);

                if ($row === null) {
                    throw new DomainRuleException('That product is not stocked at that store.', 'not_stocked');
                }

                throw new DomainRuleException(
                    sprintf(
                        'That would leave fewer units than the %d already promised to orders. Cancel or fulfil those first.',
                        (int) $row['qty_reserved']
                    ),
                    'below_reserved'
                );
            }

            Audit::record(
                'inventory.adjusted',
                'inventory',
                $productId,
                sprintf('%+d at store %d (%s)', $delta, $storeId, $reasonCode),
                null,
                null,
                $note
            );
        });
    }

    /**
     * What a product page shows: every store, how many are there, and whether
     * that store can hand them over.
     *
     * @return list<array<string,mixed>>
     */
    public function availabilityFor(int $productId): array
    {
        return $this->inventory->acrossStores($productId);
    }

    public function totalAvailable(int $productId): int
    {
        $total = 0;

        foreach ($this->inventory->acrossStores($productId) as $row) {
            $total += (int) $row['qty_available'];
        }

        return $total;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function lowStockFor(int $sellerId): array
    {
        return $this->inventory->forSeller($sellerId, true);
    }

    /**
     * Both the product and the store must belong to the seller.
     *
     * Checking only the product would let a seller move stock into somebody
     * else's shop; checking only the store would let them stock somebody else's
     * product. Neither is a theoretical worry on a marketplace where the ids
     * are sequential.
     */
    private function assertOwnership(int $sellerId, int $productId, int $storeId): void
    {
        if ($this->products->findOwnedBy($productId, $sellerId) === null) {
            throw new DomainRuleException('That product is not one of yours.', 'not_owned');
        }

        if ($this->stores->findOwnedBy($storeId, $sellerId) === null) {
            throw new DomainRuleException('That store is not one of yours.', 'not_owned');
        }
    }

    /** @param array{name?:string,product_id:int} $line */
    private function nameFor(array $line): string
    {
        if (isset($line['name']) && $line['name'] !== '') {
            return $line['name'];
        }

        $product = $this->products->find($line['product_id']);

        return $product === null ? 'That product' : (string) $product['name'];
    }

    private function shortfallMessage(string $name, int $available): string
    {
        if ($available <= 0) {
            return sprintf('%s has sold out while you were checking out.', $name);
        }

        return sprintf(
            'Only %d of %s %s left. Reduce the quantity to continue.',
            $available,
            $name,
            $available === 1 ? 'is' : 'are'
        );
    }
}
