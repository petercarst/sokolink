<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Token;
use App\Domain\Enums\ActorType;
use App\Domain\Enums\FulfilmentMethod;
use App\Domain\Enums\OrderStatus;
use App\Repositories\CartRepository;
use App\Repositories\OrderRepository;
use App\Repositories\SellerRepository;
use App\Repositories\StoreRepository;
use App\Repositories\UserRepository;
use App\Services\Payment\CashEligibility;

/**
 * Turning a basket into an order.
 *
 * This is the most consequential transaction in the application, so it is one
 * transaction and it does these things in this order:
 *
 *   1. Check the idempotency key. A repeated submission returns the ORIGINAL
 *      order rather than creating a second one.
 *   2. Re-read every price from the product rows. Nothing the client sent is
 *      used as a price, a fee or a total.
 *   3. Reserve the stock, with the row lock and the guarded UPDATE.
 *   4. Write orders, seller_orders, order_items, the pickup or delivery row,
 *      and the first status history entry.
 *   5. Mark the basket converted.
 *
 * Any failure rolls the whole thing back, so the possible outcomes are "an
 * order exists, fully formed, with its stock held" and "nothing happened".
 * There is no state in between.
 */
final class CheckoutService
{
    public function __construct(
        private readonly CartRepository $carts = new CartRepository(),
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly StoreRepository $stores = new StoreRepository(),
        private readonly SellerRepository $sellers = new SellerRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly PricingService $pricing = new PricingService(),
        private readonly InventoryService $inventory = new InventoryService(),
        private readonly OrderService $orderService = new OrderService(),
        private readonly CashEligibility $cashPolicy = new CashEligibility(),
    ) {
    }

    /**
     * Places an order.
     *
     * @param array<int,string>        $methods        seller_id => pickup|delivery
     * @param array<string,mixed>|null $address        required when any seller is delivering
     * @param array<int,int>           $pickupStores   seller_id => store_id, for collection
     *
     * @return array{order_number:string,order_id:int,replayed:bool}
     */
    public function placeOrder(
        int $cartId,
        array $methods,
        ?array $address,
        array $pickupStores = [],
        string $paymentMethod = 'sandbox',
        ?string $idempotencyKey = null
    ): array {
        $userId = Auth::id();

        if ($userId === null) {
            throw new DomainRuleException('Sign in to place an order.', 'not_authenticated');
        }

        // Checked before the transaction opens. A replay should not take locks
        // or do work - it should hand back the answer it gave the first time.
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->replayFor($idempotencyKey, $userId);

            if ($existing !== null) {
                return $existing;
            }
        }

        return Database::transaction(function () use (
            $cartId,
            $methods,
            $address,
            $pickupStores,
            $paymentMethod,
            $idempotencyKey,
            $userId
        ): array {
            $items = $this->carts->itemsFor($cartId);

            if ($items === []) {
                throw new DomainRuleException('Your basket is empty.', 'empty_basket');
            }

            $this->assertBasketIsOrderable($items);

            // Fulfilment choices, validated against what each seller and store
            // can actually do. A posted "delivery" for a store that does not
            // deliver is refused here rather than accepted and discovered by a
            // customer waiting at home.
            $methods = $this->resolveMethods($items, $methods, $address);

            $priced = $this->pricing->priceBasket($items, $methods, $address);

            // Checked here rather than only on the payment page, because the
            // page is a suggestion and this is the decision. A browser can post
            // whatever method it likes.
            if ($paymentMethod === 'cash') {
                $eligible = $this->cashPolicy->forBasket($userId, $items, $priced['grand_total']);

                if (!$eligible['allowed']) {
                    throw new DomainRuleException($eligible['reason'], 'cod_not_allowed');
                }
            }

            $user         = $this->users->find($userId) ?? throw new DomainRuleException('Account not found.', 'no_user');
            $orderNumber  = $this->orders->nextOrderNumber();
            $expiryMinutes = (int) Config::get('payment.expiry_minutes', 60);

            // Cash on collection or delivery is not "unpaid and about to
            // expire" - it is a different payment status with no expiry, and
            // the order must not be swept away by the expiry task.
            $isCash        = $paymentMethod === 'cash';
            $paymentStatus = $isCash ? 'pending_cod' : 'pending';

            $orderId = $this->orders->createOrder([
                'order_number'   => $orderNumber,
                'user_id'        => $userId,
                'contact_email'  => (string) $user['email'],
                'contact_phone'  => (string) ($address['phone'] ?? $user['phone'] ?? ''),
                'contact_name'   => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentMethod,
                'currency'       => $priced['currency'],
                'items_subtotal' => $priced['items_subtotal'],
                'delivery_total' => $priced['delivery_total'],
                'discount_total' => $priced['discount_total'],
                'grand_total'    => $priced['grand_total'],
                'placed_at'      => gmdate('Y-m-d H:i:s'),
                'expires_at'     => $isCash ? null : gmdate('Y-m-d H:i:s', time() + $expiryMinutes * 60),
            ]);

            $subIndex = 0;

            foreach ($priced['groups'] as $group) {
                $subIndex++;

                $method  = FulfilmentMethod::from($group['fulfilment_method']);
                $storeId = $this->resolveStoreFor($group, $method, $pickupStores);

                $sellerOrderId = $this->orders->createSellerOrder([
                    'order_id'          => $orderId,
                    'sub_number'        => $orderNumber . '-' . $subIndex,
                    'seller_id'         => $group['seller_id'],
                    'store_id'          => $storeId,
                    'fulfilment_method' => $method->value,
                    'status'            => OrderStatus::PendingPayment->value,
                    'subtotal'          => $group['subtotal'],
                    'delivery_fee'      => $group['delivery_fee'],
                    'total'             => $group['total'],
                    'commission_amount' => $group['commission_amount'],
                ]);

                $stockLines = [];

                foreach ($group['lines'] as $line) {
                    // The snapshot. From this moment the receipt is independent
                    // of the catalogue: renaming or delisting the product later
                    // cannot rewrite what the customer bought.
                    $this->orders->createItem([
                        'seller_order_id'    => $sellerOrderId,
                        'product_id'         => $line['product_id'],
                        'name_snapshot'      => $line['name'],
                        'sku_snapshot'       => $this->skuFor($line['product_id']),
                        'pack_size_snapshot' => $line['pack_size'],
                        'unit_price'         => $line['unit_price'],
                        'qty'                => $line['quantity'],
                        'line_total'         => $line['line_total'],
                    ]);

                    $stockLines[] = [
                        'product_id' => $line['product_id'],
                        'store_id'   => $storeId,
                        'quantity'   => $line['quantity'],
                        'name'       => $line['name'],
                    ];
                }

                // The authoritative stock check. Everything before this point
                // was advisory; this one holds the row lock.
                $this->inventory->reserveAll($stockLines, 'seller_order:' . $sellerOrderId);

                if ($method === FulfilmentMethod::Pickup) {
                    $this->createPickupRow($sellerOrderId, $storeId);
                } else {
                    $this->createDeliveryRow($sellerOrderId, $group, $address, $isCash);
                }

                $this->orders->recordTransition(
                    $sellerOrderId,
                    null,
                    OrderStatus::PendingPayment,
                    ActorType::Customer,
                    $userId,
                    'Order placed'
                );

                // Cash has nothing to wait for. There is no gateway callback
                // coming, so an order that stayed at `pending_payment` would
                // sit there until it was swept - except cash orders have no
                // expiry either, so it would sit there for ever and no seller
                // would ever see it. It goes straight to them; the money
                // changes hands at the counter or the door.
                //
                // Through OrderService, not by writing the two rows here. That
                // is the only thing allowed to move a status, and going around
                // it would skip the state machine, the audit row and the
                // customer notification - quietly, and only on this one path.
                // Database::transaction nests with savepoints, so being inside
                // this one is fine.
                if ($isCash) {
                    $this->orderService->transition(
                        $sellerOrderId,
                        OrderStatus::AwaitingSeller,
                        ActorType::System,
                        'Cash on fulfilment - nothing to collect before dispatch'
                    );
                }
            }

            $this->carts->markConverted($cartId);

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $this->recordIdempotency($idempotencyKey, $userId, $orderNumber);
            }

            Audit::record(
                'order.placed',
                'order',
                $orderId,
                sprintf('%s - %s %s', $orderNumber, $priced['currency'], $priced['grand_total'])
            );

            return ['order_number' => $orderNumber, 'order_id' => $orderId, 'replayed' => false];
        });
    }

    /**
     * Has this exact submission already produced an order?
     *
     * The key is hashed with the user id and the endpoint, so the same key from
     * two customers is two different keys - one person cannot claim another's
     * order by guessing.
     *
     * @return array{order_number:string,order_id:int,replayed:bool}|null
     */
    private function replayFor(string $key, int $userId): ?array
    {
        $hash = Token::idempotencyHash($key, $userId, 'checkout.place');

        $row = Database::selectOne(
            'SELECT response_ref FROM idempotency_keys
              WHERE key_hash = :hash
                AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())',
            ['hash' => $hash]
        );

        if ($row === null || $row['response_ref'] === null) {
            return null;
        }

        $order = $this->orders->findByNumber((string) $row['response_ref']);

        if ($order === null) {
            return null;
        }

        return [
            'order_number' => (string) $order['order_number'],
            'order_id'     => (int) $order['id'],
            'replayed'     => true,
        ];
    }

    /**
     * Records the key.
     *
     * The UNIQUE index on key_hash is what actually prevents the duplicate: if
     * two requests race past the replay check, the second INSERT fails and its
     * whole transaction rolls back - order included. The check above is the
     * fast path; the constraint is the guarantee.
     */
    private function recordIdempotency(string $key, int $userId, string $orderNumber): void
    {
        Database::statement(
            'INSERT INTO idempotency_keys (key_hash, user_id, endpoint, response_type, response_ref, expires_at)
             VALUES (:hash, :user, :endpoint, :type, :ref, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))',
            [
                'hash'     => Token::idempotencyHash($key, $userId, 'checkout.place'),
                'user'     => $userId,
                'endpoint' => 'checkout.place',
                'type'     => 'order',
                'ref'      => $orderNumber,
            ]
        );
    }

    /**
     * Refuses a basket that cannot legitimately become an order.
     *
     * Every one of these is also reported by CartService::summary() so the
     * customer sees it on the basket page. This is the second check, at the
     * moment it matters, because the basket page was rendered some time ago.
     *
     * @param list<array<string,mixed>> $items
     */
    private function assertBasketIsOrderable(array $items): void
    {
        foreach ($items as $item) {
            $name = (string) $item['name'];

            if ((string) $item['product_status'] !== 'published') {
                throw new DomainRuleException(
                    sprintf('%s is no longer on sale. Remove it from your basket to continue.', $name),
                    'unavailable'
                );
            }

            if ((string) $item['seller_status'] !== 'active') {
                throw new DomainRuleException(
                    sprintf('%s is not taking orders at the moment.', (string) $item['business_name']),
                    'seller_unavailable'
                );
            }

            if ($item['store_id'] === null) {
                throw new DomainRuleException(
                    sprintf('Choose where to get %s from before checking out.', $name),
                    'store_required'
                );
            }

            if ((string) $item['store_status'] !== 'published') {
                throw new DomainRuleException(
                    sprintf('%s is closed. Choose another branch for %s.', (string) $item['store_name'], $name),
                    'store_unavailable'
                );
            }
        }
    }

    /**
     * Validates each seller's fulfilment choice.
     *
     * A posted method is a request, not an instruction. If the store does not
     * deliver, or the product does not allow collection, the choice is refused
     * - the browser does not get to decide what a shop can do.
     *
     * @param  list<array<string,mixed>> $items
     * @param  array<int,string>         $methods
     * @param  array<string,mixed>|null  $address
     * @return array<int,string>
     */
    private function resolveMethods(array $items, array $methods, ?array $address): array
    {
        $resolved = [];

        foreach ($items as $item) {
            $sellerId = (int) $item['seller_id'];
            $wanted   = $methods[$sellerId] ?? 'pickup';

            if (!in_array($wanted, ['pickup', 'delivery'], true)) {
                throw new DomainRuleException('Choose collection or delivery for every seller.', 'invalid_method');
            }

            if ($wanted === 'delivery') {
                if ($address === null) {
                    throw new DomainRuleException('Choose a delivery address.', 'address_required');
                }

                if ((int) $item['allows_delivery'] !== 1) {
                    throw new DomainRuleException(
                        sprintf('%s is collection only.', (string) $item['name']),
                        'delivery_not_offered'
                    );
                }

                if ((int) ($item['offers_delivery'] ?? 0) !== 1) {
                    throw new DomainRuleException(
                        sprintf('%s does not deliver. Choose collection.', (string) $item['store_name']),
                        'delivery_not_offered'
                    );
                }
            } else {
                if ((int) $item['allows_pickup'] !== 1) {
                    throw new DomainRuleException(
                        sprintf('%s is delivery only.', (string) $item['name']),
                        'pickup_not_offered'
                    );
                }

                if ((int) ($item['offers_pickup'] ?? 0) !== 1) {
                    throw new DomainRuleException(
                        sprintf('%s does not offer collection.', (string) $item['store_name']),
                        'pickup_not_offered'
                    );
                }
            }

            $resolved[$sellerId] = $wanted;
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $group
     * @param array<int,int>      $pickupStores
     */
    private function resolveStoreFor(array $group, FulfilmentMethod $method, array $pickupStores): int
    {
        // Every line in a group already carries the store the customer chose in
        // the basket. A per-seller override is accepted for collection, but it
        // is validated against that seller rather than trusted.
        $storeId = $group['store_id'] !== null ? (int) $group['store_id'] : null;

        if ($method === FulfilmentMethod::Pickup && isset($pickupStores[$group['seller_id']])) {
            $storeId = (int) $pickupStores[$group['seller_id']];
        }

        if ($storeId === null) {
            throw new DomainRuleException(
                sprintf('Choose a collection point for %s.', (string) $group['business_name']),
                'store_required'
            );
        }

        $store = $this->stores->findPublished($storeId);

        if ($store === null || (int) $store['seller_id'] !== (int) $group['seller_id']) {
            throw new DomainRuleException('That store cannot fulfil this order.', 'store_mismatch');
        }

        return $storeId;
    }

    /**
     * Creates the collection record.
     *
     * No code is generated yet. The code is issued when the seller marks the
     * order ready, because a code handed out before the goods are packed is a
     * code the customer can present at a counter with nothing behind it.
     */
    private function createPickupRow(int $sellerOrderId, int $storeId): void
    {
        $store = $this->stores->findPublished($storeId);

        Database::insert('order_pickups', [
            'seller_order_id'       => $sellerOrderId,
            'store_id'              => $storeId,
            'instructions_snapshot' => (string) ($store['pickup_instructions'] ?? ''),
        ]);
    }

    /**
     * Creates the delivery task, unassigned.
     *
     * The recipient details are snapshotted onto the task. An agent must not
     * need to read the customer's address book, and a customer editing their
     * saved address later must not silently change where a parcel already on
     * its way is going.
     *
     * @param array<string,mixed>      $group
     * @param array<string,mixed>|null $address
     */
    private function createDeliveryRow(
        int $sellerOrderId,
        array $group,
        ?array $address,
        bool $isCash = false
    ): void {
        if ($address === null) {
            throw new DomainRuleException('Choose a delivery address.', 'address_required');
        }

        $addressLine = trim(implode(', ', array_filter([
            (string) ($address['street'] ?? ''),
            (string) ($address['ward'] ?? ''),
            (string) ($address['district'] ?? ''),
            (string) ($address['region'] ?? ''),
        ])));

        Database::statement(
            'INSERT INTO delivery_tasks
                (task_ref, seller_order_id, zone_id, status, recipient_name, recipient_phone,
                 address_line, landmark, instructions, fee, cod_amount, created_at)
             VALUES
                (:ref, :sub, :zone, \'unassigned\', :name, :phone, :address, :landmark, :instructions, :fee,
                 :cod, UTC_TIMESTAMP())',
            [
                'ref'          => 'TSK-' . Token::code(8),
                'sub'          => $sellerOrderId,
                'zone'         => $address['zone_id'] ?? null,
                'name'         => (string) ($address['recipient'] ?? ''),
                'phone'        => (string) ($address['phone'] ?? ''),
                'address'      => mb_substr($addressLine, 0, 400),
                'landmark'     => $address['landmark'] ?? null,
                'instructions' => $address['instructions'] ?? null,
                'fee'          => $group['delivery_fee'],
                // What the agent must collect at the door, or null when the
                // order is already paid. An agent asking for money somebody has
                // already handed over is a bad afternoon for everybody, and an
                // agent with no figure at all cannot collect anything.
                'cod'          => $isCash ? $group['total'] : null,
            ]
        );
    }

    private function skuFor(int $productId): string
    {
        $sku = Database::scalar('SELECT sku FROM products WHERE id = :id', ['id' => $productId]);

        return is_string($sku) ? $sku : '';
    }
}
