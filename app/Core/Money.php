<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Currency formatting without the intl extension.
 *
 * This XAMPP install does not have ext-intl (checked at Phase 1 start), so
 * NumberFormatter is unavailable. Rather than add a dependency for one string,
 * formatting is done here. The rules are deliberately explicit.
 *
 * Tanzanian Shilling is quoted in whole shillings in everyday use, so we show
 * no decimals for TZS even though amounts are stored as DECIMAL(12,2)
 * (NFR-DAT-02). Storage precision and display precision are separate concerns.
 */
final class Money
{
    public static function format(float|int|string $amount, bool $withSymbol = true): string
    {
        $value    = self::toFloat($amount);
        $currency = (string) Config::get('app.currency', 'TZS');
        $decimals = self::decimalsFor($currency);

        $formatted = number_format($value, $decimals, '.', ',');

        if (!$withSymbol) {
            return $formatted;
        }

        return Config::get('app.currency_symbol', 'TSh') . ' ' . $formatted;
    }

    /**
     * Compact form for dense dashboard tiles: 1,250,000 becomes 1.25M.
     * Full precision stays available in a title attribute at the call site.
     */
    public static function compact(float|int|string $amount, bool $withSymbol = true): string
    {
        $value  = self::toFloat($amount);
        $symbol = $withSymbol ? Config::get('app.currency_symbol', 'TSh') . ' ' : '';
        $abs    = abs($value);
        $sign   = $value < 0 ? '-' : '';

        return match (true) {
            $abs >= 1_000_000_000 => $symbol . $sign . self::trim($abs / 1_000_000_000) . 'B',
            $abs >= 1_000_000     => $symbol . $sign . self::trim($abs / 1_000_000) . 'M',
            $abs >= 10_000        => $symbol . $sign . self::trim($abs / 1_000) . 'K',
            default               => self::format($value, $withSymbol),
        };
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function decimalsFor(string $currency): int
    {
        // Zero-decimal currencies we care about. Extend as markets are added.
        return in_array(strtoupper($currency), ['TZS', 'UGX', 'RWF', 'KMF', 'JPY'], true) ? 0 : 2;
    }

    public static function toFloat(float|int|string $amount): float
    {
        if (is_string($amount)) {
            $amount = str_replace([',', ' '], '', $amount);
        }

        return (float) $amount;
    }
}
