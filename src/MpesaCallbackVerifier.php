<?php

namespace Noria\Payments;

use Illuminate\Http\Request;
use Noria\Payments\Support\Setting;
use Symfony\Component\HttpFoundation\IpUtils;

class MpesaCallbackVerifier
{
    public const TRUSTED_CALLBACK_IPS = [
        '196.201.214.200',
        '196.201.214.206',
        '196.201.213.114',
        '196.201.214.207',
        '196.201.214.208',
        '196.201.213.44',
        '196.201.212.127',
        '196.201.212.138',
        '196.201.212.129',
        '196.201.212.136',
        '196.201.212.74',
        '196.201.212.69',
    ];

    /**
     * @param  array<int, string>  $trustedIps
     */
    public function __construct(
        private readonly array $trustedIps = self::TRUSTED_CALLBACK_IPS,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(array $config = []): self
    {
        $callbackConfig = (array) ($config['callback_security'] ?? []);

        return new self(self::normalizeTrustedIps($callbackConfig['trusted_ips'] ?? null));
    }

    public function verifyRequest(Request $request): bool
    {
        return $this->isTrustedIp($request->ip());
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

    /**
     * @return array<int, string>
     */
    private static function normalizeTrustedIps(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        $trustedIps = array_values(array_filter(array_map(
            static fn (mixed $ip): string => trim(Setting::string($ip)),
            is_array($value) ? $value : [],
        )));

        return $trustedIps === [] ? self::TRUSTED_CALLBACK_IPS : $trustedIps;
    }
}
