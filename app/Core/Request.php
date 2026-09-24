<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish wrapper around the incoming HTTP request.
 *
 * The interesting part is base-path detection, which lets the same code run at
 * both of the URLs a XAMPP user might use:
 *
 *   http://localhost/e-commerce/            (root .htaccess rewrites into public/)
 *   http://localhost/e-commerce/public/     (direct)
 *   http://sokolink.test/                   (vhost with DocumentRoot = public/)
 *
 * Without this, every link on every page would break depending on how the site
 * was served. See docs/PROJECT_REQUIREMENTS.md OQ-05.
 */
final class Request
{
    private static ?self $instance = null;

    private string $method;
    private string $path;
    private string $basePath;

    /** @var array<string,mixed> */
    private array $query;

    /** @var array<string,mixed> */
    private array $post;

    private function __construct()
    {
        $this->method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $this->query  = $_GET;
        $this->post   = $_POST;

        $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $uriPath = '/' . ltrim(rawurldecode($uriPath), '/');

        $this->basePath = $this->detectBasePath($uriPath);
        $this->path     = $this->detectPath($uriPath, $this->basePath);
    }

    public static function capture(): self
    {
        return self::$instance ??= new self();
    }

    public static function current(): self
    {
        return self::capture();
    }

    private function detectBasePath(string $uriPath): string
    {
        $configured = (string) Config::get('app.base_path', '');
        if ($configured !== '') {
            return '/' . trim($configured, '/');
        }

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptDir  = rtrim(dirname($scriptName), '/');

        if ($scriptDir === '' || $scriptDir === '.') {
            return '';
        }

        // Case 1: the browser really is under /.../public
        if ($uriPath === $scriptDir || str_starts_with($uriPath, $scriptDir . '/')) {
            return $scriptDir;
        }

        // Case 2: root .htaccess rewrote /e-commerce/x into /e-commerce/public/index.php,
        // so SCRIPT_NAME says ".../public" but the browser URL does not.
        if (basename($scriptDir) === 'public') {
            $parent = rtrim(dirname($scriptDir), '/');
            if ($parent === '' || $parent === '.' || $parent === '/') {
                return '';
            }
            if ($uriPath === $parent || str_starts_with($uriPath, $parent . '/')) {
                return $parent;
            }
        }

        return '';
    }

    private function detectPath(string $uriPath, string $basePath): string
    {
        if ($basePath !== '' && str_starts_with($uriPath, $basePath)) {
            $uriPath = substr($uriPath, strlen($basePath));
        }

        // Tolerate /public in the path when someone links to it directly.
        if (str_starts_with($uriPath, '/public/')) {
            $uriPath = substr($uriPath, 7);
        } elseif ($uriPath === '/public') {
            $uriPath = '/';
        }

        $uriPath = '/' . trim($uriPath, '/');

        return $uriPath === '//' ? '/' : $uriPath;
    }

    public function method(): string
    {
        // Allow HTML forms to emulate PUT/PATCH/DELETE via a _method field.
        if ($this->method === 'POST' && isset($this->post['_method'])) {
            $spoofed = strtoupper((string) $this->post['_method']);
            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoofed;
            }
        }

        return $this->method;
    }

    public function realMethod(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function isPost(): bool
    {
        return $this->realMethod() === 'POST';
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    /** @return array<string,mixed> the submitted body, used to repopulate a failed form */
    public function postAll(): array
    {
        return $this->post;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->post[$key]) || isset($this->query[$key]);
    }

    /**
     * Query string rebuilt with some parameters replaced - used by filter and
     * pagination links so they preserve the rest of the current filters.
     *
     * @param array<string,mixed> $overrides  a null value removes the key
     */
    public function queryStringWith(array $overrides): string
    {
        $params = array_merge($this->query, $overrides);
        $params = array_filter(
            $params,
            static fn ($v) => $v !== null && $v !== '' && $v !== []
        );

        return $params === [] ? '' : '?' . http_build_query($params);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : $default;
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    public function isSecure(): bool
    {
        return (($_SERVER['HTTPS'] ?? 'off') !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
    }

    public function wantsJson(): bool
    {
        return str_contains((string) $this->header('Accept', ''), 'application/json')
            || $this->header('X-Requested-With') === 'XMLHttpRequest';
    }
}
