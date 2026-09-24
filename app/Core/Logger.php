<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Line-based file logging (NFR-OPS-02).
 *
 * Channels are separate files so an operator can tail security events without
 * wading through request noise. Timestamps are UTC to match storage.
 */
final class Logger
{
    public const APP           = 'app';
    public const SECURITY      = 'security';
    public const NOTIFICATIONS = 'notifications';
    public const CLI           = 'cli';

    private static string $logPath = '';

    public static function setLogPath(string $path): void
    {
        self::$logPath = rtrim($path, '/' . DIRECTORY_SEPARATOR);
    }

    /** @param array<string,mixed> $context */
    public static function write(string $channel, string $level, string $message, array $context = []): void
    {
        if (self::$logPath === '' || !is_dir(self::$logPath)) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s%s",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . (string) json_encode($context, JSON_UNESCAPED_SLASHES),
            PHP_EOL
        );

        // Suppressed: logging must never be the thing that breaks a response.
        @file_put_contents(self::$logPath . '/' . $channel . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write(self::APP, 'info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write(self::APP, 'warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write(self::APP, 'error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function security(string $message, array $context = []): void
    {
        self::write(self::SECURITY, 'notice', $message, $context);
    }

    /**
     * Logs a throwable and returns a short reference the user can quote to
     * support. The user never sees the exception itself (NFR-SEC-09).
     */
    /**
     * The outbound mail log.
     *
     * A channel of its own so a developer can read exactly what would have been
     * sent without wading through request logs - and so it is obvious that the
     * "log" mail driver writes messages rather than delivering them.
     */
    public static function mail(string $message): void
    {
        self::write('mail', 'info', $message);
    }

    public static function exception(Throwable $e): string
    {
        $reference = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        self::write(self::APP, 'error', 'Unhandled exception [' . $reference . ']', [
            'type'    => $e::class,
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => explode("\n", $e->getTraceAsString()),
        ]);

        return $reference;
    }
}
