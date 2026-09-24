<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Requires a signed-in account.
 *
 * Redirects rather than 403s, and remembers where the person was going so the
 * login form can return them there. That intended URL is stored in the session,
 * not passed through the query string, so it cannot be used to bounce someone
 * to another site after they log in.
 */
final class AuthMiddleware implements Middleware
{
    public function handle(Request $request, string $argument = ''): ?Response
    {
        if (Auth::check()) {
            return null;
        }

        if ($request->wantsJson()) {
            return Response::json(['error' => 'Sign in to continue.'], 401);
        }

        if (!$request->isPost()) {
            Session::put('_intended_url', $request->path());
        }

        Session::flash('info', 'Sign in to continue.');

        return Response::redirect(route('auth.login'));
    }
}
