<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Token;
use App\Domain\Enums\TokenPurpose;

/**
 * Single-use, time-limited tokens: email verification, password reset,
 * unsubscribe links.
 *
 * The plaintext token exists in exactly one place - the return value of
 * issue() - and is never written anywhere. What is stored is a public
 * `selector` for the lookup and a SHA-256 of the secret for the comparison.
 *
 * A database dump therefore contains no working links. That is the entire
 * point: the most common way a password reset flow fails is that the tokens
 * were readable by whoever got the backup.
 */
final class TokenRepository extends Repository
{
    protected string $table = 'user_tokens';

    /**
     * Issues a token and returns the plaintext to put in the link.
     *
     * Any earlier token for the same purpose is consumed first. If somebody
     * clicks "forgot password" three times, only the newest link works -
     * otherwise a link from an email read months later still opens the account.
     *
     * @return string "selector.secret", for the URL
     */
    public function issue(int $userId, TokenPurpose $purpose, int $ttlMinutes, string $ip = ''): string
    {
        $this->consumeAllFor($userId, $purpose);

        $selector = Token::selector();
        $secret   = Token::secret();

        $this->statement(
            'INSERT INTO user_tokens (user_id, purpose, selector, token_hash, expires_at, created_ip, created_at)
             VALUES (:user_id, :purpose, :selector, :token_hash,
                     DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl MINUTE),
                     CASE WHEN :ip IS NULL THEN NULL ELSE INET6_ATON(:ip2) END,
                     UTC_TIMESTAMP())',
            [
                'user_id'    => $userId,
                'purpose'    => $purpose->value,
                'selector'   => $selector,
                'token_hash' => Token::hashExact($secret),
                'ttl'        => $ttlMinutes,
                'ip'         => $ip === '' ? null : $ip,
                'ip2'        => $ip === '' ? null : $ip,
            ]
        );

        return Token::combine($selector, $secret);
    }

    /**
     * Verifies a token and marks it used, all or nothing.
     *
     * Returns the user id, or null for every failure mode - wrong shape,
     * unknown selector, wrong secret, expired, already used. The caller shows
     * one message for all of them, because distinguishing "this link expired"
     * from "this link never existed" tells an attacker which selectors are real.
     */
    public function consume(TokenPurpose $purpose, string $combined): ?int
    {
        $parts = Token::split($combined);

        if ($parts === null) {
            return null;
        }

        [$selector, $secret] = $parts;

        $row = $this->selectOne(
            'SELECT id, user_id, token_hash
               FROM user_tokens
              WHERE selector = :selector
                AND purpose  = :purpose
                AND consumed_at IS NULL
                AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
              LIMIT 1',
            ['selector' => $selector, 'purpose' => $purpose->value]
        );

        if ($row === null) {
            return null;
        }

        if (!Token::matchesExact($secret, (string) $row['token_hash'])) {
            return null;
        }

        // Marking it consumed in a guarded UPDATE, and requiring one affected
        // row, means two simultaneous clicks on the same link cannot both
        // succeed. The loser gets null and sees the ordinary failure message.
        $marked = $this->statement(
            'UPDATE user_tokens
                SET consumed_at = UTC_TIMESTAMP()
              WHERE id = :id AND consumed_at IS NULL',
            ['id' => (int) $row['id']]
        );

        return $marked === 1 ? (int) $row['user_id'] : null;
    }

    /**
     * Looks a token up without consuming it - used to decide whether a reset
     * form should be shown at all, before the new password has been typed.
     */
    public function isValid(TokenPurpose $purpose, string $combined): bool
    {
        $parts = Token::split($combined);

        if ($parts === null) {
            return false;
        }

        [$selector, $secret] = $parts;

        $hash = $this->scalar(
            'SELECT token_hash FROM user_tokens
              WHERE selector = :selector AND purpose = :purpose
                AND consumed_at IS NULL
                AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())',
            ['selector' => $selector, 'purpose' => $purpose->value]
        );

        return is_string($hash) && Token::matchesExact($secret, $hash);
    }

    public function consumeAllFor(int $userId, TokenPurpose $purpose): int
    {
        return $this->statement(
            'UPDATE user_tokens
                SET consumed_at = UTC_TIMESTAMP()
              WHERE user_id = :user_id AND purpose = :purpose AND consumed_at IS NULL',
            ['user_id' => $userId, 'purpose' => $purpose->value]
        );
    }

    /**
     * How many tokens of this purpose the user has been issued recently. Used
     * to stop "resend verification email" becoming a way to post mail through
     * somebody else's letterbox a hundred times.
     */
    public function issuedSince(int $userId, TokenPurpose $purpose, int $minutes): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM user_tokens
              WHERE user_id = :user_id AND purpose = :purpose
                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE)',
            ['user_id' => $userId, 'purpose' => $purpose->value, 'minutes' => $minutes]
        );
    }

    /**
     * Housekeeping, run by the scheduled task. A consumed or expired token has
     * no further use and keeping it only makes the index bigger.
     */
    public function pruneExpired(int $keepDays = 7): int
    {
        return $this->statement(
            'DELETE FROM user_tokens
              WHERE (expires_at IS NOT NULL AND expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY))
                 OR (consumed_at IS NOT NULL AND consumed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days2 DAY))',
            ['days' => $keepDays, 'days2' => $keepDays]
        );
    }
}
