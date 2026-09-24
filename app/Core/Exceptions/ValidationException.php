<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * Input the user can fix by changing what they typed.
 *
 * Carries per-field messages so a controller can repopulate the form and show
 * each error beside the field it belongs to, rather than one banner at the top
 * saying something went wrong.
 *
 * Distinct from DomainRuleException, which is "what you asked for is not
 * allowed" - a different thing, shown differently, and usually not fixable by
 * editing a field.
 */
final class ValidationException extends RuntimeException
{
    /** @var array<string,string> */
    private array $errors;

    /** @param array<string,string> $errors */
    public function __construct(array $errors, string $message = '')
    {
        $this->errors = $errors;

        parent::__construct($message !== '' ? $message : (reset($errors) ?: 'Check the form and try again.'));
    }

    public static function forField(string $field, string $message): self
    {
        return new self([$field => $message]);
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
