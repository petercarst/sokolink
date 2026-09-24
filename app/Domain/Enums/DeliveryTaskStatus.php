<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Where a delivery has got to.
 *
 * Mirrors `delivery_tasks`.`status`. The two are asserted equal by
 * tests/Integration/enum_parity_test.php - if a migration adds a value here or
 * there and not the other, that test fails rather than something subtler
 * happening in production.
 */
enum DeliveryTaskStatus: string
{
    case Unassigned = 'unassigned';
    case Offered = 'offered';
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case ReturnedToSeller = 'returned_to_seller';
    case Cancelled = 'cancelled';

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

    public function label(): string
    {
        return match ($this) {
            self::Unassigned => 'Waiting for an agent',
            self::Offered => 'Offered to agents',
            self::Assigned => 'Assigned to an agent',
            self::PickedUp => 'Collected from the seller',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Failed => 'Attempt failed',
            self::ReturnedToSeller => 'Returned to the seller',
            self::Cancelled => 'Cancelled',
        };
    }
}
