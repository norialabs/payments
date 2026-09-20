<?php

namespace NoriaLabs\Payments;

use Illuminate\Http\Request;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\Support\Setting;
use Symfony\Component\HttpFoundation\IpUtils;

class PaystackWebhookVerifier
{
    public const TRUSTED_WEBHOOK_IPS = [
        '52.31.139.75',
        '52.49.173.169',
        '52.214.14.220',
    ];

    /**
     * @param  array<int, string>  $trustedIps
     */
    public function __construct(
        private readonly ?string $secretKey,
        private readonly array $trustedIps = self::TRUSTED_WEBHOOK_IPS,
        private readonly bool $enforceIpWhitelist = false,
        private readonly bool $verifySignature = true,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(array $config = []): self
    {
        $webhookConfig = (array) ($config['webhook_security'] ?? []);

        return new self(
            secretKey: self::nullableString($webhookConfig['secret_key'] ?? $config['secret_key'] ?? null),
            trustedIps: self::normalizeTrustedIps($webhookConfig['trusted_ips'] ?? self::TRUSTED_WEBHOOK_IPS),
            enforceIpWhitelist: self::boolean($webhookConfig['enforce_ip_whitelist'] ?? false),
            verifySignature: self::boolean($webhookConfig['verify_signature'] ?? true),
        );
    }

    public function verifyRequest(
        Request $request,
        ?bool $enforceIpWhitelist = null,
        ?bool $verifySignature = null,
    ): bool {
        return $this->verify(
            payload: $request->getContent(),
            signature: $request->headers->get('x-paystack-signature'),
            ip: $request->ip(),
            enforceIpWhitelist: $enforceIpWhitelist,
            verifySignature: $verifySignature,
        );
    }

    public function verify(
        string $payload,
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

        if ($signature === null || trim($signature) === '') {
            return false;
        }

        return hash_equals(
            strtolower($this->expectedSignature($payload)),
            strtolower(trim($signature)),
        );
    }

    public function expectedSignature(string $payload, ?string $secretKey = null): string
    {
        return hash_hmac('sha512', $payload, $secretKey ?? $this->requireSecretKey());
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

    private function requireSecretKey(): string
    {
        if ($this->secretKey === null || $this->secretKey === '') {
            throw new ConfigurationException(
                'Paystack webhook verification requires a secret key. Configure payments.paystack.webhook_security.secret_key or payments.paystack.secret_key.'
            );
        }

        return $this->secretKey;
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
            is_array($value) ? $value : self::TRUSTED_WEBHOOK_IPS,
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
