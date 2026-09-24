<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Auth;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Restricts a route to one or more roles: `role:seller` or `role:support,admin`.
 *
 * The role comes from the database via the session user. Nothing here reads a
 * request parameter, a cookie or a header, which is what makes "never trust a
 * role supplied by the browser" true by construction rather than by vigilance.
 *
 * A role gate is coarse. It answers "should this person see this area at all?"
 * Whether they may act on a particular row is a separate question, answered by
 * the service that owns the row - an agent with the delivery role still cannot
 * touch a task assigned to somebody else.
 */
final class RoleMiddleware implements Middleware
{
    public function handle(Request $request, string $argument = ''): ?Response
    {
        if ($argument === '') {
            throw new HttpException(500, 'Role middleware was registered without a role.');
        }

        $roles = array_map('trim', explode(',', $argument));

        if (Auth::guest()) {
            return (new AuthMiddleware())->handle($request);
        }

        if (Auth::hasRole(...$roles)) {
            return null;
        }

        throw new HttpException(403, 'Your account does not have access to this area.');
    }
}
