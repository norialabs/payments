<?php

namespace NoriaLabs\Payments\Support;

class RetryPolicy
{
    /**
     * @param  array<int, string>  $retryMethods
     * @param  array<int, int>  $retryOnStatuses
     */
    public function __construct(
        public readonly int $maxAttempts = 1,
        public readonly array $retryMethods = [],
        public readonly array $retryOnStatuses = [],
        public readonly bool $retryOnNetworkError = false,
        public readonly float $baseDelaySeconds = 0.0,
        public readonly float $maxDelaySeconds = 60.0,
        public readonly float $backoffMultiplier = 2.0,
        public readonly float $jitterSeconds = 0.0,
        public readonly bool $respectRetryAfter = true,
        public readonly mixed $shouldRetry = null,
        public readonly mixed $sleeper = null,
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        return new self(
            maxAttempts: Setting::int($value['max_attempts'] ?? null, 1),
            retryMethods: self::strings($value['retry_methods'] ?? null),
            retryOnStatuses: self::ints($value['retry_on_statuses'] ?? null),
            retryOnNetworkError: (bool) ($value['retry_on_network_error'] ?? false),
            baseDelaySeconds: Setting::float($value['base_delay_seconds'] ?? null, 0.0) ?? 0.0,
            maxDelaySeconds: Setting::float($value['max_delay_seconds'] ?? null, 60.0) ?? 60.0,
            backoffMultiplier: Setting::float($value['backoff_multiplier'] ?? null, 2.0) ?? 2.0,
            jitterSeconds: Setting::float($value['jitter_seconds'] ?? null, 0.0) ?? 0.0,
            respectRetryAfter: (bool) ($value['respect_retry_after'] ?? true),
            shouldRetry: $value['should_retry'] ?? null,
            sleeper: $value['sleeper'] ?? null,
        );
    }

    /**
     * @return array<int, string>
     */
    private static function strings(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_scalar($entry)) {
                $out[] = (string) $entry;
            }
        }

        return $out;
    }

    /**
     * @return array<int, int>
     */
    private static function ints(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_numeric($entry)) {
                $out[] = (int) $entry;
            }
        }

        return $out;
    }
}
