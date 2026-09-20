<?php

namespace NoriaLabs\Payments\Support;

use NoriaLabs\Payments\Exceptions\BusinessException;

class BusinessStatus
{
    public const KCB_BUNI = 'kcb_buni';

    public const MPESA = 'mpesa';

    public const SASAPAY = 'sasapay';

    public const PAYSTACK = 'paystack';

    public static function succeeded(string $provider, mixed $body): ?bool
    {
        if (! is_array($body)) {
            return null;
        }

        $body = Setting::map($body);

        return match ($provider) {
            self::KCB_BUNI => self::kcbBuni($body),
            self::MPESA => self::mpesa($body),
            self::SASAPAY => self::sasapay($body),
            self::PAYSTACK => self::paystack($body),
            default => null,
        };
    }

    /**
     * @throws BusinessException
     */
    public static function assert(string $provider, mixed $body, string $context): void
    {
        if (self::succeeded($provider, $body) !== false) {
            return;
        }

        $code = self::statusCode($provider, $body);
        $message = self::statusMessage($provider, $body)
            ?? 'The provider reported a business failure.';

        throw new BusinessException(
            $context.' failed: '.$message.($code === null ? '' : " (status {$code})"),
            $provider,
            $code,
            $body,
        );
    }

    public static function statusCode(string $provider, mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $keys = match ($provider) {
            self::KCB_BUNI => [['header', 'statusCode'], ['response', 'ResponseCode'], ['statusCode'], ['ResponseCode']],
            self::MPESA => [['errorCode'], ['ResponseCode'], ['ResultCode'], ['Body', 'stkCallback', 'ResultCode']],
            self::SASAPAY => [['statusCode'], ['ResponseCode'], ['status_code']],
            self::PAYSTACK => [['code']],
            default => [],
        };

        foreach ($keys as $path) {
            $value = self::dig(Setting::map($body), $path);

            if (is_scalar($value) && trim(Setting::string($value)) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    public static function statusMessage(string $provider, mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $keys = match ($provider) {
            self::KCB_BUNI => [
                ['header', 'statusMessage'], ['header', 'statusDescription'],
                ['response', 'ResponseDescription'], ['response', 'CustomerMessage'],
                ['statusMessage'], ['message'],
            ],
            self::MPESA => [
                ['errorMessage'], ['ResponseDescription'], ['ResultDesc'],
                ['Body', 'stkCallback', 'ResultDesc'], ['message'],
            ],
            self::SASAPAY => [['detail'], ['message'], ['statusMessage'], ['ResponseDescription']],
            self::PAYSTACK => [['message']],
            default => [],
        };

        $body = Setting::map($body);

        foreach ($keys as $path) {
            $value = self::dig($body, $path);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function kcbBuni(array $body): ?bool
    {
        $markers = [
            self::dig($body, ['header', 'statusCode']),
            self::dig($body, ['response', 'ResponseCode']) ?? self::dig($body, ['ResponseCode']),
            self::dig($body, ['statusCode']),
        ];

        $found = false;

        foreach ($markers as $marker) {
            if (! is_scalar($marker) || trim((string) $marker) === '') {
                continue;
            }

            $found = true;

            if (! self::isZero($marker)) {
                return false;
            }
        }

        return $found ? true : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function mpesa(array $body): ?bool
    {
        $errorCode = self::dig($body, ['errorCode']);

        if (is_scalar($errorCode) && trim((string) $errorCode) !== '') {
            return false;
        }

        foreach ([['ResponseCode'], ['ResultCode'], ['Body', 'stkCallback', 'ResultCode']] as $path) {
            $value = self::dig($body, $path);

            if (is_scalar($value) && trim(Setting::string($value)) !== '') {
                return self::isZero($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function sasapay(array $body): ?bool
    {
        $status = self::booleanish($body['status'] ?? null);

        if ($status !== null) {
            return $status;
        }

        foreach ([['statusCode'], ['ResponseCode']] as $path) {
            $value = self::dig($body, $path);

            if (is_scalar($value) && trim(Setting::string($value)) !== '') {
                return self::isZero($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function paystack(array $body): ?bool
    {
        return self::booleanish($body['status'] ?? null);
    }

    private static function booleanish(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) && in_array(strtolower(trim($value)), ['true', 'false'], true)) {
            return strtolower(trim($value)) === 'true';
        }

        return null;
    }

    private static function isZero(mixed $value): bool
    {
        $value = trim(Setting::string($value));

        return $value === '0' || (is_numeric($value) && (float) $value === 0.0);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int, string>  $path
     */
    private static function dig(array $body, array $path): mixed
    {
        $current = $body;

        foreach ($path as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
