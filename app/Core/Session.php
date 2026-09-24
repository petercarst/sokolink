<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session handling with hardened cookie settings.
 *
 * Holds the CSRF token, the signed-in user, flash messages, and the old input
 * and validation errors that a failed POST carries across its redirect
 * (NFR-SEC-04).
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        if (PHP_SAPI === 'cli') {
            self::$started = true;
            return;
        }

        $storagePath = dirname(__DIR__, 2) . '/storage/sessions';
        if (is_dir($storagePath) && is_writable($storagePath)) {
            session_save_path($storagePath);
        }

        session_name((string) Config::get('session.name', 'sokolink_session'));

        // Reject a session id that the server did not issue.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => Request::current()->basePath() . '/',
            'domain'   => '',
            'secure'   => (bool) Config::get('session.secure', false),
            'httponly' => true,
            'samesite' => (string) Config::get('session.same_site', 'Lax'),
        ]);

        session_start();
        self::$started = true;

        self::enforceIdleTimeout();
    }

    private static function enforceIdleTimeout(): void
    {
        $timeoutMinutes = (int) Config::get('session.idle_timeout', 30);
        if ($timeoutMinutes <= 0) {
            return;
        }

        $now  = time();
        $last = (int) ($_SESSION['_last_activity'] ?? $now);

        if ($now - $last > $timeoutMinutes * 60) {
            self::destroy();
            session_start();
        }

        $_SESSION['_last_activity'] = $now;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Reads a value once and removes it - used for flash messages and for the
     * repopulation of a form after a failed validation round-trip.
     */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);

        return $value;
    }

    /** Regenerates the session id, keeping the data. Called on any privilege change. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Writes the session and releases its lock, once, at the end of a request.
     *
     * PHP would do this at shutdown anyway. Doing it here releases the session
     * file lock before the body is sent rather than after, so two requests from
     * the same browser stop queueing behind each other - and it means a write
     * that fails does so while there is still a request to attribute it to.
     *
     * A KEY CONTAINING `|` MAKES THIS WRITE NOTHING. PHP's default session
     * serializer uses it as a delimiter and cannot represent a key containing
     * one; it discards the WHOLE session rather than that one entry, with no
     * warning and no exception. See RateLimiter::bucketKey(), which is where
     * that was learned the hard way.
     */
    public static function commit(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        self::$started = false;
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    // ---- Flash messages --------------------------------------------------

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function takeFlashes(): array
    {
        /** @var list<array{type:string,message:string}> $flashes */
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $flashes;
    }

    // ---- Old input and validation errors ---------------------------------
    //
    // A failed POST redirects rather than re-rendering (POST-redirect-GET), so
    // what the user typed and what was wrong with it have to survive exactly
    // one redirect and then disappear. They are pulled out of the session the
    // moment the next request boots - not when a template happens to ask for
    // them - because a template that never asks would otherwise leave stale
    // errors to appear on some unrelated page later.

    /** @var array<string,mixed> */
    private static array $oldInput = [];

    /** @var array<string,string|list<string>> */
    private static array $errors = [];

    /** Moves the previous request's input and errors out of the session. */
    public static function hydrateOldInput(): void
    {
        $old = self::pull('_old', []);
        $errors = self::pull('_errors', []);

        self::$oldInput = is_array($old) ? $old : [];
        self::$errors   = is_array($errors) ? $errors : [];
    }

    /**
     * Stores input for the next request, minus anything that must never be
     * written to session storage.
     *
     * @param array<string,mixed> $input
     */
    public static function flashInput(array $input): void
    {
        foreach (['password', 'password_confirmation', 'current_password', 'new_password', Csrf::FIELD] as $secret) {
            unset($input[$secret]);
        }

        $_SESSION['_old'] = $input;
    }

    /** @param array<string,string|list<string>> $errors */
    public static function flashErrors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }

    public static function oldInput(string $key, mixed $default = ''): mixed
    {
        return self::$oldInput[$key] ?? $default;
    }

    /** @return array<string,string|list<string>> */
    public static function errors(): array
    {
        return self::$errors;
    }

    public static function firstError(string $key): ?string
    {
        if (!isset(self::$errors[$key])) {
            return null;
        }

        $messages = (array) self::$errors[$key];

        return $messages === [] ? null : (string) reset($messages);
    }

    /** True when the last request failed validation - used to open a form's error summary. */
    public static function hasErrors(): bool
    {
        return self::$errors !== [];
    }
}
