<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Requires a confirmed email address.
 *
 * Applied to actions that commit the platform to something on a customer's
 * behalf - placing an order, applying to sell - rather than to browsing. An
 * unverified account can look around; it cannot make a seller prepare goods for
 * an address nobody has confirmed.
 */
final class VerifiedMiddleware implements Middleware
{
    public function handle(Request $request, string $argument = ''): ?Response
    {
        if (Auth::guest()) {
            return (new AuthMiddleware())->handle($request);
        }

        if (Auth::isVerified()) {
            return null;
        }

        if ($request->wantsJson()) {
            return Response::json(['error' => 'Confirm your email address first.'], 403);
        }

        Session::flash('warning', 'Confirm your email address to continue. We can send the link again.');

        return Response::redirect(route('auth.verify'));
    }
}
