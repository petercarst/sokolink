<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * What happened to a basket.
 *
 * Mirrors `carts`.`status`. The two are asserted equal by
 * tests/Integration/enum_parity_test.php - if a migration adds a value here or
 * there and not the other, that test fails rather than something subtler
 * happening in production.
 */
enum CartStatus: string
{
    case Active = 'active';
    case Merged = 'merged';
    case Converted = 'converted';
    case Abandoned = 'abandoned';

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
            self::Active => 'Active',
            self::Merged => 'Merged',
            self::Converted => 'Converted',
            self::Abandoned => 'Abandoned',
        };
    }
}
