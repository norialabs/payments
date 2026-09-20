<?php

namespace NoriaLabs\Payments;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory;
use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Support\Hooks;
use NoriaLabs\Payments\Support\Setting;

class PaymentsManager
{
    public function __construct(
        private readonly Factory $http,
        private readonly Repository $config,
        private readonly ?CacheFactory $cache = null,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function mpesa(
        array $overrides = [],
        ?AccessTokenProvider $tokenProvider = null,
        ?Hooks $hooks = null,
    ): MpesaClient {
        return MpesaClient::make(
            httpFactory: $this->http,
            config: $this->mergedConfig('mpesa', $overrides),
            tokenProvider: $tokenProvider,
            hooks: $hooks,
            cacheFactory: $this->cache,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function sasapay(
        array $overrides = [],
        ?AccessTokenProvider $tokenProvider = null,
        ?Hooks $hooks = null,
    ): SasaPayClient {
        return SasaPayClient::make(
            httpFactory: $this->http,
            config: $this->mergedConfig('sasapay', $overrides),
            tokenProvider: $tokenProvider,
            hooks: $hooks,
            cacheFactory: $this->cache,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function sasapayCallbackVerifier(array $overrides = []): SasaPayCallbackVerifier
    {
        return SasaPayCallbackVerifier::make($this->mergedConfig('sasapay', $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function kcbBuni(
        array $overrides = [],
        ?AccessTokenProvider $tokenProvider = null,
        ?Hooks $hooks = null,
    ): KcbBuniClient {
        return KcbBuniClient::make(
            httpFactory: $this->http,
            config: $this->mergedConfig('kcb_buni', $overrides),
            tokenProvider: $tokenProvider,
            hooks: $hooks,
            cacheFactory: $this->cache,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function kcbBuniIpnVerifier(array $overrides = []): KcbBuniIpnVerifier
    {
        return KcbBuniIpnVerifier::make($this->mergedConfig('kcb_buni', $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function paystack(
        array $overrides = [],
        ?AccessTokenProvider $tokenProvider = null,
        ?Hooks $hooks = null,
    ): PaystackClient {
        return PaystackClient::make(
            httpFactory: $this->http,
            config: $this->mergedConfig('paystack', $overrides),
            tokenProvider: $tokenProvider,
            hooks: $hooks,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function paystackWebhookVerifier(array $overrides = []): PaystackWebhookVerifier
    {
        return PaystackWebhookVerifier::make($this->mergedConfig('paystack', $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergedConfig(string $provider, array $overrides): array
    {
        $http = Setting::map($this->config->get('payments.http', []));
        $providerConfig = self::withoutNullValues(Setting::map($this->config->get("payments.{$provider}", [])));

        /** @var array<string, mixed> */
        return array_replace_recursive($http, $providerConfig, $overrides);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function withoutNullValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::withoutNullValues($value);

                continue;
            }

            if ($value === null) {
                unset($values[$key]);
            }
        }

        return $values;
    }
}
