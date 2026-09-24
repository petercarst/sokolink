<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal PSR-4 autoloader.
 *
 * Composer's autoloader is used when vendor/autoload.php exists. This class is
 * the fallback so the application runs on a plain XAMPP install with no
 * Composer step at all - which matters because the whole point of Phase 1 is
 * that you can open it in a browser immediately.
 */
final class Autoloader
{
    private const NS_SEPARATOR = '\\';

    /** @var array<string,string> namespace prefix => base directory */
    private array $prefixes = [];

    public function addNamespace(string $prefix, string $baseDir): void
    {
        $prefix = trim($prefix, self::NS_SEPARATOR) . self::NS_SEPARATOR;

        $this->prefixes[$prefix] = rtrim($baseDir, '/' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public function register(): void
    {
        spl_autoload_register([$this, 'load']);
    }

    public function load(string $class): void
    {
        foreach ($this->prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file     = $baseDir . str_replace(self::NS_SEPARATOR, DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
}
