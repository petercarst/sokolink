<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * A gate that runs before a controller action.
 *
 * Return null to let the request continue, or a Response to stop it here.
 * Throwing an HttpException is equally valid and is what the authorisation
 * gates do, because a 403 page is the same page wherever it is raised from.
 *
 * Middleware is where the brief's "implement permission checks on the backend,
 * not only by hiding frontend buttons" actually lands. A hidden button is a
 * courtesy; this is the control.
 */
interface Middleware
{
    public function handle(Request $request, string $argument = ''): ?Response;
}
