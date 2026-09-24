<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * An exception that carries an HTTP status code.
 *
 * Anything thrown as an HttpException is considered a message we are willing to
 * show the user. Every other exception is treated as an internal fault: the
 * user sees a generic page with a reference id, and the detail goes to the log
 * (NFR-SEC-09).
 */
class HttpException extends RuntimeException
{
    /** @var array<string,string> */
    private array $headers;

    /** @param array<string,string> $headers */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        array $headers = []
    ) {
        parent::__construct($message, $statusCode);
        $this->headers = $headers;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
