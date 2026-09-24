<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Where one seller part of an order has got to. The transitions live in OrderStateMachine.
 *
 * Mirrors `seller_orders`.`status`. The two are asserted equal by
 * tests/Integration/enum_parity_test.php - if a migration adds a value here or
 * there and not the other, that test fails rather than something subtler
 * happening in production.
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case AwaitingSeller = 'awaiting_seller';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case ReadyForPickup = 'ready_for_pickup';
    case Collected = 'collected';
    case CollectionOverdue = 'collection_overdue';
    case ReturnedToStock = 'returned_to_stock';
    case ReadyForDispatch = 'ready_for_dispatch';
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case ReturnedToSeller = 'returned_to_seller';
    case Completed = 'completed';
    case CancelledCustomer = 'cancelled_customer';
    case RejectedSeller = 'rejected_seller';
    case ExpiredUnpaid = 'expired_unpaid';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** Null-tolerant: a NULL column becomes null rather than an exception. */
    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /**
     * Throws rather than returning null. Used where a value came from our own
     * database and being unable to read it means the schema and the code have
     * diverged - which should be loud.
     */
    public static function fromDatabase(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new \ValueError(sprintf('%s has no case for "%s".', static::class, $value));
    }

    /**
     * Written for a customer reading an order page, not for a developer reading
     * a database. "Waiting for the seller" tells someone what is happening;
     * "awaiting_seller" tells them we did not think about it.
     */
    public function label(): string
    {
        return match ($this) {
            self::PendingPayment     => 'Waiting for payment',
            self::AwaitingSeller     => 'Waiting for the seller',
            self::Confirmed          => 'Accepted by the seller',
            self::Preparing          => 'Being prepared',
            self::ReadyForPickup     => 'Ready to collect',
            self::Collected          => 'Collected',
            self::CollectionOverdue  => 'Collection overdue',
            self::ReturnedToStock    => 'Returned to stock',
            self::ReadyForDispatch   => 'Ready for dispatch',
            self::Assigned           => 'Assigned to an agent',
            self::PickedUp           => 'Picked up by the agent',
            self::OutForDelivery     => 'Out for delivery',
            self::Delivered          => 'Delivered',
            self::DeliveryFailed     => 'Delivery attempt failed',
            self::ReturnedToSeller   => 'Returned to the seller',
            self::Completed          => 'Completed',
            self::CancelledCustomer  => 'Cancelled by the customer',
            self::RejectedSeller     => 'Rejected by the seller',
            self::ExpiredUnpaid      => 'Expired - not paid in time',
            self::RefundPending      => 'Refund in progress',
            self::Refunded           => 'Refunded',
        };
    }

    /**
     * The tone a badge should use. Never the only signal - every status is
     * shown with its text as well, because colour alone fails for anyone who
     * cannot distinguish these two hues (DS-EXT-02).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Collected, self::Delivered, self::Completed           => 'success',
            self::ReadyForPickup, self::ReadyForDispatch, self::OutForDelivery,
            self::Confirmed, self::Preparing, self::Assigned, self::PickedUp => 'info',
            self::PendingPayment, self::AwaitingSeller, self::CollectionOverdue,
            self::DeliveryFailed, self::RefundPending                   => 'warning',
            self::CancelledCustomer, self::RejectedSeller, self::ExpiredUnpaid,
            self::ReturnedToStock, self::ReturnedToSeller               => 'danger',
            self::Refunded                                              => 'neutral',
        };
    }

    /** Still moving - the orders a seller or an agent has work to do on. */
    public function isOpen(): bool
    {
        return !in_array($this, [
            self::Completed, self::Refunded, self::ExpiredUnpaid,
            self::CancelledCustomer, self::RejectedSeller,
        ], true);
    }

    /** The customer got their goods. */
    public function isFulfilled(): bool
    {
        return in_array($this, [self::Collected, self::Delivered, self::Completed], true);
    }

    /**
     * Statuses that hold reserved stock. Anything here must release or consume
     * its reservation before it leaves - an order that simply stops in one of
     * these states has taken units off the shelf for nobody.
     */
    public function holdsStock(): bool
    {
        return in_array($this, [
            self::PendingPayment, self::AwaitingSeller, self::Confirmed, self::Preparing,
            self::ReadyForPickup, self::CollectionOverdue, self::ReadyForDispatch,
            self::Assigned, self::PickedUp, self::OutForDelivery, self::DeliveryFailed,
        ], true);
    }

    /** @return list<self> */
    public static function openStatuses(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => $s->isOpen()));
    }
}
