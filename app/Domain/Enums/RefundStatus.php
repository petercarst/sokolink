<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Where a refund has got to.
 *
 * Mirrors `refunds`.`status`. The two are asserted equal by
 * tests/Integration/enum_parity_test.php - if a migration adds a value here or
 * there and not the other, that test fails rather than something subtler
 * happening in production.
 */
enum RefundStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Refunded = 'refunded';
    case Failed = 'failed';
    case Rejected = 'rejected';

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
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Refunded => 'Refunded',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
        };
    }
}
