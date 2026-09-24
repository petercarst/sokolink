<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Why a delivery attempt did not succeed.
 *
 * Mirrors `delivery_events`.`reason_code`. The two are asserted equal by
 * tests/Integration/enum_parity_test.php - if a migration adds a value here or
 * there and not the other, that test fails rather than something subtler
 * happening in production.
 */
enum DeliveryFailureReason: string
{
    case RecipientAbsent = 'recipient_absent';
    case WrongAddress = 'wrong_address';
    case Refused = 'refused';
    case UnreachablePhone = 'unreachable_phone';
    case AccessDenied = 'access_denied';
    case UnsafeConditions = 'unsafe_conditions';
    case DamagedInTransit = 'damaged_in_transit';
    case TooFar = 'too_far';
    case TooHeavy = 'too_heavy';
    case Timing = 'timing';
    case Busy = 'busy';
    case Other = 'other';

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
            self::RecipientAbsent => 'Recipient absent',
            self::WrongAddress => 'Wrong address',
            self::Refused => 'Refused',
            self::UnreachablePhone => 'Unreachable phone',
            self::AccessDenied => 'Access denied',
            self::UnsafeConditions => 'Unsafe conditions',
            self::DamagedInTransit => 'Damaged in transit',
            self::TooFar => 'Too far',
            self::TooHeavy => 'Too heavy',
            self::Timing => 'Timing',
            self::Busy => 'Busy',
            self::Other => 'Other',
        };
    }
}
