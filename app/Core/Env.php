<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Reads key=value pairs from a .env file into an internal array.
 *
 * Values are NOT written into $_ENV or getenv(), deliberately: keeping them in
 * one place makes it obvious where configuration comes from, and stops secrets
 * leaking into phpinfo() output or subprocess environments.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;
    private static ?string $loadedFrom = null;

    /**
     * Loads the first file that exists from the given candidates.
     *
     * .env.example is an accepted fallback because it contains no secrets - it
     * lets a freshly cloned checkout boot. Real deployments must have a .env;
     * docs/INSTALLATION.md says so and the production checklist verifies it.
     *
     * @param list<string> $candidates
     */
    public static function load(array $candidates): void
    {
        foreach ($candidates as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $key   = trim($key);
                $value = trim($value);

                // Strip an inline comment, but only when it is not inside quotes.
                if ($value !== '' && $value[0] !== '"' && $value[0] !== "'" && str_contains($value, '#')) {
                    $value = trim(substr($value, 0, (int) strpos($value, '#')));
                }

                $value = trim($value, "\"'");

                self::$values[$key] = $value;
            }

            self::$loaded     = true;
            self::$loadedFrom = $path;
            return;
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$values)) {
            return $default;
        }

        $value = self::$values[$key];

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            ''                 => $default,
            default            => $value,
        };
    }

    public static function loadedFrom(): ?string
    {
        return self::$loadedFrom;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
