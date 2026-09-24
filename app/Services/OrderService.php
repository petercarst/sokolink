<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Domain\Enums\ActorType;
use App\Domain\Enums\FulfilmentMethod;
use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;
use App\Domain\Enums\OrderStatus;
use App\Domain\OrderStateMachine;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\SellerRepository;

/**
 * The life of an order after it is placed.
 *
 * Every status change in the application goes through transition(). Nothing
 * writes `seller_orders.status` directly. That single funnel is what makes the
 * following true everywhere rather than in most places:
 *
 *   - the state machine decides whether the move is legal;
 *   - the actor is checked against who may make it;
 *   - the UPDATE is guarded on the current status, so two dashboards cannot
 *     both act on the same order;
 *   - a history row is written;
 *   - stock is released or consumed as the new status requires;
 *   - the customer is told.
 *
 * Each of those is easy to forget once. None of them can be forgotten here.
 */
final class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly SellerRepository $sellers = new SellerRepository(),
        private readonly InventoryService $inventory = new InventoryService(),
        private readonly NotificationRepository $notifications = new NotificationRepository(),
    ) {
    }

    /**
     * The only way an order's status changes.
     *
     * @param ActorType $actor who is doing it - derived from the session, never posted
     * @param string|null $reason mandatory for the statuses that tell a customer "no"
     */
    public function transition(
        int $sellerOrderId,
        OrderStatus $to,
        ActorType $actor,
        ?string $reason = null
    ): void {
        Database::transaction(function () use ($sellerOrderId, $to, $actor, $reason): void {
            $sub = $this->orders->findSellerOrder($sellerOrderId);

            if ($sub === null) {
                throw new DomainRuleException('That order could not be found.', 'not_found');
            }

            $from   = OrderStatus::fromDatabase((string) $sub['status']);
            $method = FulfilmentMethod::fromDatabase((string) $sub['fulfilment_method']);

            // Throws with a message written for whoever is reading it.
            OrderStateMachine::assertCan($from, $to, $method, $actor);

            if (OrderStateMachine::requiresReason($to) && trim((string) $reason) === '') {
                throw new DomainRuleException(
                    'Say why, so the customer knows what happened.',
                    'reason_required'
                );
            }

            // Guarded on `status = $from`. If another tab moved it first, this
            // affects zero rows and we stop - rather than overwriting whatever
            // they did.
            $moved = $this->orders->transitionSellerOrder($sellerOrderId, $from, $to, $reason);

            if ($moved !== 1) {
                throw new DomainRuleException(
                    'This order has already moved on. Reload to see where it is now.',
                    'stale'
                );
            }

            $this->orders->recordTransition($sellerOrderId, $from, $to, $actor, Auth::id(), $reason);

            $this->applyStockEffect($sellerOrderId, $to);
            $this->notifyCustomer($sub, $to, $reason);

            Audit::record(
                'order.transition',
                'seller_order',
                $sellerOrderId,
                sprintf('%s -> %s by %s', $from->value, $to->value, $actor->value),
                ['status' => $from->value],
                ['status' => $to->value],
                $reason
            );
        });
    }

    // ---- Seller actions -----------------------------------------------------

    /**
     * A seller accepting an order.
     *
     * The sub-order is fetched with the seller id in the WHERE clause, so a
     * seller who posts another seller's id gets "not found" - the same answer
     * as for an id that does not exist.
     */
    public function acceptAsSeller(int $sellerOrderId, int $sellerId): void
    {
        $sub = $this->requireOwnedBy($sellerOrderId, $sellerId);

        if ((string) $sub['payment_status'] !== 'paid' && (string) $sub['payment_status'] !== 'pending_cod') {
            throw new DomainRuleException(
                'Wait for payment to clear before accepting this order.',
                'not_paid'
            );
        }

        $this->transition($sellerOrderId, OrderStatus::Confirmed, ActorType::Seller);
    }

    public function rejectAsSeller(int $sellerOrderId, int $sellerId, string $reason): void
    {
        $this->requireOwnedBy($sellerOrderId, $sellerId);

        if (trim($reason) === '') {
            throw new DomainRuleException('Say why you cannot fulfil this order.', 'reason_required');
        }

        $this->transition($sellerOrderId, OrderStatus::RejectedSeller, ActorType::Seller, $reason);

        // A rejection owes the customer their money back - but only if money
        // actually changed hands. An order rejected before payment cleared is
        // simply rejected; sending it to refund_pending would put a refund of
        // nothing on somebody's queue.
        $this->requestRefundIfPaid($sellerOrderId, 'Seller rejected the order');
    }

    public function startPreparing(int $sellerOrderId, int $sellerId): void
    {
        $this->requireOwnedBy($sellerOrderId, $sellerId);

        $this->transition($sellerOrderId, OrderStatus::Preparing, ActorType::Seller);
    }

    /**
     * Marks an order ready.
     *
     * Which status that means depends on the fulfilment method, and the state
     * machine will refuse the wrong one - so the method is read from the order
     * rather than passed in.
     */
    public function markReady(int $sellerOrderId, int $sellerId): void
    {
        $sub    = $this->requireOwnedBy($sellerOrderId, $sellerId);
        $method = FulfilmentMethod::fromDatabase((string) $sub['fulfilment_method']);

        $to = $method === FulfilmentMethod::Pickup
            ? OrderStatus::ReadyForPickup
            : OrderStatus::ReadyForDispatch;

        $this->transition($sellerOrderId, $to, ActorType::Seller);
    }

    // ---- Customer actions ---------------------------------------------------

    /**
     * A customer cancelling.
     *
     * Only while the seller has not started work. Once an order is being
     * prepared the seller has committed goods and time, and cancelling becomes
     * a support conversation rather than a button.
     */
    public function cancelAsCustomer(string $orderNumber, int $userId, string $reason): void
    {
        $order = $this->orders->findForCustomer($orderNumber, $userId);

        if ($order === null) {
            throw new DomainRuleException('That order could not be found.', 'not_found');
        }

        $subs      = $this->orders->sellerOrdersFor((int) $order['id']);
        $cancelled = 0;

        foreach ($subs as $sub) {
            $status = OrderStatus::fromDatabase((string) $sub['status']);

            if (!OrderStateMachine::can($status, OrderStatus::CancelledCustomer, FulfilmentMethod::fromDatabase((string) $sub['fulfilment_method']), ActorType::Customer)) {
                continue;
            }

            $this->transition(
                (int) $sub['id'],
                OrderStatus::CancelledCustomer,
                ActorType::Customer,
                $reason !== '' ? $reason : 'Cancelled by the customer'
            );

            $this->requestRefundIfPaid((int) $sub['id'], 'Cancelled by the customer');

            $cancelled++;
        }

        if ($cancelled === 0) {
            throw new DomainRuleException(
                'This order has gone too far to cancel. Contact support and we will help.',
                'too_late'
            );
        }
    }

    // ---- Reads --------------------------------------------------------------

    /**
     * The full order as a customer sees it: the parent, every seller part, the
     * lines and the history.
     *
     * @return array<string,mixed>
     */
    public function customerView(string $orderNumber, int $userId): array
    {
        $order = $this->orders->findForCustomer($orderNumber, $userId);

        if ($order === null) {
            throw new DomainRuleException('That order could not be found.', 'not_found');
        }

        $parts = [];

        foreach ($this->orders->sellerOrdersFor((int) $order['id']) as $sub) {
            $status = OrderStatus::fromDatabase((string) $sub['status']);

            $parts[] = array_merge($sub, [
                'status_enum'  => $status,
                'status_label' => $status->label(),
                'status_tone'  => $status->tone(),
                'items'        => $this->orders->itemsFor((int) $sub['id']),
                'history'      => $this->orders->historyFor((int) $sub['id']),
                'can_cancel'   => OrderStateMachine::can(
                    $status,
                    OrderStatus::CancelledCustomer,
                    FulfilmentMethod::fromDatabase((string) $sub['fulfilment_method']),
                    ActorType::Customer
                ),
            ]);
        }

        return ['order' => $order, 'parts' => $parts];
    }

    /**
     * The order as its seller sees it - only their part, and without the
     * customer's other sellers, other lines or full contact history.
     *
     * @return array<string,mixed>
     */
    public function sellerView(int $sellerOrderId, int $sellerId): array
    {
        $sub = $this->requireOwnedBy($sellerOrderId, $sellerId);

        $status = OrderStatus::fromDatabase((string) $sub['status']);
        $method = FulfilmentMethod::fromDatabase((string) $sub['fulfilment_method']);

        return [
            'sub_order'    => $sub,
            'status_enum'  => $status,
            'status_label' => $status->label(),
            'items'        => $this->orders->itemsFor($sellerOrderId),
            'history'      => $this->orders->historyFor($sellerOrderId),
            'next_steps'   => array_map(
                static fn (OrderStatus $s): array => ['value' => $s->value, 'label' => $s->label()],
                OrderStateMachine::nextFor($status, $method, ActorType::Seller)
            ),
        ];
    }

    // ---- Internals ----------------------------------------------------------

    /**
     * Moves a cancelled or rejected sub-order on to `refund_pending`, but only
     * when the customer has actually paid.
     *
     * Cash on collection is not money we hold, so it is not money we owe back
     * either - those orders end at cancelled or rejected.
     */
    private function requestRefundIfPaid(int $sellerOrderId, string $reason): void
    {
        $paymentStatus = Database::scalar(
            'SELECT o.payment_status
               FROM seller_orders so
               JOIN orders o ON o.id = so.order_id
              WHERE so.id = :id',
            ['id' => $sellerOrderId]
        );

        if (!in_array((string) $paymentStatus, ['paid', 'partially_refunded'], true)) {
            return;
        }

        $this->transition($sellerOrderId, OrderStatus::RefundPending, ActorType::System, $reason);
    }

    /**
     * Applies the stock consequence of a status change.
     *
     * The rules are the state machine's, not this method's - it asks whether a
     * status releases or consumes stock rather than listing statuses itself, so
     * adding a status in one place cannot leave stock handling behind in
     * another.
     */
    private function applyStockEffect(int $sellerOrderId, OrderStatus $to): void
    {
        $lines = $this->orders->stockLinesFor($sellerOrderId);

        if ($lines === []) {
            return;
        }

        if (OrderStateMachine::releasesStock($to)) {
            $this->inventory->releaseAll(
                $lines,
                'seller_order:' . $sellerOrderId,
                'Released on ' . $to->value
            );

            return;
        }

        if (OrderStateMachine::consumesStock($to) && $to !== OrderStatus::Completed) {
            // Completed follows collected/delivered, and the stock has already
            // gone at that point. Consuming twice would take the units off the
            // shelf a second time.
            $this->inventory->consumeAll($lines, 'seller_order:' . $sellerOrderId);
        }
    }

    /**
     * Queues the customer notification for a transition.
     *
     * Only for statuses a customer actually needs to hear about. "Preparing"
     * and "assigned" are internal progress; being told about each one trains
     * people to ignore the messages that matter.
     *
     * @param array<string,mixed> $sub
     */
    private function notifyCustomer(array $sub, OrderStatus $to, ?string $reason): void
    {
        $template = match ($to) {
            OrderStatus::Confirmed        => 'order.accepted',
            OrderStatus::ReadyForPickup   => 'order.ready_for_collection',
            OrderStatus::OutForDelivery   => 'order.out_for_delivery',
            OrderStatus::Delivered        => 'order.delivered',
            OrderStatus::Collected        => 'order.collected',
            OrderStatus::RejectedSeller   => 'order.rejected',
            OrderStatus::DeliveryFailed   => 'order.delivery_failed',
            OrderStatus::CollectionOverdue => 'order.collection_overdue',
            OrderStatus::ExpiredUnpaid    => 'order.expired',
            OrderStatus::Refunded         => 'order.refunded',
            default                       => null,
        };

        if ($template === null) {
            return;
        }

        $userId = Database::scalar(
            'SELECT user_id FROM orders WHERE id = :id',
            ['id' => (int) $sub['order_id']]
        );

        if ($userId === null) {
            return;
        }

        $category = in_array($to, [
            OrderStatus::ReadyForPickup,
            OrderStatus::OutForDelivery,
            OrderStatus::Delivered,
            OrderStatus::Collected,
            OrderStatus::DeliveryFailed,
            OrderStatus::CollectionOverdue,
        ], true)
            ? NotificationCategory::PickupDelivery
            : NotificationCategory::OrderUpdates;

        $this->notifications->queue(
            (int) $userId,
            NotificationChannel::Email,
            $category,
            $template,
            [
                'sub_number' => (string) $sub['sub_number'],
                'status'     => $to->value,
                'reason'     => $reason,
            ],
            false,
            'seller_order',
            (int) $sub['id']
        );
    }

    /**
     * @return array<string,mixed>
     * @throws DomainRuleException when the sub-order is not this seller's
     */
    private function requireOwnedBy(int $sellerOrderId, int $sellerId): array
    {
        if (!$this->sellers->canTrade($sellerId)) {
            throw new DomainRuleException(
                'Your seller account is not active, so you cannot work on orders.',
                'seller_inactive'
            );
        }

        $sub = $this->orders->findSellerOrderOwnedBy($sellerOrderId, $sellerId);

        if ($sub === null) {
            throw new DomainRuleException('That order could not be found.', 'not_found');
        }

        return $sub;
    }
}
