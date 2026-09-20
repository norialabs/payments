<?php

namespace NoriaLabs\Payments\Support;

class RequestOptions
{
    /**
     * @param  array<string, string>  $headers
     * @param  bool|null  $validate  null inherits the client setting
     * @param  bool|null  $throwOnBusinessError  null inherits the client setting
     */
    public function __construct(
        public readonly array $headers = [],
        public readonly ?float $timeoutSeconds = null,
        public readonly RetryPolicy|false|null $retry = null,
        public readonly ?string $accessToken = null,
        public readonly bool $forceTokenRefresh = false,
        public readonly ?string $amountNormalization = null,
        public readonly ?bool $validate = null,
        public readonly ?bool $throwOnBusinessError = null,
    ) {}

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array|self|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null) {
            return new self;
        }

        return new self(
            headers: Setting::stringMap($value['headers'] ?? null),
            timeoutSeconds: Setting::float($value['timeout_seconds'] ?? null),
            retry: array_key_exists('retry', $value) ? (RetryPolicy::fromArray($value['retry']) ?? false) : null,
            accessToken: isset($value['access_token']) ? Setting::string($value['access_token']) : null,
            forceTokenRefresh: (bool) ($value['force_token_refresh'] ?? false),
            amountNormalization: isset($value['amount_normalization']) || isset($value['amountNormalization'])
                ? Setting::string($value['amount_normalization'] ?? $value['amountNormalization'])
                : null,
            validate: self::nullableBoolean($value['validate'] ?? null),
            throwOnBusinessError: self::nullableBoolean(
                $value['throw_on_business_error'] ?? $value['throwOnBusinessError'] ?? null
            ),
        );
    }

    private static function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
