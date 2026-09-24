<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Request;

/**
 * The cookie that gives a signed-out visitor a basket.
 *
 * The cookie holds a random value; the database stores its SHA-256. That means
 * a stolen database backup does not hand anybody a working basket cookie, and
 * the column cannot be walked by incrementing an id.
 *
 * The cookie is deliberately NOT the session: a basket has to survive a session
 * expiring, which is what makes someone come back an hour later and still find
 * their shopping. It carries no personal data and no privileges - the worst a
 * forged value can do is show an empty basket.
 */
final class GuestCart
{
    public const COOKIE = 'sokolink_basket';

    private const LIFETIME_DAYS = 30;

    private static ?string $pendingToken = null;

    /** The hash for the current visitor's basket, issuing a cookie if there is none. */
    public static function hash(): string
    {
        $token = self::token();

        if ($token === null) {
            $token = bin2hex(random_bytes(16));
            self::issue($token);
        }

        return hash('sha256', $token);
    }

    /**
     * The hash if a cookie already exists, otherwise null.
     *
     * Read paths use this: rendering a page should not set a cookie on someone
     * who has not put anything in a basket yet.
     */
    public static function existingHash(): ?string
    {
        $token = self::token();

        return $token === null ? null : hash('sha256', $token);
    }

    /** Clears the cookie after the basket has been merged into an account. */
    public static function forget(): void
    {
        self::$pendingToken = null;

        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        unset($_COOKIE[self::COOKIE]);

        setcookie(self::COOKIE, '', self::cookieOptions(time() - 42000));
    }

    private static function token(): ?string
    {
        if (self::$pendingToken !== null) {
            return self::$pendingToken;
        }

        $raw = $_COOKIE[self::COOKIE] ?? null;

        // A value that is not 32 hex characters was not issued here. Treat it
        // as absent rather than hashing it - it would only ever find nothing.
        return is_string($raw) && preg_match('/^[a-f0-9]{32}$/', $raw) === 1 ? $raw : null;
    }

    private static function issue(string $token): void
    {
        // Held in memory as well as sent, so a request that creates the basket
        // and then reads it back gets the same hash rather than a second one.
        self::$pendingToken    = $token;
        $_COOKIE[self::COOKIE] = $token;

        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, $token, self::cookieOptions(time() + self::LIFETIME_DAYS * 86400));
    }

    /** @return array<string,mixed> */
    private static function cookieOptions(int $expires): array
    {
        return [
            'expires'  => $expires,
            'path'     => Request::current()->basePath() . '/',
            'domain'   => '',
            'secure'   => (bool) Config::get('session.secure', false),
            'httponly' => true,
            'samesite' => (string) Config::get('session.same_site', 'Lax'),
        ];
    }
}
