<?php

namespace NoriaLabs\Payments\Support;

class Payload
{
    /**
     * @param  array<int, string>  $keys
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalizeKenyanPhoneNumbers(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload) || (! is_string($payload[$key]) && ! is_int($payload[$key]))) {
                continue;
            }

            $payload[$key] = self::normalizeKenyanPhoneNumber($payload[$key]);
        }

        return $payload;
    }

    public static function normalizeKenyanPhoneNumber(string|int $value): string|int
    {
        $raw = (string) $value;

        if (preg_match('/^[\d\s()+-]+$/', $raw) !== 1) {
            return $value;
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if (preg_match('/^0([17]\d{8})$/', $digits, $matches) === 1) {
            return '254'.$matches[1];
        }

        if (preg_match('/^[17]\d{8}$/', $digits) === 1) {
            return '254'.$digits;
        }

        if (preg_match('/^254[17]\d{8}$/', $digits) === 1) {
            return $digits;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalizeAmount(array $payload, mixed $normalization = 'string'): array
    {
        if (self::resolveAmountNormalization($normalization) === 'none') {
            return $payload;
        }

        return self::stringifyAmount($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function stringifyAmount(array $payload): array
    {
        foreach (['Amount', 'amount'] as $key) {
            if (array_key_exists($key, $payload) && is_scalar($payload[$key])) {
                $payload[$key] = self::amountToString($payload[$key]);
            }
        }

        return $payload;
    }

    public static function amountToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return (string) $value;
            }

            $formatted = number_format($value, 8, '.', '');

            return str_contains($formatted, '.')
                ? rtrim(rtrim($formatted, '0'), '.')
                : $formatted;
        }

        return Setting::string($value);
    }

    public static function resolveAmountNormalization(mixed $value): string
    {
        $normalized = strtolower(trim(Setting::string($value ?? null, 'string')));

        return match ($normalized) {
            'none', 'raw', 'preserve' => 'none',
            default => 'string',
        };
    }
}
