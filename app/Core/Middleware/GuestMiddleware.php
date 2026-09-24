<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * The login and registration screens, for people who are not already signed in.
 *
 * Sending a logged-in user to their dashboard instead of the login form avoids
 * the confusing case where signing in again silently replaces the session you
 * already had.
 */
final class GuestMiddleware implements Middleware
{
    public function handle(Request $request, string $argument = ''): ?Response
    {
        if (Auth::guest()) {
            return null;
        }

        $home = match (Auth::primaryRole()) {
            'admin'          => 'admin.dashboard',
            'support'        => 'support.dashboard',
            'delivery_agent' => 'delivery.dashboard',
            'seller'         => 'seller.dashboard',
            default          => 'customer.dashboard',
        };

        return Response::redirect(route($home));
    }
}
