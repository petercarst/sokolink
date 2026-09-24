<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * How a seller is constituted.
 *
 * Mirrors `seller_applications`.`business_type`. The two are asserted equal by
 * tests/Integration/enum_parity_test.php - if a migration adds a value here or
 * there and not the other, that test fails rather than something subtler
 * happening in production.
 */
enum BusinessType: string
{
    case SoleTrader = 'sole_trader';
    case Partnership = 'partnership';
    case Company = 'company';
    case Cooperative = 'cooperative';

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
            self::SoleTrader => 'Sole trader',
            self::Partnership => 'Partnership',
            self::Company => 'Company',
            self::Cooperative => 'Cooperative',
        };
    }
}
