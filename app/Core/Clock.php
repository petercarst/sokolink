<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One place that converts stored UTC timestamps into local display strings.
 *
 * Everything is stored UTC (NFR-DAT-05). Nothing else in the application is
 * allowed to call date() on a stored timestamp, because that silently uses
 * whatever timezone PHP happens to be set to, which is how "delivered at 3am"
 * bugs get shipped.
 */
final class Clock
{
    public static function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function displayZone(): DateTimeZone
    {
        return new DateTimeZone((string) Config::get('app.display_timezone', 'Africa/Dar_es_Salaam'));
    }

    /** Converts a stored UTC value into the display timezone. */
    public static function local(string|DateTimeImmutable $utc): DateTimeImmutable
    {
        $dt = $utc instanceof DateTimeImmutable
            ? $utc
            : new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        return $dt->setTimezone(self::displayZone());
    }

    /** e.g. "21 Sep 2026, 14:30" */
    public static function dateTime(string|DateTimeImmutable $utc): string
    {
        return self::local($utc)->format('j M Y, H:i');
    }

    /** e.g. "21 Sep 2026" */
    public static function date(string|DateTimeImmutable $utc): string
    {
        return self::local($utc)->format('j M Y');
    }

    /** e.g. "14:30" */
    public static function time(string|DateTimeImmutable $utc): string
    {
        return self::local($utc)->format('H:i');
    }

    /** e.g. "3 days ago", "in 2 weeks" */
    public static function relative(string|DateTimeImmutable $utc): string
    {
        $then = $utc instanceof DateTimeImmutable
            ? $utc
            : new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        $diff    = self::nowUtc()->getTimestamp() - $then->getTimestamp();
        $future  = $diff < 0;
        $seconds = abs($diff);

        $phrase = match (true) {
            $seconds < 60        => 'just now',
            $seconds < 3600      => self::plural((int) floor($seconds / 60), 'minute'),
            $seconds < 86400     => self::plural((int) floor($seconds / 3600), 'hour'),
            $seconds < 604800    => self::plural((int) floor($seconds / 86400), 'day'),
            $seconds < 2592000   => self::plural((int) floor($seconds / 604800), 'week'),
            $seconds < 31536000  => self::plural((int) floor($seconds / 2592000), 'month'),
            default              => self::plural((int) floor($seconds / 31536000), 'year'),
        };

        if ($phrase === 'just now') {
            return $phrase;
        }

        return $future ? 'in ' . $phrase : $phrase . ' ago';
    }

    private static function plural(int $count, string $unit): string
    {
        return $count . ' ' . $unit . ($count === 1 ? '' : 's');
    }

    /** ISO 8601 for the datetime attribute of a <time> element. */
    public static function iso(string|DateTimeImmutable $utc): string
    {
        return self::local($utc)->format(DATE_ATOM);
    }
}
