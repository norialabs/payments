<?php

namespace Noria\Payments\Providers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Noria\Payments\Http\Middleware\VerifyKcbBuniIpn;
use Noria\Payments\Http\Middleware\VerifyPaystackWebhook;
use Noria\Payments\Http\Middleware\VerifySasaPayCallback;
use Noria\Payments\KcbBuniClient;
use Noria\Payments\KcbBuniIpnVerifier;
use Noria\Payments\MpesaClient;
use Noria\Payments\PaymentsManager;
use Noria\Payments\PaystackClient;
use Noria\Payments\PaystackWebhookVerifier;
use Noria\Payments\SasaPayCallbackVerifier;
use Noria\Payments\SasaPayClient;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/payments.php', 'payments');

        $this->app->singleton(PaymentsManager::class, function (Application $app): PaymentsManager {
            return new PaymentsManager(
                http: $app->make(Factory::class),
                config: $app->make('config'),
                cache: $app->bound(CacheFactory::class) ? $app->make(CacheFactory::class) : null,
            );
        });

        $this->app->bind(MpesaClient::class, function (Application $app): MpesaClient {
            return $app->make(PaymentsManager::class)->mpesa();
        });

        $this->app->bind(SasaPayClient::class, function (Application $app): SasaPayClient {
            return $app->make(PaymentsManager::class)->sasapay();
        });

        $this->app->bind(SasaPayCallbackVerifier::class, function (Application $app): SasaPayCallbackVerifier {
            return $app->make(PaymentsManager::class)->sasapayCallbackVerifier();
        });

        $this->app->bind(KcbBuniClient::class, function (Application $app): KcbBuniClient {
            return $app->make(PaymentsManager::class)->kcbBuni();
        });

        $this->app->bind(KcbBuniIpnVerifier::class, function (Application $app): KcbBuniIpnVerifier {
            return $app->make(PaymentsManager::class)->kcbBuniIpnVerifier();
        });

        $this->app->bind(PaystackClient::class, function (Application $app): PaystackClient {
            return $app->make(PaymentsManager::class)->paystack();
        });

        $this->app->bind(PaystackWebhookVerifier::class, function (Application $app): PaystackWebhookVerifier {
            return $app->make(PaymentsManager::class)->paystackWebhookVerifier();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/payments.php' => config_path('payments.php'),
        ], 'payments-config');

        $this->registerMiddlewareAliases();
    }

    private function registerMiddlewareAliases(): void
    {
        if (! $this->app->bound('router')) {
            return;
        }

        $router = $this->app->make('router');

        $router->aliasMiddleware('kcb-buni.ipn', VerifyKcbBuniIpn::class);
        $router->aliasMiddleware('sasapay.callback', VerifySasaPayCallback::class);
        $router->aliasMiddleware('paystack.webhook', VerifyPaystackWebhook::class);
    }
}
