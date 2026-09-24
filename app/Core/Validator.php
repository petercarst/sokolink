<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Server-side validation.
 *
 * Every rule here also exists in the browser, and the browser copy is a
 * courtesy. This one is the one that counts: nothing reaches a repository
 * without passing through it, because "the form checked it" is not a security
 * control (NFR-SEC-06).
 *
 * Messages are written to be shown to a person - "Enter an email address" -
 * rather than to describe the rule that failed.
 *
 *   $v = Validator::make($request->all(), [
 *       'email'    => 'required|email|max:190',
 *       'password' => 'required|min:10',
 *       'quantity' => 'required|integer|between:1,999',
 *   ], ['email' => 'Email address']);
 *
 *   if ($v->fails()) { return back with $v->errors(); }
 *   $clean = $v->validated();
 */
final class Validator
{
    /** @var array<string,mixed> */
    private array $data;

    /** @var array<string,string> field => pipe-separated rules */
    private array $rules;

    /** @var array<string,string> field => human label */
    private array $labels;

    /** @var array<string,string> field => first error */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $validated = [];

    /**
     * Whether the field being checked was declared numeric.
     *
     * It decides what min/max/between MEASURE. A phone number written
     * "0712000222" is numeric to is_numeric(), so without this, `max:32` asked
     * whether 712,000,222 was under 32 rather than whether the string was 32
     * characters. Size rules follow the declared type, never the value's shape.
     */
    private bool $numericField = false;

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    private function __construct(array $data, array $rules, array $labels)
    {
        $this->data   = $data;
        $this->rules  = $rules;
        $this->labels = $labels;

        $this->run();
    }

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $message) {
            return $message;
        }

        return null;
    }

    /**
     * Only the fields that had rules, cast to the type the rules implied.
     * Anything the caller did not ask about is dropped, so an extra field
     * posted by a curious user cannot reach an INSERT.
     *
     * @return array<string,mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /** Adds an error discovered after the rules ran - a unique check, say. */
    public function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    // ---- The engine --------------------------------------------------------

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = array_filter(explode('|', $ruleString));
            $value = $this->data[$field] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            $this->numericField = in_array('integer', $rules, true)
                || in_array('numeric', $rules, true)
                || in_array('decimal', $rules, true);

            $isRequired = in_array('required', $rules, true);
            $isPresent  = $value !== null && $value !== '' && $value !== [];

            if (!$isPresent) {
                if ($isRequired) {
                    $this->fail($field, 'required', $rules);
                    continue;
                }

                // Absent and optional: record null and skip the rest, so
                // "max:10" does not complain about a field nobody filled in.
                $this->validated[$field] = in_array('nullable', $rules, true) ? null : $value;
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }

                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, '');

                $result = $this->check($name, $value, $argument, $field);

                if ($result === false) {
                    $this->fail($field, $name, $rules, $argument);
                    continue 2;
                }

                // A rule may normalise the value - integer casts, for example.
                if ($result !== true) {
                    $value = $result;
                }
            }

            $this->validated[$field] = $value;
        }
    }

    /** @return bool|mixed true, false, or the normalised value */
    private function check(string $rule, mixed $value, string $argument, string $field): mixed
    {
        return match ($rule) {
            'email'    => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url'      => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'integer'  => $this->asInteger($value),
            'numeric'  => is_numeric($value) ? (float) $value : false,
            'decimal'  => $this->asDecimal($value, $argument),
            'boolean'  => $this->asBoolean($value),
            'string'   => is_string($value),
            'array'    => is_array($value),
            'min'      => $this->compareSize($value, (float) $argument, '>='),
            'max'      => $this->compareSize($value, (float) $argument, '<='),
            'between'  => $this->between($value, $argument),
            'size'     => $this->compareSize($value, (float) $argument, '=='),
            'in'       => in_array((string) $value, explode(',', $argument), true),
            'not_in'   => !in_array((string) $value, explode(',', $argument), true),
            'regex'    => is_string($value) && preg_match($argument, $value) === 1,
            'alpha'    => is_string($value) && preg_match('/^[\p{L}]+$/u', $value) === 1,
            'alpha_num' => is_string($value) && preg_match('/^[\p{L}\p{N}]+$/u', $value) === 1,
            'slug'     => is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1,
            'phone'    => $this->isPhone($value),
            'date'     => $this->isDate($value),
            'confirmed' => $this->isConfirmed($value, $field),
            'same'     => isset($this->data[$argument]) && $value === $this->data[$argument],
            'different' => !isset($this->data[$argument]) || $value !== $this->data[$argument],
            default    => true,
        };
    }

    private function asInteger(mixed $value): int|false
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return false;
    }

    private function asDecimal(mixed $value, string $places): string|false
    {
        $places = $places === '' ? '2' : $places;

        if (!is_numeric($value)) {
            return false;
        }

        // Money is stored as DECIMAL, so it travels as a string. Casting to
        // float here would reintroduce exactly the precision problem the
        // schema avoids.
        return number_format((float) $value, (int) $places, '.', '');
    }

    private function asBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
    }

    private function compareSize(mixed $value, float $limit, string $operator): bool
    {
        $size = match (true) {
            is_array($value)                    => (float) count($value),
            is_int($value), is_float($value)    => (float) $value,
            $this->numericField && is_numeric($value) => (float) $value,
            default                             => (float) mb_strlen((string) $value),
        };

        return match ($operator) {
            '>='    => $size >= $limit,
            '<='    => $size <= $limit,
            default => abs($size - $limit) < 0.000001,
        };
    }

    private function between(mixed $value, string $argument): bool
    {
        [$low, $high] = array_pad(explode(',', $argument, 2), 2, '0');

        return $this->compareSize($value, (float) $low, '>=')
            && $this->compareSize($value, (float) $high, '<=');
    }

    private function isPhone(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        // Deliberately permissive: Tanzanian numbers are written +255..., 0...,
        // and with spaces. Rejecting a real number because of a space is worse
        // than accepting a shape we will confirm by sending to it anyway.
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

        return mb_strlen($digits) >= 9 && mb_strlen($digits) <= 15;
    }

    private function isDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return strtotime($value) !== false;
    }

    private function isConfirmed(mixed $value, string $field): bool
    {
        return ($this->data[$field . '_confirmation'] ?? null) === $value;
    }

    // ---- Messages ----------------------------------------------------------

    /** @param list<string> $rules */
    private function fail(string $field, string $rule, array $rules, string $argument = ''): void
    {
        $label = $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));

        $this->errors[$field] = match ($rule) {
            'required'  => sprintf('%s is required.', $label),
            'email'     => 'Enter a valid email address.',
            'url'       => 'Enter a valid web address.',
            'integer'   => sprintf('%s must be a whole number.', $label),
            'numeric', 'decimal' => sprintf('%s must be a number.', $label),
            'min'       => $this->sizeMessage($label, $rules, 'at least', $argument),
            'max'       => $this->sizeMessage($label, $rules, 'no more than', $argument),
            'between'   => sprintf('%s must be between %s.', $label, str_replace(',', ' and ', $argument)),
            'size'      => sprintf('%s must be exactly %s characters.', $label, $argument),
            'in'        => sprintf('Choose a valid option for %s.', mb_strtolower($label)),
            'slug'      => sprintf('%s may contain lowercase letters, numbers and hyphens only.', $label),
            'phone'     => 'Enter a valid phone number.',
            'date'      => sprintf('%s must be a valid date.', $label),
            'confirmed' => sprintf('%s does not match the confirmation.', $label),
            'same'      => sprintf('%s does not match.', $label),
            'alpha'     => sprintf('%s may contain letters only.', $label),
            'alpha_num' => sprintf('%s may contain letters and numbers only.', $label),
            default     => sprintf('%s is not valid.', $label),
        };
    }

    /** @param list<string> $rules */
    private function sizeMessage(string $label, array $rules, string $phrase, string $argument): string
    {
        $isNumeric = in_array('integer', $rules, true)
            || in_array('numeric', $rules, true)
            || in_array('decimal', $rules, true);

        return $isNumeric
            ? sprintf('%s must be %s %s.', $label, $phrase, $argument)
            : sprintf('%s must be %s %s characters.', $label, $phrase, $argument);
    }
}
