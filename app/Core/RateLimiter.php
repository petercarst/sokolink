<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Throttling for anything a robot would like to do repeatedly (NFR-SEC-09).
 *
 * Attempts live in `auth_attempts` rather than the session, because a session
 * is exactly what an attacker does not reuse. Both the email and the IP are
 * counted, and either tripping is enough to refuse: counting only the email
 * lets one host walk a list of addresses, and counting only the IP lets a
 * botnet share the work.
 *
 * Successful attempts are recorded too - they are what lets "someone tried
 * 200 passwords and then got in" be visible afterwards.
 */
final class RateLimiter
{
    /**
     * Records one attempt. Always called, success or failure.
     */
    public static function record(?string $email, string $ip, bool $successful): void
    {
        Database::statement(
            'INSERT INTO auth_attempts (email, ip_address, successful, attempted_at)
             VALUES (:email, INET6_ATON(:ip), :successful, UTC_TIMESTAMP())',
            [
                'email'      => $email !== null ? mb_strtolower($email) : null,
                'ip'         => $ip,
                'successful' => $successful ? 1 : 0,
            ]
        );
    }

    /**
     * How many failures this email has had inside the window.
     */
    public static function failuresForEmail(string $email, ?int $windowMinutes = null): int
    {
        $window = $windowMinutes ?? (int) Config::get('security.login_decay_minutes', 15);

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM auth_attempts
              WHERE email = :email
                AND successful = 0
                AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE)',
            ['email' => mb_strtolower($email), 'minutes' => $window]
        );
    }

    public static function failuresForIp(string $ip, ?int $windowMinutes = null): int
    {
        $window = $windowMinutes ?? (int) Config::get('security.login_decay_minutes', 15);

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM auth_attempts
              WHERE ip_address = INET6_ATON(:ip)
                AND successful = 0
                AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE)',
            ['ip' => $ip, 'minutes' => $window]
        );
    }

    /**
     * True when this email/IP pair has spent its allowance.
     *
     * The IP allowance is deliberately looser (three times the per-email limit)
     * so a shared office connection or a phone on carrier NAT is not locked out
     * because one colleague forgot their password.
     */
    public static function tooManyAttempts(?string $email, string $ip): bool
    {
        $limit = (int) Config::get('security.login_max_attempts', 5);

        if ($email !== null && self::failuresForEmail($email) >= $limit) {
            return true;
        }

        return self::failuresForIp($ip) >= $limit * 3;
    }

    /**
     * Seconds until the oldest counted failure falls out of the window, so the
     * message can say "try again in 4 minutes" instead of "try again later".
     */
    public static function secondsUntilRetry(?string $email, string $ip): int
    {
        $window = (int) Config::get('security.login_decay_minutes', 15);

        $oldest = Database::scalar(
            'SELECT MIN(attempted_at) FROM auth_attempts
              WHERE successful = 0
                AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE)
                AND (email = :email OR ip_address = INET6_ATON(:ip))',
            ['minutes' => $window, 'email' => $email !== null ? mb_strtolower($email) : null, 'ip' => $ip]
        );

        if (!is_string($oldest)) {
            return 0;
        }

        $expiresAt = strtotime($oldest . ' UTC') + ($window * 60);

        return max(0, $expiresAt - time());
    }

    /**
     * Clears the failure history for an email. Called after a successful login
     * and after a completed password reset, so a user who has just proved who
     * they are is not still serving a sentence.
     */
    public static function clear(string $email): void
    {
        Database::statement(
            'DELETE FROM auth_attempts WHERE email = :email AND successful = 0',
            ['email' => mb_strtolower($email)]
        );
    }

    /**
     * A generic session-backed limiter for non-authentication actions -
     * "send another verification email", "submit this ticket reply". Weaker
     * than the table-backed one by design: it guards against an impatient
     * person, not against an attacker.
     */
    public static function hitSession(string $key, int $maxHits, int $windowSeconds): bool
    {
        $bucketKey = self::bucketKey($key);
        $now       = time();

        /** @var array{count:int,started:int} $bucket */
        $bucket = Session::get($bucketKey, ['count' => 0, 'started' => $now]);

        if ($now - (int) $bucket['started'] > $windowSeconds) {
            $bucket = ['count' => 0, 'started' => $now];
        }

        $bucket['count']++;
        Session::put($bucketKey, $bucket);

        return $bucket['count'] <= $maxHits;
    }

    /**
     * The session key for a bucket.
     *
     * **A session key may not contain `|`.** PHP's default session serializer
     * uses it as its own delimiter and cannot represent a key containing one -
     * so it silently writes NOTHING and the entire session is lost, not just
     * this entry. That failure is invisible: no warning, no exception, just a
     * session that was fine at the end of the request and empty at the start of
     * the next.
     *
     * `!` is replaced as well. It is harmless under the default handler and is
     * the delimiter for `php_binary`, so this costs nothing and means changing
     * session.serialize_handler cannot reintroduce the same bug.
     *
     * It cost an afternoon. A throttle key of "auth.login.submit|POST" meant
     * every login wrote `_auth_user_id` into a session that was then discarded,
     * so signing in appeared to work and the next page asked you to sign in
     * again.
     *
     * Sanitising here rather than at the one call site that got it wrong,
     * because the next caller cannot be expected to know this either.
     */
    private static function bucketKey(string $key): string
    {
        return '_throttle_' . str_replace(['|', '!'], '.', $key);
    }

    /**
     * Housekeeping for the CLI task. Attempts older than the retention window
     * are of no forensic value and only slow the index down.
     */
    public static function prune(int $olderThanDays = 30): int
    {
        return Database::statement(
            'DELETE FROM auth_attempts WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)',
            ['days' => $olderThanDays]
        );
    }
}
