<?php

namespace NoriaLabs\Payments\Support;

use NoriaLabs\Payments\Exceptions\ValidationException;

class FieldRules
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $rules
     *
     * @throws ValidationException
     */
    public static function assert(array $payload, array $rules, string $context): void
    {
        $errors = self::validate($payload, $rules);

        if ($errors === []) {
            return;
        }

        throw new ValidationException(
            $context.' payload is invalid: '.implode(' ', $errors),
            $errors,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<int, string>
     */
    public static function validate(array $payload, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            if (! array_key_exists($field, $payload)) {
                if ($rule['required'] ?? false) {
                    $errors[] = "[{$field}] is required.";
                }

                continue;
            }

            $value = $payload[$field];

            if ($value === null) {
                if ($rule['required'] ?? false) {
                    $errors[] = "[{$field}] is required and cannot be null.";
                }

                continue;
            }

            foreach (self::checkValue($field, $value, $rule) as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<int, string>
     */
    private static function checkValue(string $field, mixed $value, array $rule): array
    {
        $errors = [];

        if ($rule['boolean'] ?? false) {
            if (! is_bool($value)) {
                $errors[] = "[{$field}] must be a boolean.";
            }

            return $errors;
        }

        if (! is_scalar($value)) {
            $errors[] = "[{$field}] must be a scalar value.";

            return $errors;
        }

        if (($rule['numeric'] ?? false) && ! is_numeric($value)) {
            $errors[] = "[{$field}] must be numeric.";

            return $errors;
        }

        $string = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        if (($rule['notEmpty'] ?? false) && trim($string) === '') {
            $errors[] = "[{$field}] cannot be empty.";

            return $errors;
        }

        if (isset($rule['max']) && mb_strlen($string) > Setting::int($rule['max'])) {
            $errors[] = "[{$field}] must not exceed ".Setting::int($rule['max']).' characters, got '.mb_strlen($string).'.';
        }

        if (isset($rule['pattern']) && $string !== '' && preg_match(Setting::string($rule['pattern']), $string) !== 1) {
            $description = isset($rule['format'])
                ? ' Expected format: '.Setting::string($rule['format']).'.'
                : '';

            $errors[] = "[{$field}] is malformed.".$description;
        }

        return $errors;
    }
}
