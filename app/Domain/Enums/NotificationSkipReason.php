<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Why a queued message was not sent.
 *
 * Mirrors `notifications`.`skip_reason`, which is NOT the same set as
 * `reorder_reminders`.`skip_reason` - it carries one extra value,
 * `skipped_no_provider`, for the channels that have no provider connected.
 *
 * That difference is deliberate rather than an oversight. A reminder is skipped
 * for reasons about the CUSTOMER (they withdrew consent, they already bought
 * it); a message is additionally skipped for reasons about US (we cannot send
 * SMS). Collapsing the two would make "we have no SMS provider" look like a
 * decision about the customer.
 *
 * Parity with the column is asserted by tests/Integration/domain_test.php.
 */
enum NotificationSkipReason: string
{
    case NoConsent = 'no_consent';
    case ConsentWithdrawn = 'consent_withdrawn';
    case AlreadyRepurchased = 'already_repurchased';
    case Cooldown = 'cooldown';
    case FrequencyCap = 'frequency_cap';
    case Unavailable = 'unavailable';
    case InsufficientData = 'insufficient_data';
    case SkippedNoProvider = 'skipped_no_provider';
    case QuietHours = 'quiet_hours';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    public static function fromDatabase(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new \ValueError(sprintf('%s has no case for "%s".', static::class, $value));
    }

    /** Bridges the reminder enum, whose values are a subset of these. */
    public static function fromReminderSkip(SkipReason $reason): self
    {
        return self::fromDatabase($reason->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::NoConsent => 'The customer never agreed to these messages',
            self::ConsentWithdrawn => 'The customer withdrew their agreement',
            self::AlreadyRepurchased => 'They have bought it again already',
            self::Cooldown => 'Too soon after the last message',
            self::FrequencyCap => 'They have had enough messages this month',
            self::Unavailable => 'The product is no longer available',
            self::InsufficientData => 'Not enough evidence to estimate a date',
            self::SkippedNoProvider => 'No provider is connected for this channel',
            self::QuietHours => 'Inside the quiet hours',
        };
    }
}
