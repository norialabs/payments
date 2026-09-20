<?php

namespace NoriaLabs\Payments;

use Illuminate\Http\Request;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\Support\Setting;
use Symfony\Component\HttpFoundation\IpUtils;

class KcbBuniIpnVerifier
{
    /**
     * @param  array<int, string>  $trustedIps
     */
    public function __construct(
        private readonly ?string $publicKey,
        private readonly array $trustedIps = [],
        private readonly bool $enforceIpWhitelist = false,
        private readonly bool $verifySignature = true,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(array $config = []): self
    {
        $ipnConfig = (array) ($config['ipn_security'] ?? []);

        return new self(
            publicKey: self::normalizePublicKey($ipnConfig['public_key'] ?? null),
            trustedIps: self::normalizeTrustedIps($ipnConfig['trusted_ips'] ?? []),
            enforceIpWhitelist: self::boolean($ipnConfig['enforce_ip_whitelist'] ?? false),
            verifySignature: self::boolean($ipnConfig['verify_signature'] ?? true),
        );
    }

    public function verifyRequest(
        Request $request,
        ?bool $enforceIpWhitelist = null,
        ?bool $verifySignature = null,
    ): bool {
        return $this->verify(
            payload: $request->getContent(),
            signature: $request->headers->get('Signature'),
            ip: $request->ip(),
            enforceIpWhitelist: $enforceIpWhitelist,
            verifySignature: $verifySignature,
        );
    }

    public function verify(
        string $payload,
        ?string $signature,
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

        $decodedSignature = base64_decode(trim($signature), true);

        if ($decodedSignature === false) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($this->requirePublicKey());

        if ($publicKey === false) {
            throw new ConfigurationException(
                'KCB Buni IPN public key is not a valid OpenSSL public key.'
            );
        }

        $result = openssl_verify(
            data: $payload,
            signature: $decodedSignature,
            public_key: $publicKey,
            algorithm: OPENSSL_ALGO_SHA256,
        );

        return $result === 1;
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

    private function requirePublicKey(): string
    {
        if ($this->publicKey === null || $this->publicKey === '') {
            throw new ConfigurationException(
                'KCB Buni IPN verification requires a public key. Configure payments.kcb_buni.ipn_security.public_key.'
            );
        }

        return $this->publicKey;
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
            is_array($value) ? $value : [],
        )));
    }

    private static function normalizePublicKey(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace('\\n', "\n", Setting::string($value)));

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
