<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Auth;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Restricts a route to holders of a permission: `can:order.transition.accept`.
 *
 * Preferred over a role gate wherever a specific capability is what matters.
 * Roles change - "support can now issue refunds" should be a row in
 * role_permissions, not a code change and a deploy.
 *
 * Several permissions may be listed; holding any one is enough, which suits
 * "an admin or the seller who owns it may do this".
 */
final class PermissionMiddleware implements Middleware
{
    public function handle(Request $request, string $argument = ''): ?Response
    {
        if ($argument === '') {
            throw new HttpException(500, 'Permission middleware was registered without a permission.');
        }

        if (Auth::guest()) {
            return (new AuthMiddleware())->handle($request);
        }

        foreach (explode(',', $argument) as $permission) {
            if (Auth::can(trim($permission))) {
                return null;
            }
        }

        throw new HttpException(403, 'You do not have permission to do that.');
    }
}
