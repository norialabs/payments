<?php

namespace NoriaLabs\Payments;

use Illuminate\Http\Request;
use InvalidArgumentException;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\Support\Setting;
use Symfony\Component\HttpFoundation\IpUtils;

class SasaPayCallbackVerifier
{
    public const TRUSTED_CALLBACK_IPS = [
        '47.129.43.141',
        '13.229.247.179',
        '13.215.155.141',
        '13.214.60.231',
        '54.169.74.198',
        '18.142.226.87',
        '47.129.243.116',
        '13.250.110.3',
        '155.12.30.40',
        '155.12.30.58',
    ];

    private const FIELD_ALIASES = [
        'sasapay_transaction_code' => [
            'sasapay_transaction_code',
            'TransactionCode',
            'TransID',
            'SasaPayTransactionCode',
        ],
        'sasapay_transaction_id' => [
            'SasaPayTransactionID',
        ],
        'third_party_transaction_id' => [
            'ThirdPartyTransID',
            'ThirdPartyTransactionCode',
            'third_party_transaction_code',
        ],
        'merchant_code' => [
            'merchant_code',
            'merchantCode',
            'MerchantCode',
            'BusinessShortCode',
        ],
        'account_number' => [
            'account_number',
            'accountNumber',
            'AccountNumber',
            'CustomerMobile',
            'MSISDN',
            'RecipientAccountNumber',
            'BeneficiaryAccountNumber',
            'SenderAccountNumber',
            'ContactNumber',
            'DestinationAccountNumber',
        ],
        'checkout_request_id' => [
            'CheckoutRequestID',
            'CheckoutRequestId',
            'checkoutRequestId',
        ],
        'payment_reference' => [
            'payment_reference',
            'BillRefNumber',
            'InvoiceNumber',
            'MerchantReference',
            'merchantReference',
            'MerchantTransactionReference',
            'TransactionReference',
            'transactionReference',
            'PaymentRequestID',
            'MerchantRequestID',
            'bulk_payment_reference',
        ],
        'amount' => [
            'amount',
            'TransactionAmount',
            'TransAmount',
            'AmountPaid',
            'PaidAmount',
            'Amount',
            'RequestedAmount',
        ],
    ];

    private const SIGNATURE_PAYLOAD_KEYS = [
        'sasapay_signature',
    ];

    /**
     * @param  array<int, string>  $trustedIps
     */
    public function __construct(
        private readonly ?string $secretKey,
        private readonly array $trustedIps = self::TRUSTED_CALLBACK_IPS,
        private readonly bool $enforceIpWhitelist = false,
        private readonly bool $verifySignature = true,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(array $config = []): self
    {
        $callbackConfig = (array) ($config['callback_security'] ?? []);

        return new self(
            secretKey: self::nullableString($callbackConfig['secret_key'] ?? $config['client_id'] ?? null),
            trustedIps: self::normalizeTrustedIps($callbackConfig['trusted_ips'] ?? self::TRUSTED_CALLBACK_IPS),
            enforceIpWhitelist: self::boolean($callbackConfig['enforce_ip_whitelist'] ?? false),
            verifySignature: self::boolean($callbackConfig['verify_signature'] ?? true),
        );
    }

    public function verifyRequest(
        Request $request,
        ?bool $enforceIpWhitelist = null,
        ?bool $verifySignature = null,
    ): bool {
        $payload = Setting::map($request->all());
        $signature = $this->signatureFromPayload($payload);

        return $this->verify(
            payload: $payload,
            signature: $signature,
            ip: $request->ip(),
            enforceIpWhitelist: $enforceIpWhitelist,
            verifySignature: $verifySignature,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(
        array $payload,
        ?string $signature = null,
        ?string $ip = null,
        ?bool $enforceIpWhitelist = null,
        ?bool $verifySignature = null,
    ): bool {
        if (($enforceIpWhitelist ?? $this->enforceIpWhitelist) && ! $this->isTrustedIp($ip)) {
            return false;
        }

        if (! ($verifySignature ?? $this->verifySignature)) {
            return true;
        }

        $signature ??= $this->signatureFromPayload($payload);

        if ($signature === null || trim($signature) === '') {
            return false;
        }

        try {
            $expectedSignature = $this->expectedSignature($payload);
        } catch (InvalidArgumentException) {
            return false;
        }

        return hash_equals(
            strtolower($expectedSignature),
            strtolower(trim($signature)),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function expectedSignature(array $payload, ?string $secretKey = null): string
    {
        return hash_hmac('sha512', $this->message($payload), $secretKey ?? $this->requireSecretKey());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function message(array $payload): string
    {
        return self::messageFromValues(
            sasapayTransactionCode: $this->payloadValue($payload, 'sasapay_transaction_code'),
            merchantCode: $this->payloadValue($payload, 'merchant_code'),
            accountNumber: $this->payloadValue($payload, 'account_number'),
            paymentReference: $this->payloadValue($payload, 'payment_reference'),
            amount: $this->payloadValue($payload, 'amount'),
        );
    }

    public static function messageFromValues(
        string|int|float $sasapayTransactionCode,
        string|int $merchantCode,
        string|int $accountNumber,
        string|int $paymentReference,
        string|int|float $amount,
    ): string {
        return implode('-', [
            self::stringValue($sasapayTransactionCode, 'sasapay_transaction_code'),
            self::stringValue($merchantCode, 'merchant_code'),
            self::stringValue($accountNumber, 'account_number'),
            self::stringValue($paymentReference, 'payment_reference'),
            self::stringValue($amount, 'amount'),
        ]);
    }

    /**
     * @return array<string, array<int, string>>|array<int, string>
     */
    public static function fieldAliases(?string $field = null): array
    {
        if ($field === null) {
            return self::FIELD_ALIASES;
        }

        if (! array_key_exists($field, self::FIELD_ALIASES)) {
            throw new InvalidArgumentException("Unknown SasaPay callback field [{$field}].");
        }

        return self::FIELD_ALIASES[$field];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function callbackValue(array $payload, string $field): ?string
    {
        if (! array_key_exists($field, self::FIELD_ALIASES)) {
            throw new InvalidArgumentException("Unknown SasaPay callback field [{$field}].");
        }

        foreach (self::FIELD_ALIASES[$field] as $key) {
            if (array_key_exists($key, $payload)) {
                return self::stringValue($payload[$key], $field);
            }
        }

        return null;
    }

    public function isTrustedIp(?string $ip): bool
    {
        if ($ip === null || trim($ip) === '') {
            return false;
        }

        foreach ($this->trustedIps as $trustedIp) {
            if (IpUtils::checkIp($ip, $trustedIp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function signatureFromPayload(array $payload): ?string
    {
        foreach (self::SIGNATURE_PAYLOAD_KEYS as $key) {
            if (array_key_exists($key, $payload) && is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function trustedIps(): array
    {
        return $this->trustedIps;
    }

    public function enforcesIpWhitelist(): bool
    {
        return $this->enforceIpWhitelist;
    }

    public function verifiesSignature(): bool
    {
        return $this->verifySignature;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadValue(array $payload, string $field): string
    {
        $value = $this->callbackValue($payload, $field);

        return $value ?? throw new InvalidArgumentException("Missing SasaPay callback field [{$field}].");
    }

    private function requireSecretKey(): string
    {
        if ($this->secretKey === null || $this->secretKey === '') {
            throw new ConfigurationException(
                'SasaPay callback verification requires a secret key. Configure payments.sasapay.callback_security.secret_key or sasapay.client_id.'
            );
        }

        return $this->secretKey;
    }

    private static function stringValue(mixed $value, string $field): string
    {
        if (! is_scalar($value)) {
            throw new InvalidArgumentException("SasaPay callback field [{$field}] must be scalar.");
        }

        $value = trim(Setting::string($value));

        if ($value === '') {
            throw new InvalidArgumentException("SasaPay callback field [{$field}] cannot be empty.");
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeTrustedIps(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $ip): string => trim(Setting::string($ip)),
            is_array($value) ? $value : self::TRUSTED_CALLBACK_IPS,
        )));
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(Setting::string($value));

        return $value === '' ? null : $value;
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
