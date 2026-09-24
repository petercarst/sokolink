<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Secrets: generating them, and hashing them for storage.
 *
 * Nothing in this application stores a reset token, a collection code or a
 * delivery code in readable form. The database holds a SHA-256 hash and the
 * plaintext exists only long enough to be shown to the person it belongs to
 * (docs/DATABASE_DESIGN.md section 9).
 *
 * Why SHA-256 here and bcrypt for passwords: a password is short, guessable and
 * chosen by a human, so it needs a deliberately slow hash. These are 96+ bits
 * of machine-generated randomness with a short life, where the only property
 * needed is that the stored form cannot be read back - and a collection code
 * gets verified at a shop counter, where a 300ms bcrypt round trip per attempt
 * is a real cost for no gain.
 */
final class Token
{
    /**
     * Characters a person can read off a screen and type at a counter without
     * getting it wrong. No 0/O, no 1/I/L, no 5/S, no 8/B.
     */
    private const CODE_ALPHABET = '234679ACDEFGHJKMNPQRTUVWXY';

    /**
     * A collection or delivery code. Six characters from a 26-symbol alphabet
     * is about 28 bits - not a password, but it is single-use, tied to one
     * order, checked by a person and rate-limited, which is the threat model
     * that matters here.
     */
    public static function code(int $length = 6): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max      = strlen($alphabet) - 1;
        $code     = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * A long random secret for links sent by email. 32 bytes of entropy, hex
     * encoded so it survives being pasted out of a mail client.
     */
    public static function secret(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * The public half of a split token.
     *
     * A reset link carries selector + secret. The selector is indexed and looks
     * the row up; the secret is compared against the stored hash with
     * hash_equals. Looking up by the secret itself would mean either indexing a
     * secret or scanning every row, and comparing a fetched hash without a
     * constant-time compare leaks through timing.
     */
    public static function selector(): string
    {
        return substr(bin2hex(random_bytes(16)), 0, 24);
    }

    /**
     * The stored form. Uppercased first for codes, so a customer reading
     * "k7m2qp" off a phone still matches.
     */
    public static function hash(string $plaintext): string
    {
        return hash('sha256', mb_strtoupper(trim($plaintext)));
    }

    /** Hash for values where case is meaningful - email tokens, webhook secrets. */
    public static function hashExact(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /** Constant-time comparison. Never use === on a secret. */
    public static function matches(string $plaintext, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($plaintext));
    }

    public static function matchesExact(string $plaintext, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hashExact($plaintext));
    }

    /**
     * Splits "selector.secret" back into its parts, or null if the shape is
     * wrong. Callers treat null exactly like a token that did not match - a
     * malformed link and a wrong link deserve the same answer.
     *
     * @return array{0:string,1:string}|null
     */
    public static function split(string $combined): ?array
    {
        $parts = explode('.', trim($combined), 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    public static function combine(string $selector, string $secret): string
    {
        return $selector . '.' . $secret;
    }

    /**
     * Idempotency keys are hashed too. The key itself is chosen by the client,
     * so it is untrusted input; hashing gives a fixed-width value for the
     * UNIQUE index and means a guessable key cannot be read out of the table.
     */
    public static function idempotencyHash(string $key, int $userId, string $endpoint): string
    {
        return hash('sha256', $userId . '|' . $endpoint . '|' . $key);
    }
}
