<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Why a reminder was NOT sent. Without this the engine is a black box.
 *
 * Mirrors `reorder_reminders`.`skip_reason`. The two are asserted equal by
 * tests/Integration/enum_parity_test.php - if a migration adds a value here or
 * there and not the other, that test fails rather than something subtler
 * happening in production.
 */
enum SkipReason: string
{
    case NoConsent = 'no_consent';
    case ConsentWithdrawn = 'consent_withdrawn';
    case AlreadyRepurchased = 'already_repurchased';
    case Cooldown = 'cooldown';
    case FrequencyCap = 'frequency_cap';
    case Unavailable = 'unavailable';
    case InsufficientData = 'insufficient_data';
    case QuietHours = 'quiet_hours';

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
            self::NoConsent => 'The customer never agreed to reminders',
            self::ConsentWithdrawn => 'The customer withdrew their agreement',
            self::AlreadyRepurchased => 'They have bought it again already',
            self::Cooldown => 'Too soon after the last reminder',
            self::FrequencyCap => 'They have had enough messages this month',
            self::Unavailable => 'The product is no longer available',
            self::InsufficientData => 'Not enough evidence to estimate a date',
            self::QuietHours => "Inside the customer's quiet hours",
        };
    }
}
