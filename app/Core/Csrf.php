<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchroniser-token CSRF protection (NFR-SEC-03).
 *
 * One token per session, compared with hash_equals() so the comparison itself
 * does not leak information through timing. Every state-changing form in the
 * application embeds the hidden field; Phase 3 adds the middleware that makes
 * verification mandatory rather than opt-in.
 */
final class Csrf
{
    public const FIELD = '_token';
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    public static function verify(?string $candidate): bool
    {
        $token = Session::get(self::SESSION_KEY);

        if (!is_string($token) || $token === '' || !is_string($candidate) || $candidate === '') {
            return false;
        }

        return hash_equals($token, $candidate);
    }

    /** Invalidates the current token. Called on login, logout and password change. */
    public static function rotate(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
