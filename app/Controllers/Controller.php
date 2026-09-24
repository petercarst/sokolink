<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Base controller.
 *
 * Controllers parse input, call services and choose a view. They contain no SQL
 * and no business rules - see docs/SYSTEM_ARCHITECTURE.md section 1.
 *
 * Phase 4 added the write side. Every POST handler in this application follows
 * the same shape, and it is worth stating once rather than repeating it in
 * thirteen docblocks:
 *
 *   1. Read input from the request.
 *   2. Call a service inside `attempt()`.
 *   3. On success: flash a message and redirect (303). Never render.
 *   4. On failure: flash the error, keep what was typed, redirect back.
 *
 * Step 3 is POST-redirect-GET, and it is the reason a browser refresh after
 * placing an order re-displays the confirmation instead of resubmitting the
 * form. Rendering directly from a POST would make the back button a duplicate
 * order waiting to happen.
 */
abstract class Controller
{
    protected function request(): Request
    {
        return Request::current();
    }

    /** @param array<string,mixed> $data */
    protected function view(string $page, array $data = [], string $layout = 'public'): Response
    {
        return Response::html(View::render($page, $data, $layout));
    }

    protected function redirect(string $url): Response
    {
        return Response::redirect($url);
    }

    /** @param array<string,string|int> $params */
    protected function toRoute(string $name, array $params = []): Response
    {
        return Response::redirect(route($name, $params));
    }

    /** Redirects back to the referring page, falling back to the site root. */
    protected function back(): Response
    {
        $referer = $this->request()->header('Referer');

        // Only follow a same-origin referer - an open redirect is a real bug,
        // not a theoretical one.
        if (is_string($referer) && $referer !== '') {
            $host = parse_url($referer, PHP_URL_HOST);
            if ($host === null || $host === ($_SERVER['HTTP_HOST'] ?? '')) {
                return Response::redirect($referer);
            }
        }

        return Response::redirect(url('/'));
    }

    /**
     * Runs a write action and turns its two expected failures into a redirect
     * the user can act on.
     *
     * ValidationException carries per-field messages, so the form is
     * repopulated and each message lands beside its field. DomainRuleException
     * is "that is not allowed" rather than "you typed it wrong", so it becomes
     * a single flash message. Anything else is a fault and is left to the
     * handler in Application, which logs it and shows a reference.
     *
     * @param callable():Response $action
     */
    protected function attempt(callable $action, ?Response $onFailure = null): Response
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            Session::flashInput($this->request()->postAll());
            Session::flashErrors($e->errors());
            Session::flash('error', $e->getMessage());
        } catch (DomainRuleException $e) {
            Session::flashInput($this->request()->postAll());
            Session::flash('error', $e->getMessage());
        }

        return $onFailure ?? $this->back();
    }

    protected function success(string $message, Response $next): Response
    {
        Session::flash('success', $message);

        return $next;
    }
}
