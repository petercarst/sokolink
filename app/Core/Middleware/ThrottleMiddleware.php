<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * A light limiter for routes that are expensive rather than sensitive:
 * `throttle:10,60` means ten requests per sixty seconds.
 *
 * Session-backed, which makes it weak on purpose - it stops an impatient person
 * from submitting a ticket reply eight times, not an attacker. Login and
 * password reset use the table-backed RateLimiter instead, because those are
 * the ones somebody would actually script.
 */
final class ThrottleMiddleware implements Middleware
{
    public function handle(Request $request, string $argument = ''): ?Response
    {
        [$maxHits, $window] = array_pad(explode(',', $argument === '' ? '30,60' : $argument), 2, '60');

        // A dot, not a pipe: this ends up in a session key, and PHP's session
        // serializer treats `|` as its own delimiter. RateLimiter sanitises it
        // too, but the right separator here means it never has to.
        $key = (Router::currentName() ?? $request->path()) . '.' . $request->method();

        if (RateLimiter::hitSession($key, (int) $maxHits, (int) $window)) {
            return null;
        }

        if ($request->wantsJson()) {
            return Response::json(['error' => 'Too many requests. Wait a moment and try again.'], 429);
        }

        throw new HttpException(429, 'You are doing that too quickly. Wait a moment and try again.');
    }
}
