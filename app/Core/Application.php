<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use Throwable;

/**
 * Boots the application and runs one request.
 *
 * Kept deliberately small and readable: this is the whole "framework" startup,
 * and someone new should be able to read it top to bottom in a couple of
 * minutes and know exactly what happens before a controller runs.
 */
final class Application
{
    private static string $root = '';

    public static function boot(string $rootPath): void
    {
        self::$root = rtrim($rootPath, '/' . DIRECTORY_SEPARATOR);

        self::registerAutoloading();

        // Storage timezone is UTC, always (NFR-DAT-05). Display conversion is
        // the Clock class's job and happens at render time only.
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');

        Env::load([self::$root . '/.env', self::$root . '/.env.example']);
        Config::boot();

        Logger::setLogPath(self::$root . '/storage/logs');
        View::setViewPath(self::$root . '/app/Views');

        self::configureErrorReporting();
        self::registerHandlers();

        Request::capture();
        Session::start();

        // Before anything reads them: last request's input and errors come out
        // of the session now, so they live for exactly this request.
        Session::hydrateOldInput();

        self::shareGlobalViewData();

        require self::$root . '/routes/web.php';
    }

    /**
     * Boot for the command line: no request, no session, no routes.
     *
     * The scheduled tasks and the test scripts use this. Keeping it separate
     * means a CLI task cannot accidentally depend on a session - which is the
     * whole point of the brief's rule that reminders must not need a browser
     * to be open.
     */
    public static function bootConsole(string $rootPath): void
    {
        self::$root = rtrim($rootPath, '/' . DIRECTORY_SEPARATOR);

        self::registerAutoloading();

        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');

        Env::load([self::$root . '/.env', self::$root . '/.env.example']);
        Config::boot();

        Logger::setLogPath(self::$root . '/storage/logs');
        View::setViewPath(self::$root . '/app/Views');

        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        ini_set('log_errors', '1');
        ini_set('error_log', self::$root . '/storage/logs/php.log');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public static function root(): string
    {
        return self::$root;
    }

    private static function registerAutoloading(): void
    {
        $composer = self::$root . '/vendor/autoload.php';

        if (is_file($composer)) {
            require $composer;
            require_once __DIR__ . '/helpers.php';
            return;
        }

        // No Composer install needed to run the app - see Autoloader's docblock.
        require_once __DIR__ . '/Autoloader.php';

        $autoloader = new Autoloader();
        $autoloader->addNamespace('App', self::$root . '/app');
        $autoloader->register();

        require_once __DIR__ . '/helpers.php';
    }

    private static function configureErrorReporting(): void
    {
        error_reporting(E_ALL);

        // Never render PHP errors into the page. They go to the log, and the
        // user gets a generic page with a reference id.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', self::$root . '/storage/logs/php.log');
    }

    private static function registerHandlers(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            self::renderThrowable($e)->send();
        });
    }

    private static function shareGlobalViewData(): void
    {
        View::share([
            'appName'     => (string) Config::get('app.name', 'SokoLink'),
            'appEnv'      => (string) Config::get('app.env', 'local'),
            'isDebug'     => Config::isDebug(),
            'flashes'     => Session::takeFlashes(),
            'currentYear' => (int) gmdate('Y'),
        ]);
    }

    public static function run(): void
    {
        try {
            $result = Router::dispatch(Request::current());

            $response = $result instanceof Response
                ? $result
                : Response::html(is_string($result) ? $result : '');

            // Written and unlocked before the body goes out, so the next
            // request from the same browser is not waiting on this one.
            Session::commit();

            $response->send();
        } catch (Throwable $e) {
            Session::commit();

            self::renderThrowable($e)->send();
        }
    }

    /**
     * Turns any throwable into a response.
     *
     * HttpException messages are considered safe to show. Everything else is an
     * internal fault: logged with a reference, shown generically.
     */
    private static function renderThrowable(Throwable $e): Response
    {
        $isHttp  = $e instanceof HttpException;
        $status  = $isHttp ? $e->statusCode() : 500;
        $headers = $isHttp ? $e->headers() : [];

        $reference = null;
        if (!$isHttp) {
            $reference = Logger::exception($e);
        }

        if (Request::current()->wantsJson()) {
            return Response::json([
                'error'     => $isHttp ? $e->getMessage() : 'An unexpected error occurred.',
                'reference' => $reference,
            ], $status);
        }

        $page = match ($status) {
            403, 429 => 'errors/403',
            404, 405 => 'errors/404',
            default  => 'errors/500',
        };

        try {
            $body = View::render($page, [
                'title'     => match ($status) {
                    403      => 'Access denied',
                    429      => 'Too many requests',
                    404, 405 => 'Page not found',
                    default  => 'Something went wrong',
                },
                'status'    => $status,
                'message'   => $isHttp ? $e->getMessage() : 'An unexpected error occurred on our side.',
                'reference' => $reference,
                'exception' => (!$isHttp && Config::isDebug()) ? $e : null,
            ], 'public');
        } catch (Throwable $inner) {
            // The error page itself failed. Fall back to plain text so the user
            // still gets something coherent rather than a blank screen.
            Logger::error('Error page failed to render', ['message' => $inner->getMessage()]);

            $body = '<!doctype html><meta charset="utf-8"><title>Error</title>'
                . '<body style="font-family:system-ui;padding:3rem;max-width:40rem;margin:auto">'
                . '<h1>Something went wrong</h1><p>Please try again.</p>'
                . ($reference !== null ? '<p>Reference: <code>' . e($reference) . '</code></p>' : '')
                . '</body>';
        }

        return Response::html($body, $status, $headers);
    }
}
