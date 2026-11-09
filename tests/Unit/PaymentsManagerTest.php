<?php

use Illuminate\Support\Facades\Http;
use Noria\Payments\Contracts\AccessTokenProvider;
use Noria\Payments\Facades\Payments;
use Noria\Payments\KcbBuniClient;
use Noria\Payments\KcbBuniIpnVerifier;
use Noria\Payments\MpesaClient;
use Noria\Payments\PaymentsManager;
use Noria\Payments\PaystackClient;
use Noria\Payments\PaystackWebhookVerifier;
use Noria\Payments\SasaPayCallbackVerifier;
use Noria\Payments\SasaPayClient;

function managerTokenProvider(string $token = 'token'): AccessTokenProvider
{
    return new class($token) implements AccessTokenProvider
    {
        public function __construct(private readonly string $token) {}

        public function getAccessToken(bool $forceRefresh = false): string
        {
            return $forceRefresh ? $this->token.'-fresh' : $this->token;
        }
    };
}

it('builds provider clients and verifiers via the manager and the container', function (): void {
    config()->set('payments.mpesa.consumer_key', 'consumer');
    config()->set('payments.mpesa.consumer_secret', 'secret');
    config()->set('payments.sasapay.client_id', 'client');
    config()->set('payments.sasapay.client_secret', 'secret');
    config()->set('payments.kcb_buni.consumer_key', 'consumer');
    config()->set('payments.kcb_buni.consumer_secret', 'secret');
    config()->set('payments.paystack.secret_key', 'sk_test_secret');

    $manager = app(PaymentsManager::class);

    expect($manager->mpesa(tokenProvider: managerTokenProvider()))->toBeInstanceOf(MpesaClient::class)
        ->and($manager->sasapay(tokenProvider: managerTokenProvider()))->toBeInstanceOf(SasaPayClient::class)
        ->and($manager->sasapayCallbackVerifier([
            'callback_security' => ['verify_signature' => false],
        ]))->toBeInstanceOf(SasaPayCallbackVerifier::class)
        ->and($manager->kcbBuni(tokenProvider: managerTokenProvider()))->toBeInstanceOf(KcbBuniClient::class)
        ->and($manager->kcbBuniIpnVerifier([
            'ipn_security' => ['verify_signature' => false],
        ]))->toBeInstanceOf(KcbBuniIpnVerifier::class)
        ->and($manager->paystack(tokenProvider: managerTokenProvider()))->toBeInstanceOf(PaystackClient::class)
        ->and($manager->paystackWebhookVerifier([
            'webhook_security' => ['verify_signature' => false],
        ]))->toBeInstanceOf(PaystackWebhookVerifier::class)
        ->and(app(MpesaClient::class))->toBeInstanceOf(MpesaClient::class)
        ->and(app(SasaPayClient::class))->toBeInstanceOf(SasaPayClient::class)
        ->and(app(SasaPayCallbackVerifier::class))->toBeInstanceOf(SasaPayCallbackVerifier::class)
        ->and(app(KcbBuniClient::class))->toBeInstanceOf(KcbBuniClient::class)
        ->and(app(KcbBuniIpnVerifier::class))->toBeInstanceOf(KcbBuniIpnVerifier::class)
        ->and(app(PaystackClient::class))->toBeInstanceOf(PaystackClient::class)
        ->and(app(PaystackWebhookVerifier::class))->toBeInstanceOf(PaystackWebhookVerifier::class)
        ->and(Payments::sasapayCallbackVerifier([
            'callback_security' => ['verify_signature' => false],
        ]))->toBeInstanceOf(SasaPayCallbackVerifier::class)
        ->and(Payments::kcbBuniIpnVerifier([
            'ipn_security' => ['verify_signature' => false],
        ]))->toBeInstanceOf(KcbBuniIpnVerifier::class)
        ->and(Payments::paystackWebhookVerifier([
            'webhook_security' => ['verify_signature' => false],
        ]))->toBeInstanceOf(PaystackWebhookVerifier::class);
});

it('lets provider null config values inherit shared http defaults', function (): void {
    config()->set('cache.default', 'array');
    config()->set('cache.stores.array', ['driver' => 'array']);
    config()->set('cache.prefix', '');
    config()->set('payments.http.cache_store', 'array');
    config()->set('payments.http.cache_ttl_seconds', 600);
    config()->set('payments.mpesa.consumer_key', 'consumer');
    config()->set('payments.mpesa.consumer_secret', 'secret');
    config()->set('payments.mpesa.cache_store', null);
    config()->set('payments.mpesa.cache_ttl_seconds', null);

    Http::fake([
        'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
            'access_token' => 'shared-cache-token',
            'expires_in' => 3600,
        ], 200),
        'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
            'ResponseCode' => '0',
        ], 200),
    ]);

    $manager = app(PaymentsManager::class);

    $manager->mpesa()->stkPushQuery(['CheckoutRequestID' => 'first']);
    $manager->mpesa()->stkPushQuery(['CheckoutRequestID' => 'second']);

    $tokenRequests = collect(Http::recorded())
        ->filter(fn (array $record): bool => str_starts_with($record[0]->url(), 'https://sandbox.safaricom.co.ke/oauth/v1/generate'))
        ->count();

    expect($tokenRequests)->toBe(1);
});
