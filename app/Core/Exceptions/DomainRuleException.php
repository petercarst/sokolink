<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * A business rule said no.
 *
 * "Only two of these are left", "this order has already been collected",
 * "your account is not approved to sell yet". The message is written to be
 * shown to the person who hit it, so a controller can flash it directly.
 *
 * The distinction from a plain RuntimeException matters at the top of the
 * stack: a DomainRuleException is an expected outcome and gets shown; anything
 * else is a fault and gets logged with a reference and a generic page.
 */
class DomainRuleException extends RuntimeException
{
    /** Optional machine-readable code, for the cases a caller must branch on. */
    private string $reason;

    public function __construct(string $message, string $reason = '')
    {
        $this->reason = $reason;

        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
