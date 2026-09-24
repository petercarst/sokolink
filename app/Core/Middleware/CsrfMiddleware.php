<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Csrf;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Verifies the synchroniser token on every state-changing request.
 *
 * Applied to all POSTs by the router rather than opted into per route. Opt-in
 * CSRF protection is protection that will eventually be forgotten on the one
 * route where it mattered.
 *
 * 403, not 419. 419 is a framework convention, not a registered HTTP status,
 * and PHP degrades an unknown status to 500 - which turns a routine expired
 * token into what looks like a server fault.
 */
final class CsrfMiddleware implements Middleware
{
    public function handle(Request $request, string $argument = ''): ?Response
    {
        if (!$request->isPost()) {
            return null;
        }

        $token = $request->input(Csrf::FIELD);

        if (!is_string($token)) {
            // A fetch() caller may send it as a header instead of a field.
            $token = $request->header('X-CSRF-Token');
        }

        if (Csrf::verify(is_string($token) ? $token : null)) {
            return null;
        }

        if ($request->wantsJson()) {
            return Response::json(['error' => 'Your session expired. Reload and try again.'], 403);
        }

        throw new HttpException(
            403,
            'Your session expired before the form was submitted. Go back, reload the page and try again.'
        );
    }
}
