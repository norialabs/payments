<?php

namespace NoriaLabs\Payments\Support;

/**
 * Configuration arrives as whatever the host put in it. These narrow one
 * value at a time, so a misconfigured entry becomes a sane default here
 * rather than a TypeError somewhere inside a request.
 */
class Setting
{
    public static function string(mixed $value, string $fallback = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : $fallback;
    }

    public static function int(mixed $value, int $fallback = 0): int
    {
        return is_numeric($value) ? (int) $value : $fallback;
    }

    public static function float(mixed $value, ?float $fallback = null): ?float
    {
        return is_numeric($value) ? (float) $value : $fallback;
    }

    /**
     * A query string carries scalars and nothing else. Anything richer is
     * dropped rather than stringified into something the provider misreads.
     *
     * @return array<string, bool|float|int|string|null>|null
     */
    public static function query(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $query = [];

        foreach ($value as $key => $entry) {
            if (is_string($key) && ($entry === null || is_scalar($entry))) {
                $query[$key] = $entry;
            }
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    public static function stringMap(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $key => $entry) {
            if (is_string($key) && is_scalar($entry)) {
                $map[$key] = (string) $entry;
            }
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $key => $entry) {
            if (is_string($key)) {
                $map[$key] = $entry;
            }
        }

        return $map;
    }
}
