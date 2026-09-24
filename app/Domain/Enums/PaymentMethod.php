<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * How the customer chose to pay.
 *
 * Mirrors `orders`.`payment_method`. The two are asserted equal by
 * tests/Integration/enum_parity_test.php - if a migration adds a value here or
 * there and not the other, that test fails rather than something subtler
 * happening in production.
 */
enum PaymentMethod: string
{
    case Sandbox = 'sandbox';
    case Cash = 'cash';
    case Mpesa = 'mpesa';
    case AirtelMoney = 'airtel_money';
    case Mixx = 'mixx';
    case Halopesa = 'halopesa';

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
            self::Sandbox => 'Sandbox',
            self::Cash => 'Cash',
            self::Mpesa => 'M-Pesa',
            self::AirtelMoney => 'Airtel money',
            self::Mixx => 'Mixx',
            self::Halopesa => 'Halopesa',
        };
    }
}
