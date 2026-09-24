<?php

declare(strict_types=1);

/**
 * An in-process HTTP client for the real application.
 *
 * Phase 3 tested services by calling them. That proves the business rules are
 * right; it does not prove a form reaches them. A page can be wired to the
 * wrong route, drop a field, skip CSRF, or render a 500 on data the service
 * handles perfectly - and every service test would still pass.
 *
 * So Phase 4 tests go in through the front door: build a request, hand it to
 * Router::dispatch(), and look at the Response and at the database rows it
 * produced. Nothing is stubbed. The controllers, the middleware, the CSRF
 * check, the services, the repositories and MySQL are all the real ones.
 *
 * Two deliberate differences from a browser:
 *
 *   1. $_SESSION is a plain array rather than a real session, because PHP will
 *      not start a session on the CLI. Everything the application does with it
 *      works identically.
 *   2. Response::send() is never called, so nothing is echoed and no header is
 *      emitted. The Response object is inspected directly.
 */

use App\Core\Application;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Support\GuestCart;

final class Http
{
    private static bool $booted = false;

    private static string $host = 'localhost';

    /** Boots the framework once. Routes are registered once and reused. */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        $root = dirname(__DIR__, 2);

        self::fakeServer('GET', '/');

        require_once $root . '/app/Core/Application.php';

        Application::boot($root);

        self::$booted = true;
    }

    public static function get(string $path, array $query = []): Response
    {
        return self::dispatch('GET', $path, $query, []);
    }

    /**
     * A POST with a valid CSRF token already attached.
     *
     * The token is fetched the way a page would render it, so this exercises
     * the real verification rather than bypassing it. postWithoutToken() is the
     * counterpart used to prove the check actually fires.
     *
     * @param array<string,mixed> $body
     */
    public static function post(string $path, array $body = []): Response
    {
        $body[Csrf::FIELD] ??= Csrf::token();

        return self::dispatch('POST', $path, [], $body);
    }

    /** @param array<string,mixed> $body */
    public static function postWithoutToken(string $path, array $body = []): Response
    {
        return self::dispatch('POST', $path, [], $body);
    }

    /**
     * Follows one redirect, the way a browser would after a POST.
     *
     * Only one hop: a handler that redirects to a handler that redirects is
     * usually a bug, and following silently would hide it.
     */
    public static function follow(Response $response): Response
    {
        $location = self::header($response, 'Location');

        if ($location === null) {
            throw new RuntimeException('Response is not a redirect; nothing to follow.');
        }

        $path  = (string) parse_url($location, PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        // Strip the base path the router added when building the URL.
        $base = Request::current()->basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return self::get($path === '' ? '/' : $path, $query);
    }

    /** Everything a browser would forget: session, identity, basket cookie. */
    public static function newVisitor(): void
    {
        $_SESSION = [];
        $_COOKIE  = [];

        Auth::logout();
        Auth::forgetCache();

        // The cookie helper caches the token it issued for the current request.
        (function (): void {
            $ref = new ReflectionClass(GuestCart::class);
            $ref->setStaticPropertyValue('pendingToken', null);
        })();

        self::clearOldInput();
    }

    /** @return list<array{type:string,message:string}> */
    public static function flashes(): array
    {
        /** @var list<array{type:string,message:string}> $flashes */
        $flashes = $_SESSION['_flash'] ?? [];

        return $flashes;
    }

    /** The messages a page would show, joined, for an assertion message. */
    public static function flashText(): string
    {
        return implode(' | ', array_map(
            static fn (array $f): string => $f['type'] . ': ' . $f['message'],
            self::flashes()
        ));
    }

    /** @return array<string,string|list<string>> */
    public static function errors(): array
    {
        /** @var array<string,string|list<string>> $errors */
        $errors = $_SESSION['_errors'] ?? [];

        return $errors;
    }

    public static function redirectedTo(Response $response): ?string
    {
        return self::header($response, 'Location');
    }

    public static function header(Response $response, string $name): ?string
    {
        $reflection = new ReflectionProperty(Response::class, 'headers');
        /** @var array<string,string> $headers */
        $headers = $reflection->getValue($response);

        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** True when the rendered body contains the text, ignoring HTML escaping of quotes. */
    public static function sees(Response $response, string $needle): bool
    {
        return str_contains($response->body(), $needle);
    }

    // ---- internals -------------------------------------------------------

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     */
    private static function dispatch(string $method, string $path, array $query, array $body): Response
    {
        self::boot();

        self::fakeServer($method, $path);

        $_GET  = $query;
        $_POST = $body;

        self::resetRequest();

        // Application::boot() does this once for a real request; the harness
        // does it per request, so a flash set by the previous one is consumed
        // by the page that would have shown it.
        Session::hydrateOldInput();
        View::share([
            'appName'     => 'SokoLink',
            'appEnv'      => 'test',
            'isDebug'     => false,
            'flashes'     => Session::takeFlashes(),
            'currentYear' => (int) gmdate('Y'),
        ]);

        try {
            $result = Router::dispatch(Request::current());

            return $result instanceof Response
                ? $result
                : Response::html(is_string($result) ? $result : '');
        } catch (HttpException $e) {
            return Response::html('HTTP ' . $e->statusCode() . ': ' . $e->getMessage(), $e->statusCode());
        }
    }

    private static function fakeServer(string $method, string $path): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $path;
        $_SERVER['HTTP_HOST']      = self::$host;
        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'SokoLink-Test/1.0';
        $_SERVER['SCRIPT_NAME']    = '/index.php';
    }

    /** Request is a per-request singleton; a new request needs a new one. */
    private static function resetRequest(): void
    {
        $reflection = new ReflectionClass(Request::class);
        $reflection->setStaticPropertyValue('instance', null);

        Request::capture();
    }

    private static function clearOldInput(): void
    {
        unset($_SESSION['_old'], $_SESSION['_errors'], $_SESSION['_flash']);

        Session::hydrateOldInput();
    }
}
