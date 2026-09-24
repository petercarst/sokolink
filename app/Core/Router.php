<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

/**
 * A small routing table: method + path pattern -> controller action.
 *
 * Patterns use {name} placeholders, which match a single path segment.
 * Routes are named so templates never hard-code a URL - that is what makes it
 * possible to change a URL later without hunting through 80 view files.
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,action:mixed,name:?string,params:list<string>,middleware:list<string>}> */
    private static array $routes = [];

    /** @var array<string,string> route name => pattern */
    private static array $names = [];

    /** @var array<string,string> */
    private static array $currentParams = [];

    private static ?string $currentName = null;

    /** @var list<string> middleware inherited by routes registered inside a group */
    private static array $groupMiddleware = [];

    private static string $groupPrefix = '';

    /** @var array<string,class-string<\App\Core\Middleware\Middleware>> */
    private static array $middlewareMap = [
        'auth'     => \App\Core\Middleware\AuthMiddleware::class,
        'guest'    => \App\Core\Middleware\GuestMiddleware::class,
        'role'     => \App\Core\Middleware\RoleMiddleware::class,
        'can'      => \App\Core\Middleware\PermissionMiddleware::class,
        'verified' => \App\Core\Middleware\VerifiedMiddleware::class,
        'csrf'     => \App\Core\Middleware\CsrfMiddleware::class,
        'throttle' => \App\Core\Middleware\ThrottleMiddleware::class,
        'webhook'  => \App\Core\Middleware\WebhookMiddleware::class,
    ];

    /** @param list<string> $middleware */
    public static function get(string $pattern, mixed $action, ?string $name = null, array $middleware = []): void
    {
        self::add('GET', $pattern, $action, $name, $middleware);
    }

    /** @param list<string> $middleware */
    public static function post(string $pattern, mixed $action, ?string $name = null, array $middleware = []): void
    {
        self::add('POST', $pattern, $action, $name, $middleware);
    }

    /**
     * Registers several routes under shared middleware and an optional prefix.
     *
     *   Router::group(['prefix' => '/seller', 'middleware' => ['role:seller']], function () {
     *       Router::get('/orders', [...], 'seller.orders');
     *   });
     *
     * Groups nest: the inner group inherits the outer one's middleware rather
     * than replacing it, so a gate can never be widened by nesting.
     *
     * @param array{prefix?:string,middleware?:list<string>} $options
     */
    public static function group(array $options, callable $routes): void
    {
        $previousMiddleware = self::$groupMiddleware;
        $previousPrefix     = self::$groupPrefix;

        self::$groupMiddleware = array_merge($previousMiddleware, $options['middleware'] ?? []);
        self::$groupPrefix     = $previousPrefix . rtrim($options['prefix'] ?? '', '/');

        try {
            $routes();
        } finally {
            self::$groupMiddleware = $previousMiddleware;
            self::$groupPrefix     = $previousPrefix;
        }
    }

    /** @param list<string> $middleware */
    public static function add(
        string $method,
        string $pattern,
        mixed $action,
        ?string $name = null,
        array $middleware = []
    ): void {
        $pattern = self::$groupPrefix . '/' . trim($pattern, '/');
        $pattern = '/' . trim($pattern, '/');
        if ($pattern === '//') {
            $pattern = '/';
        }

        $params = [];
        $regex  = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        ) ?? $pattern;

        self::$routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'regex'   => '#^' . $regex . '$#',
            'action'  => $action,
            'name'    => $name,
            'params'  => $params,
            'middleware' => array_values(array_unique(array_merge(self::$groupMiddleware, $middleware))),
        ];

        if ($name !== null) {
            self::$names[$name] = $pattern;
        }
    }

    /**
     * Resolves and runs the matching route.
     *
     * @throws HttpException 404 when no path matches, 405 when the path matches
     *                       but the method does not.
     */
    public static function dispatch(Request $request): mixed
    {
        $path          = $request->path();
        $method         = $request->method();
        $pathMatched    = false;
        $allowedMethods = [];

        foreach (self::$routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $pathMatched      = true;
            $allowedMethods[] = $route['method'];

            if ($route['method'] !== $method) {
                continue;
            }

            array_shift($matches);

            $params = [];
            foreach ($route['params'] as $i => $paramName) {
                $params[$paramName] = $matches[$i] ?? '';
            }

            self::$currentParams = $params;
            self::$currentName   = $route['name'];

            // CSRF is applied to every POST here rather than listed per route.
            // Opt-in CSRF protection is protection that will eventually be
            // forgotten on the one route where it mattered.
            //
            // The one exemption is a `webhook` route: a payment provider's
            // server has no session and no token, and cannot have one. Its
            // authenticity comes from an HMAC signature over the payload,
            // verified before anything is acted on. The exemption is spelled
            // out on the route, in the route table, where it is visible -
            // rather than being the default that every other route opts out of.
            $middleware = $route['middleware'];
            $isWebhook  = in_array('webhook', $middleware, true);

            if ($route['method'] === 'POST' && !$isWebhook && !in_array('csrf', $middleware, true)) {
                array_unshift($middleware, 'csrf');
            }

            $short = self::runMiddleware($middleware, $request);
            if ($short instanceof Response) {
                return $short;
            }

            return self::run($route['action'], $params);
        }

        if ($pathMatched) {
            throw new HttpException(405, 'Method not allowed for this address.', [
                'Allow' => implode(', ', array_unique($allowedMethods)),
            ]);
        }

        throw new HttpException(404, 'We could not find that page.');
    }

    /**
     * Runs each gate in order. The first one to return a Response ends the
     * request; the rest never run, so a failed authentication check cannot be
     * followed by an authorisation check that happens to pass.
     *
     * @param list<string> $middleware
     */
    private static function runMiddleware(array $middleware, Request $request): ?Response
    {
        foreach ($middleware as $entry) {
            [$key, $argument] = array_pad(explode(':', $entry, 2), 2, '');

            if (!isset(self::$middlewareMap[$key])) {
                throw new HttpException(500, 'Unknown middleware: ' . $key);
            }

            $class    = self::$middlewareMap[$key];
            $instance = new $class();

            $response = $instance->handle($request, $argument);

            if ($response instanceof Response) {
                return $response;
            }
        }

        return null;
    }

    /** @param array<string,string> $params */
    private static function run(mixed $action, array $params): mixed
    {
        if (is_callable($action)) {
            return $action(...array_values($params));
        }

        if (is_array($action) && count($action) === 2) {
            [$class, $method] = $action;

            if (!class_exists($class)) {
                throw new HttpException(500, 'Controller not found: ' . $class);
            }

            $controller = new $class();

            if (!method_exists($controller, $method)) {
                throw new HttpException(500, 'Action not found: ' . $class . '::' . $method);
            }

            return $controller->{$method}(...array_values($params));
        }

        throw new HttpException(500, 'Route action is not callable.');
    }

    /**
     * Builds a URL for a named route.
     *
     * @param array<string,string|int> $params
     */
    public static function url(string $name, array $params = []): string
    {
        if (!isset(self::$names[$name])) {
            // A typo in a template should be loud in development and harmless
            // in production, never a fatal error on an otherwise fine page.
            if (Config::isDebug()) {
                throw new \RuntimeException('Unknown route name: ' . $name);
            }
            return Request::current()->basePath() . '/';
        }

        $pattern = self::$names[$name];
        $query   = [];

        foreach ($params as $key => $value) {
            $placeholder = '{' . $key . '}';

            if (str_contains($pattern, $placeholder)) {
                $pattern = str_replace($placeholder, rawurlencode((string) $value), $pattern);
                continue;
            }

            // Anything the path has no slot for becomes a query parameter
            // rather than being dropped. Silently discarding it produces a link
            // that looks right and has lost something - which is how a support
            // lookup ends up without the ticket reference that justified it.
            $query[$key] = (string) $value;
        }

        // Any placeholder left unfilled would produce a broken link.
        if (preg_match('#\{[a-zA-Z_][a-zA-Z0-9_]*\}#', $pattern) === 1 && Config::isDebug()) {
            throw new \RuntimeException('Missing parameter for route: ' . $name);
        }

        $base = Request::current()->basePath();

        // The site root keeps its trailing slash so the browser is not sent on a
        // 301 round trip by mod_dir before the page is served.
        $url = $pattern === '/' ? $base . '/' : $base . $pattern;

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    public static function currentName(): ?string
    {
        return self::$currentName;
    }

    /** @return array<string,string> */
    public static function currentParams(): array
    {
        return self::$currentParams;
    }

    public static function has(string $name): bool
    {
        return isset(self::$names[$name]);
    }

    /** @return array<int,array{method:string,pattern:string,name:?string,middleware:list<string>}> */
    public static function all(): array
    {
        return array_map(
            static fn (array $r): array => [
                'method'     => $r['method'],
                'pattern'    => $r['pattern'],
                'name'       => $r['name'],
                'middleware' => $r['middleware'],
            ],
            self::$routes
        );
    }
}
