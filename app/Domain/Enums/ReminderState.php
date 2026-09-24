<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Where a reminder has got to.
 *
 * Mirrors `reorder_reminders`.`state`. The two are asserted equal by
 * tests/Integration/enum_parity_test.php - if a migration adds a value here or
 * there and not the other, that test fails rather than something subtler
 * happening in production.
 */
enum ReminderState: string
{
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Sent = 'sent';
    case Converted = 'converted';
    case Snoozed = 'snoozed';
    case Skipped = 'skipped';
    case NotScheduled = 'not_scheduled';
    case Closed = 'closed';

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
            self::Scheduled => 'Scheduled',
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Converted => 'Converted',
            self::Snoozed => 'Snoozed',
            self::Skipped => 'Skipped',
            self::NotScheduled => 'Not scheduled',
            self::Closed => 'Closed',
        };
    }
}
