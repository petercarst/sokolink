<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Typed, dot-notation access to configuration.
 *
 * Configuration is assembled once at boot from .env plus sane defaults. Views
 * and services read Config, never Env directly, so there is exactly one place
 * that decides what a setting means.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    public static function boot(): void
    {
        self::$items = [
            'app' => [
                'name'             => (string) Env::get('APP_NAME', 'SokoLink'),
                'env'              => (string) Env::get('APP_ENV', 'local'),
                'debug'            => (bool) Env::get('APP_DEBUG', true),
                'url'              => rtrim((string) Env::get('APP_URL', ''), '/'),
                'timezone'         => (string) Env::get('APP_TIMEZONE', 'UTC'),
                'display_timezone' => (string) Env::get('APP_DISPLAY_TIMEZONE', 'Africa/Dar_es_Salaam'),
                'locale'           => (string) Env::get('APP_LOCALE', 'en'),
                'currency'         => (string) Env::get('APP_CURRENCY', 'TZS'),
                'currency_symbol'  => (string) Env::get('APP_CURRENCY_SYMBOL', 'TSh'),
                'base_path'        => (string) Env::get('APP_BASE_PATH', ''),
            ],
            'session' => [
                'name'          => (string) Env::get('SESSION_NAME', 'sokolink_session'),
                'lifetime'      => (int) Env::get('SESSION_LIFETIME_MINUTES', 120),
                'idle_timeout'  => (int) Env::get('SESSION_IDLE_TIMEOUT_MINUTES', 30),
                'secure'        => (bool) Env::get('SESSION_SECURE', false),
                'same_site'     => (string) Env::get('SESSION_SAME_SITE', 'Lax'),
            ],
            'database' => [
                'host'      => (string) Env::get('DB_HOST', '127.0.0.1'),
                'port'      => (int) Env::get('DB_PORT', 3306),
                'database'  => (string) Env::get('DB_DATABASE', 'sokolink'),
                'username'  => (string) Env::get('DB_USERNAME', 'root'),
                'password'  => (string) Env::get('DB_PASSWORD', ''),
                'charset'   => (string) Env::get('DB_CHARSET', 'utf8mb4'),
                'collation' => (string) Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
            ],
            'mail' => [
                'driver'     => (string) Env::get('MAIL_DRIVER', 'log'),
                'host'       => (string) Env::get('MAIL_HOST', '127.0.0.1'),
                'port'       => (int) Env::get('MAIL_PORT', 1025),
                'username'   => (string) Env::get('MAIL_USERNAME', ''),
                'password'   => (string) Env::get('MAIL_PASSWORD', ''),
                'encryption' => (string) Env::get('MAIL_ENCRYPTION', ''),
                'from_address' => (string) Env::get('MAIL_FROM_ADDRESS', 'no-reply@sokolink.test'),
                'from_name'    => (string) Env::get('MAIL_FROM_NAME', 'SokoLink'),
            ],
            'payment' => [
                'driver'          => (string) Env::get('PAYMENT_DRIVER', 'sandbox'),
                'webhook_secret'  => (string) Env::get('PAYMENT_WEBHOOK_SECRET', ''),
                'expiry_minutes'  => (int) Env::get('PAYMENT_UNPAID_EXPIRY_MINUTES', 60),
            ],
            'notify' => [
                'quiet_start'     => (int) Env::get('NOTIFY_QUIET_HOURS_START', 21),
                'quiet_end'       => (int) Env::get('NOTIFY_QUIET_HOURS_END', 7),
                'cooldown_days'   => (int) Env::get('REMINDER_COOLDOWN_DAYS', 14),
                'monthly_cap'     => (int) Env::get('REMINDER_MONTHLY_CAP', 4),
            ],
            'security' => [
                'login_max_attempts'   => (int) Env::get('AUTH_LOGIN_MAX_ATTEMPTS', 5),
                'login_decay_minutes'  => (int) Env::get('AUTH_LOGIN_DECAY_MINUTES', 15),
                'lockout_minutes'      => (int) Env::get('AUTH_LOCKOUT_MINUTES', 15),
                'reset_token_minutes'  => (int) Env::get('AUTH_RESET_TOKEN_MINUTES', 60),
                'verify_token_hours'   => (int) Env::get('AUTH_VERIFY_TOKEN_HOURS', 48),
                'password_min_length'  => (int) Env::get('AUTH_PASSWORD_MIN_LENGTH', 10),
            ],
            'upload' => [
                'max_bytes'     => (int) Env::get('UPLOAD_MAX_BYTES', 3145728),
                'allowed_mime'  => explode(',', (string) Env::get('UPLOAD_ALLOWED_MIME', 'image/jpeg,image/png,image/webp')),
            ],
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value    = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Overrides a value for the current process only.
     *
     * Used by the tests and by CLI tasks that need to run as a different
     * environment. It never writes anything back to .env - configuration is
     * read at boot and edited by a person, not by the application.
     */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target    = &self::$items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $target[$segment] = $value;
                return;
            }

            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }
    }

    public static function isDebug(): bool
    {
        return (bool) self::get('app.debug', false);
    }

    public static function isProduction(): bool
    {
        return self::get('app.env') === 'production';
    }
}
