<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Plain-PHP template rendering.
 *
 * A page is rendered to a string, then injected into a layout as $content.
 * Templates receive a flat array of already-prepared data - never a repository,
 * never a PDO handle (NFR-MNT-02). Every value they echo goes through e().
 */
final class View
{
    private static string $viewPath = '';

    /** @var array<string,mixed> data available to every template */
    private static array $shared = [];

    public static function setViewPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/' . DIRECTORY_SEPARATOR);
    }

    /** @param array<string,mixed> $data */
    public static function share(array $data): void
    {
        self::$shared = array_merge(self::$shared, $data);
    }

    public static function shared(string $key, mixed $default = null): mixed
    {
        return self::$shared[$key] ?? $default;
    }

    /**
     * Renders a page inside a layout.
     *
     * @param string              $page   e.g. "web/home" -> app/Views/pages/web/home.php
     * @param array<string,mixed> $data
     * @param string|null         $layout e.g. "public" -> app/Views/layouts/public.php,
     *                                    null renders the page with no layout
     */
    public static function render(string $page, array $data = [], ?string $layout = 'public'): string
    {
        $content = self::renderFile(self::$viewPath . '/pages/' . $page . '.php', $data);

        if ($layout === null) {
            return $content;
        }

        return self::renderFile(
            self::$viewPath . '/layouts/' . $layout . '.php',
            array_merge($data, ['content' => $content])
        );
    }

    /** @param array<string,mixed> $data */
    public static function partial(string $name, array $data = []): string
    {
        return self::renderFile(self::$viewPath . '/partials/' . $name . '.php', $data);
    }

    /** @param array<string,mixed> $data */
    public static function component(string $name, array $data = []): string
    {
        return self::renderFile(self::$viewPath . '/components/' . $name . '.php', $data);
    }

    /** @param array<string,mixed> $data */
    public static function exists(string $page): bool
    {
        return is_file(self::$viewPath . '/pages/' . $page . '.php');
    }

    /**
     * Loads a mock data file.
     *
     * PHASE 1 ONLY. Every one of these files carries a banner naming the
     * repository method that replaces it, and Phase 4 is not complete until
     * app/Views/_mock/ has been deleted (see docs/DEVELOPMENT_ROADMAP.md).
     *
     * @return array<string,mixed>|list<mixed>
     */
    public static function mock(string $name): array
    {
        $file = self::$viewPath . '/_mock/' . $name . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('Mock data file not found: ' . $name);
        }

        /** @var array<string,mixed>|list<mixed> $data */
        $data = require $file;

        return $data;
    }

    /** @param array<string,mixed> $data */
    private static function renderFile(string $file, array $data): string
    {
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $file);
        }

        $scope = array_merge(self::$shared, $data);

        // Keep the template's variable scope clean: only $scope keys plus the
        // two locals below exist inside the included file.
        $render = static function (string $__file, array $__scope): string {
            extract($__scope, EXTR_SKIP);
            ob_start();

            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }

            return (string) ob_get_clean();
        };

        return $render($file, $scope);
    }
}
