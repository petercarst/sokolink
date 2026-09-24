<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;

/**
 * Marks a route as a server-to-server callback rather than a browser form.
 *
 * Its presence is what tells the router to skip the automatic CSRF check, and
 * that is the whole reason it exists as a named middleware: the exemption shows
 * up in the route table, beside the route it applies to, instead of being an
 * invisible default.
 *
 * It replaces the CSRF token with nothing, because nothing is what a payment
 * provider can offer - no session, no cookie, no token. What authenticates the
 * request is an HMAC signature over the payload, checked inside the gateway
 * driver before a single field is believed. This middleware does not do that
 * check and must not be mistaken for it; an unsigned request is refused by
 * PaymentService, which records it and acts on nothing.
 *
 * What it does do is make sure a request that reaches a webhook endpoint is
 * shaped like one, and leave a trace of every request that does.
 */
final class WebhookMiddleware implements Middleware
{
    public function handle(Request $request, string $argument = ''): ?Response
    {
        // Logged before anything is parsed, so a flood of unsigned callbacks is
        // visible even though every one of them will be refused.
        Logger::info('Inbound webhook', [
            'path' => $request->path(),
            'ip'   => $request->ip(),
            'has_signature' => $request->header('X-Signature') !== null
                || $request->input('signature') !== null,
        ]);

        return null;
    }
}
