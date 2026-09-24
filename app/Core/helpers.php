<?php

declare(strict_types=1);

/**
 * Global template helpers.
 *
 * These exist so templates read cleanly and so there is exactly one escaping
 * function in the codebase. Raw echo of a variable in a view is a reviewable
 * defect (NFR-SEC-02).
 */

use App\Core\Clock;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Money;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

if (!function_exists('e')) {
    /**
     * The single escaping helper. ENT_QUOTES covers both quote styles so the
     * output is safe inside an attribute as well as in text; ENT_SUBSTITUTE
     * replaces invalid UTF-8 instead of returning an empty string, which would
     * silently blank out content.
     */
    function e(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** Base-path-aware URL for a literal path. */
    function url(string $path = '/'): string
    {
        $base = Request::current()->basePath();

        if ($path === '' || $path === '/') {
            return $base === '' ? '/' : $base . '/';
        }

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('route')) {
    /**
     * URL for a named route. Templates use this rather than literal paths so a
     * URL can be changed in routes/web.php without touching 80 view files.
     *
     * @param array<string,string|int> $params
     */
    function route(string $name, array $params = []): string
    {
        return Router::url($name, $params);
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return url('assets/' . ltrim($path, '/'));
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('money')) {
    function money(float|int|string $amount, bool $withSymbol = true): string
    {
        return Money::format($amount, $withSymbol);
    }
}

if (!function_exists('money_compact')) {
    function money_compact(float|int|string $amount, bool $withSymbol = true): string
    {
        return Money::compact($amount, $withSymbol);
    }
}

if (!function_exists('component')) {
    /** @param array<string,mixed> $data */
    function component(string $name, array $data = []): string
    {
        return View::component($name, $data);
    }
}

if (!function_exists('partial')) {
    /** @param array<string,mixed> $data */
    function partial(string $name, array $data = []): string
    {
        return View::partial($name, $data);
    }
}

if (!function_exists('mock')) {
    /**
     * PHASE 1 ONLY - loads a mock dataset. Replaced by repository calls in
     * Phase 4, at which point app/Views/_mock/ is deleted.
     *
     * @return array<string,mixed>|list<mixed>
     */
    function mock(string $name): array
    {
        return View::mock($name);
    }
}

if (!function_exists('is_route')) {
    function is_route(string ...$names): bool
    {
        return in_array(Router::currentName(), $names, true);
    }
}

if (!function_exists('nav_active')) {
    /** Returns $class when one of the given route names is the current route. */
    function nav_active(array|string $names, string $class = 'active'): string
    {
        $names = (array) $names;

        return in_array(Router::currentName(), $names, true) ? $class : '';
    }
}

if (!function_exists('route_starts_with')) {
    /** True when the current route name begins with the given prefix, e.g. "seller." */
    function route_starts_with(string $prefix): bool
    {
        return str_starts_with((string) Router::currentName(), $prefix);
    }
}

if (!function_exists('old')) {
    /** Repopulates a form field after a failed validation round-trip. */
    function old(string $key, mixed $default = ''): mixed
    {
        return Session::oldInput($key, $default);
    }
}

if (!function_exists('error_for')) {
    /** First validation error for a field, or null. */
    function error_for(string $key): ?string
    {
        return Session::firstError($key);
    }
}

if (!function_exists('query_with')) {
    /**
     * Current query string with some parameters replaced. Used by filter and
     * pagination links so they keep the rest of the active filters.
     *
     * @param array<string,mixed> $overrides
     */
    function query_with(array $overrides): string
    {
        return Request::current()->queryStringWith($overrides);
    }
}

if (!function_exists('str_limit')) {
    function str_limit(string $value, int $limit = 120, string $end = '...'): string
    {
        $value = trim($value);

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit)) . $end;
    }
}

if (!function_exists('initials')) {
    function initials(string $name, int $max = 2): string
    {
        $parts  = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
            if (mb_strlen($letters) >= $max) {
                break;
            }
        }

        return $letters === '' ? '?' : $letters;
    }
}

if (!function_exists('local_datetime')) {
    function local_datetime(string $utc): string
    {
        return Clock::dateTime($utc);
    }
}

if (!function_exists('local_date')) {
    function local_date(string $utc): string
    {
        return Clock::date($utc);
    }
}

if (!function_exists('time_ago')) {
    function time_ago(string $utc): string
    {
        return Clock::relative($utc);
    }
}

if (!function_exists('time_tag')) {
    /** Accessible <time> element: machine-readable attribute, human-readable text. */
    function time_tag(string $utc, bool $relative = false): string
    {
        return sprintf(
            '<time datetime="%s" title="%s">%s</time>',
            e(Clock::iso($utc)),
            e(Clock::dateTime($utc)),
            e($relative ? Clock::relative($utc) : Clock::dateTime($utc))
        );
    }
}
