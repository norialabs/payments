<?php

use Illuminate\Support\Facades\Http;
use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Exceptions\BusinessException;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\SasaPayClient;
use NoriaLabs\Payments\Support\HttpTransport;
use NoriaLabs\Payments\Support\RequestOptions;
use NoriaLabs\Payments\Support\RetryPolicy;

it('defaults to the live sasapay hosts in the production environment', function (): void {
    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'production',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    Http::fake([
        'https://api.sasapay.app/*' => Http::response(['status' => true], 200),
    ]);

    $client->channelCodes(new RequestOptions(accessToken: 'token'));
    $client->waasChannelCodes(new RequestOptions(accessToken: 'token'));

    $urls = collect(Http::recorded())->map(fn (array $record): string => strtok($record[0]->url(), '?'))->all();

    expect($urls)->toBe([
        'https://api.sasapay.app/api/v1/payments/channel-codes/',
        'https://api.sasapay.app/api/v2/waas/channel-codes/',
    ]);
});

it('rejects an unknown sasapay environment without an explicit base url', function (): void {
    SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'staging',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);
})->throws(ConfigurationException::class, 'Unknown SasaPay environment [staging]');

it('requests token and sends c2b payment', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/auth/token/*' => Http::response([
            'status' => true,
            'access_token' => 'sasapay-token',
            'expires_in' => 3600,
        ], 200),
        'https://sandbox.sasapay.app/api/v1/payments/request-payment/' => Http::response([
            'status' => true,
            'ResponseCode' => '0',
        ], 200),
    ]);

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    $response = $client->requestPayment([
        'MerchantCode' => '600980',
        'NetworkCode' => '63902',
        'Currency' => 'KES',
        'Amount' => 1,
        'PhoneNumber' => '254700000080',
        'AccountReference' => '12345678',
        'TransactionDesc' => 'Request Payment',
        'CallBackURL' => 'https://example.com/callback',
    ]);

    expect($response['ResponseCode'])->toBe('0');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://sandbox.sasapay.app/api/v1/auth/token/?grant_type=client_credentials'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('client-id:client-secret'));
    });

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://sandbox.sasapay.app/api/v1/payments/request-payment/'
            && $request['Amount'] === '1'
            && $request->hasHeader('Authorization', 'Bearer sasapay-token');
    });
});

it('normalizes local Kenyan numbers in c2b payment requests', function (string $local, string $international): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/payments/request-payment/' => Http::response([
            'status' => true,
            'ResponseCode' => '0',
        ], 200),
    ]);

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    });

    $client->requestPayment([
        'Amount' => 1,
        'PhoneNumber' => $local,
    ]);

    Http::assertSent(fn ($request): bool => $request['PhoneNumber'] === $international);
})->with([
    '07 mobile number' => ['0704444721', '254704444721'],
    '01 mobile number' => ['0110123456', '254110123456'],
]);

it('uses the documented production token endpoint', function (): void {
    Http::fake([
        'https://api.sasapay.app/api/v1/auth/token/*' => Http::response([
            'access_token' => 'production-token',
            'expires_in' => 3600,
        ], 200),
    ]);

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'production',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    expect($client->getAccessToken())->toBe('production-token');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.sasapay.app/api/v1/auth/token/?grant_type=client_credentials');
});

it('supports explicit sasapay token url overrides', function (): void {
    Http::fake([
        'https://auth.example.com/v1/token*' => Http::response([
            'access_token' => 'v1-token',
            'expires_in' => 3600,
        ], 200),
        'https://auth.example.com/waas/token*' => Http::response([
            'access_token' => 'waas-token',
            'expires_in' => 3600,
        ], 200),
    ]);

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'token_url' => 'https://auth.example.com/v1/token',
        'waas_token_url' => 'https://auth.example.com/waas/token',
    ]);

    expect($client->getAccessToken())->toBe('v1-token')
        ->and($client->getWaasAccessToken())->toBe('waas-token');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.example.com/v1/token?grant_type=client_credentials');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.example.com/waas/token?grant_type=client_credentials');
});

it('keeps amount stringification by default and can preserve raw numeric amounts', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/payments/request-payment/' => Http::response(['status' => true], 200),
        'https://sandbox.sasapay.app/api/v1/payments/lipa-fare/' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $defaultClient = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], $tokenProvider);

    $defaultClient->requestPayment(['Amount' => 100]);

    $rawClient = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'amount_normalization' => 'none',
    ], $tokenProvider);

    $rawClient->requestPayment(['Amount' => 100]);
    $rawClient->lipaFare(['amount' => 100.50]);

    $requests = collect(Http::recorded())
        ->filter(fn (array $record): bool => $record[0]->url() !== 'https://sandbox.sasapay.app/api/v1/auth/token/?grant_type=client_credentials')
        ->values();

    expect($requests[0][0]->data()['Amount'])->toBe('100')
        ->and($requests[1][0]->data()['Amount'])->toBe(100)
        ->and($requests[2][0]->data()['amount'])->toBe(100.50);
});

it('can disable amount normalization per request', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/payments/request-payment/' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], $tokenProvider);

    $client->requestPayment(['Amount' => 100], new RequestOptions(amountNormalization: 'none'));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v1/payments/request-payment/'
        && $request->data()['Amount'] === 100);
});

it('supports per request access token overrides', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/payments/process-payment/' => Http::response([
            'status' => true,
            'detail' => 'Transaction is being processed',
        ], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            throw new RuntimeException('token provider should not be called');
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], $tokenProvider);

    $client->processPayment([
        'MerchantCode' => '600980',
        'CheckoutRequestID' => 'checkout-123',
        'VerificationCode' => '123456',
    ], new RequestOptions(
        headers: ['X-Request-Id' => 'abc-123'],
        accessToken: 'manual-token',
    ));

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer manual-token')
            && $request->hasHeader('X-Request-Id', 'abc-123');
    });
});

it('retries post requests only when explicitly enabled', function (): void {
    Http::fakeSequence()
        ->push(['access_token' => 'sasapay-token', 'expires_in' => 3600], 200)
        ->push(['detail' => 'temporary failure'], 500)
        ->push(['status' => true, 'ResponseCode' => '0'], 200);

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    $response = $client->requestPayment([
        'MerchantCode' => '600980',
        'NetworkCode' => '63902',
        'Currency' => 'KES',
        'Amount' => '1.00',
        'PhoneNumber' => '254700000080',
        'AccountReference' => '12345678',
        'TransactionDesc' => 'Request Payment',
        'CallBackURL' => 'https://example.com/callback',
    ], [
        'retry' => new RetryPolicy(
            maxAttempts: 2,
            retryMethods: ['POST'],
            retryOnStatuses: [500],
        ),
    ]);

    expect($response['ResponseCode'])->toBe('0');
});

it('maps documented non waas sasapay endpoints', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/*' => Http::response([
            'status' => true,
            'ResponseCode' => '0',
        ], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], $tokenProvider);

    $client->cardPayment(['Amount' => 10]);
    $client->preApprovedPayment(['Amount' => 10]);
    $client->remittancePayment(['Amount' => 10]);
    $client->accountValidation(['merchant_code' => '600980']);
    $client->internalFundMovement(['merchantCode' => '600980', 'amount' => 10]);
    $client->transactionStatus(['MerchantCode' => '600980']);
    $client->merchantBalance('600980');
    $client->verifyTransaction(['merchantCode' => '600980']);
    $client->businessToBeneficiary(['Amount' => 10]);
    $client->registerIpnUrl(['MerchantCode' => '600980']);
    $client->lipaFare(['Amount' => 10]);
    $client->transactions(['merchant_code' => '600980']);
    $client->channelCodes();
    $client->utilityPayment(['merchantCode' => '600980']);
    $client->utilityBillQuery(['merchantCode' => '600980']);
    $client->bulkPayment(['merchant_code' => '600980']);
    $client->bulkPaymentStatus(['merchant_code' => '600980']);
    $client->dealerBusinessTypes();
    $client->dealerCountries();
    $client->dealerSubCounties(47);
    $client->dealerIndustries();
    $client->availableBillNumber();
    $client->merchantOnboarding(['business_type_id' => 1]);

    $requests = collect(Http::recorded())->map(fn (array $record): array => [
        'method' => $record[0]->method(),
        'url' => $record[0]->url(),
    ]);

    expect($requests->pluck('url')->all())->toContain(
        'https://sandbox.sasapay.app/api/v1/payments/card-payments/',
        'https://sandbox.sasapay.app/api/v1/payments/approved/',
        'https://sandbox.sasapay.app/api/v1/remittances/remittance-payments/',
        'https://sandbox.sasapay.app/api/v1/accounts/account-validation/',
        'https://sandbox.sasapay.app/api/v1/transactions/fund-movement/',
        'https://sandbox.sasapay.app/api/v1/transactions/status/',
        'https://sandbox.sasapay.app/api/v1/payments/check-balance/?MerchantCode=600980',
        'https://sandbox.sasapay.app/api/v1/transactions/verify/',
        'https://sandbox.sasapay.app/api/v1/payments/b2c/beneficiary/',
        'https://sandbox.sasapay.app/api/v1/payments/register-ipn-url/',
        'https://sandbox.sasapay.app/api/v1/payments/lipa-fare/',
        'https://sandbox.sasapay.app/api/v1/transactions/?merchant_code=600980',
        'https://sandbox.sasapay.app/api/v1/payments/channel-codes/',
        'https://sandbox.sasapay.app/api/v1/utilities/',
        'https://sandbox.sasapay.app/api/v1/utilities/bill-query',
        'https://sandbox.sasapay.app/api/v1/payments/bulk-payments/',
        'https://sandbox.sasapay.app/api/v1/payments/bulk-payments/status/',
        'https://sandbox.sasapay.app/api/v1/accounts/business-types/',
        'https://sandbox.sasapay.app/api/v1/accounts/countries/',
        'https://sandbox.sasapay.app/api/v1/accounts/sub-counties/?county_id=47',
        'https://sandbox.sasapay.app/api/v1/accounts/industries/',
        'https://sandbox.sasapay.app/api/v1/accounts/available-bill-number/',
        'https://sandbox.sasapay.app/api/v1/accounts/merchant-onboarding/',
    );

    expect($requests->where('method', 'GET')->pluck('url')->all())->toContain(
        'https://sandbox.sasapay.app/api/v1/payments/check-balance/?MerchantCode=600980',
        'https://sandbox.sasapay.app/api/v1/transactions/?merchant_code=600980',
        'https://sandbox.sasapay.app/api/v1/accounts/business-types/',
    );

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://sandbox.sasapay.app/api/v1/payments/card-payments/'
            && $request['Amount'] === '10';
    });
});

it('exposes exact sasapay status endpoint aliases', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/*' => Http::response([
            'status' => true,
            'ResponseCode' => '0',
        ], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], $tokenProvider);

    $client->transactionStatus(['MerchantCode' => '600980', 'MerchantTransactionReference' => 'merchant-reference']);
    $client->transactionStatusQuery(['MerchantCode' => '600980', 'CheckoutRequestId' => 'checkout-query']);
    $client->transactionStatusExact(['MerchantCode' => '600980', 'CheckoutRequestId' => 'checkout-exact']);
    $client->requestPaymentStatus(['MerchantCode' => '600980', 'CheckoutRequestID' => 'checkout-payment']);

    $requests = collect(Http::recorded())->map(fn (array $record): array => [
        'url' => $record[0]->url(),
        'data' => $record[0]->data(),
    ]);

    expect($requests->pluck('url')->all())->toContain(
        'https://sandbox.sasapay.app/api/v1/transactions/status-query/',
        'https://sandbox.sasapay.app/api/v1/transactions/status/',
        'https://sandbox.sasapay.app/api/v1/payments/request-payment/status/',
    );

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v1/transactions/status/'
        && $request->data() === ['MerchantCode' => '600980', 'MerchantTransactionReference' => 'merchant-reference']);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v1/payments/request-payment/status/'
        && $request->data() === ['MerchantCode' => '600980', 'CheckoutRequestID' => 'checkout-payment']);
});

it('applies configured sasapay payment defaults without overwriting explicit values', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/payments/b2c/' => Http::response(['status' => true], 200),
        'https://sandbox.sasapay.app/api/v2/waas/payments/request-payment/' => Http::response(['status' => true], 200),
        'https://sandbox.sasapay.app/api/v2/waas/business-onboarding/' => Http::response(['status' => true], 200),
        'https://sandbox.sasapay.app/api/v2/waas/business-onboarding/confirmation/' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'payment_defaults' => [
            'MerchantCode' => '600980',
            'Currency' => 'KES',
            'CallBackURL' => 'https://example.com/core-callback',
        ],
        'waas_payment_defaults' => [
            'merchantCode' => '700980',
            'currencyCode' => 'KES',
            'callbackUrl' => 'https://example.com/waas-callback',
        ],
    ], $tokenProvider);

    $client->b2cPayment([
        'Amount' => 10,
        'Currency' => 'USD',
    ]);
    $client->waasRequestPayment([
        'amount' => 20,
        'callbackUrl' => 'https://example.com/override',
    ]);
    $client->waasBusinessOnboarding([
        'businessName' => 'Noria Labs',
    ]);
    $client->waasConfirmBusinessOnboarding([
        'requestId' => 'REQ-123',
        'otp' => '123456',
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v1/payments/b2c/'
        && $request->data()['MerchantCode'] === '600980'
        && $request->data()['Currency'] === 'USD'
        && $request->data()['CallBackURL'] === 'https://example.com/core-callback');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/payments/request-payment/'
        && $request->data()['merchantCode'] === '700980'
        && $request->data()['currencyCode'] === 'KES'
        && $request->data()['callbackUrl'] === 'https://example.com/override');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/business-onboarding/'
        && $request->data()['merchantCode'] === '700980'
        && $request->data()['callbackUrl'] === 'https://example.com/waas-callback'
        && ! array_key_exists('currencyCode', $request->data()));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/business-onboarding/confirmation/'
        && $request->data()['merchantCode'] === '700980'
        && ! array_key_exists('currencyCode', $request->data())
        && ! array_key_exists('callbackUrl', $request->data()));
});

it('derives both token urls from their own base url', function (): void {
    // https://developer.sasapay.app/docs/apis/authentication
    // https://developer.sasapay.app/docs/apis/waas/authentication
    // Each surface authenticates at {base_url}/auth/token/, GET, HTTP Basic, with
    // grant_type in the query string. Rebuilding from scheme://host alone would drop
    // the /api/v1 and /api/v2/waas prefixes and point both at a path SasaPay does not serve.
    $resolve = (new ReflectionClass(SasaPayClient::class))->getMethod('resolveTokenUrl');

    expect($resolve->invoke(null, [], SasaPayClient::SANDBOX_BASE_URL, 'token_url'))
        ->toBe('https://sandbox.sasapay.app/api/v1/auth/token/')
        ->and($resolve->invoke(null, [], SasaPayClient::WAAS_SANDBOX_BASE_URL, 'waas_token_url'))
        ->toBe('https://sandbox.sasapay.app/api/v2/waas/auth/token/')
        ->and($resolve->invoke(null, [], SasaPayClient::PRODUCTION_BASE_URL, 'token_url'))
        ->toBe('https://api.sasapay.app/api/v1/auth/token/')
        ->and($resolve->invoke(null, [], SasaPayClient::WAAS_PRODUCTION_BASE_URL, 'waas_token_url'))
        ->toBe('https://api.sasapay.app/api/v2/waas/auth/token/');
});

it('does not reuse a configured v1 token url for waas', function (): void {
    // They are different endpoints; sharing one authenticates against the wrong surface.
    $resolve = (new ReflectionClass(SasaPayClient::class))->getMethod('resolveTokenUrl');

    $config = ['token_url' => 'https://custom.example.test/api/v1/auth/token/'];

    expect($resolve->invoke(null, $config, SasaPayClient::WAAS_SANDBOX_BASE_URL, 'waas_token_url'))
        ->toBe('https://sandbox.sasapay.app/api/v2/waas/auth/token/')
        ->and($resolve->invoke(null, $config, SasaPayClient::SANDBOX_BASE_URL, 'token_url'))
        ->toBe('https://custom.example.test/api/v1/auth/token/');
});

it('honours an explicit waas_token_url override', function (): void {
    $resolve = (new ReflectionClass(SasaPayClient::class))->getMethod('resolveTokenUrl');

    expect($resolve->invoke(
        null,
        ['waas_token_url' => 'https://waas.example.test/token/'],
        SasaPayClient::WAAS_SANDBOX_BASE_URL,
        'waas_token_url',
    ))->toBe('https://waas.example.test/token/');
});

it('authenticates against the documented waas token endpoint', function (): void {
    Http::fake([
        // WAAS authenticates on its own /api/v2/waas path, not the v1 one.
        // https://developer.sasapay.app/docs/apis/waas/authentication
        'https://sandbox.sasapay.app/api/v2/waas/auth/token/*' => Http::response([
            'statusCode' => 0,
            'access_token' => 'waas-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
        'https://sandbox.sasapay.app/api/v2/waas/payments/request-payment/' => Http::response([
            'status' => true,
            'responseCode' => '0',
        ], 200),
    ]);

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    $response = $client->waasRequestPayment([
        'merchantReference' => 'REF-001',
        'merchantCode' => '600980',
        'networkCode' => '63902',
        'mobileNumber' => '0700000080',
        'receiverAccountNumber' => '600980-1',
        'amount' => '50',
        'currencyCode' => 'KES',
        'transactionDesc' => 'Wallet topup',
        'callbackUrl' => 'https://example.com/callback',
    ]);

    expect($response['responseCode'])->toBe('0');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/auth/token/?grant_type=client_credentials'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('client-id:client-secret'));
    });

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/payments/request-payment/'
            && $request['mobileNumber'] === '254700000080'
            && $request->hasHeader('Authorization', 'Bearer waas-token');
    });
});

it('maps documented waas endpoints', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v2/waas/*' => Http::response([
            'status' => true,
            'responseCode' => '0',
        ], 200),
        'https://sandbox.sasapay.app/api/v1/*' => Http::response([
            'status' => true,
            'ResponseCode' => '0',
        ], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], $tokenProvider);

    $client->waasPersonalOnboarding(['merchantCode' => '600980']);
    $client->waasConfirmPersonalOnboarding(['merchantCode' => '600980']);
    $client->waasPersonalKyc(['merchantCode' => '600980']);
    $client->waasBusinessOnboarding(['merchantCode' => '600980']);
    $client->waasConfirmBusinessOnboarding(['merchantCode' => '600980']);
    $client->waasBusinessKyc(['merchantCode' => '600980']);
    $client->waasCustomers(['merchant_code' => '600980']);
    $client->waasCustomerDetails(['merchantCode' => '600980']);
    $client->waasUpdateCustomerDetails(['merchantCode' => '600980']);
    $client->waasRequestPayment(['merchantCode' => '600980']);
    $client->waasProcessPayment(['merchantCode' => '600980']);
    $client->waasMerchantTransfer(['merchantCode' => '600980']);
    $client->waasSendMoney(['merchantCode' => '600980']);
    $client->waasPayBill(['merchantCode' => '600980']);
    $client->waasCreateSubWallet(['merchantCode' => '600980']);
    $client->waasTransactions(['merchantCode' => '600980', 'accountNumber' => '600980-1']);
    $client->waasTransactionStatus(['merchantCode' => '600980']);
    $client->waasVerifyTransaction(['merchantCode' => '600980']);
    $client->waasMerchantBalance('600980');
    $client->waasChannelCodes();
    $client->waasCountries();
    $client->waasCountrySubRegions(254);
    $client->waasIndustries();
    $client->waasSubIndustries(62);
    $client->waasBusinessTypes();
    $client->waasProducts();
    $client->waasNearestAgents('36.8157532', '-1.2827683');
    $client->waasUtilityPayment(['merchantCode' => '600980']);
    $client->waasUtilityBillQuery(['merchantCode' => '600980']);

    $urls = collect(Http::recorded())->map(fn (array $record): string => $record[0]->url())->all();

    expect($urls)->toContain(
        'https://sandbox.sasapay.app/api/v2/waas/personal-onboarding/',
        'https://sandbox.sasapay.app/api/v2/waas/personal-onboarding/confirmation/',
        'https://sandbox.sasapay.app/api/v2/waas/personal-onboarding/kyc/',
        'https://sandbox.sasapay.app/api/v2/waas/business-onboarding/',
        'https://sandbox.sasapay.app/api/v2/waas/business-onboarding/confirmation/',
        'https://sandbox.sasapay.app/api/v2/waas/business-onboarding/kyc/',
        'https://sandbox.sasapay.app/api/v2/waas/customers/?merchant_code=600980',
        'https://sandbox.sasapay.app/api/v2/waas/customer-details/',
        'https://sandbox.sasapay.app/api/v2/waas/customer-details/update/',
        'https://sandbox.sasapay.app/api/v2/waas/payments/request-payment/',
        'https://sandbox.sasapay.app/api/v2/waas/payments/process-payment/',
        'https://sandbox.sasapay.app/api/v2/waas/payments/merchant-transfers/',
        'https://sandbox.sasapay.app/api/v2/waas/payments/send-money/',
        'https://sandbox.sasapay.app/api/v2/waas/payments/pay-bills/',
        'https://sandbox.sasapay.app/api/v2/waas/sub-wallets/',
        'https://sandbox.sasapay.app/api/v2/waas/transactions/?merchantCode=600980&accountNumber=600980-1',
        'https://sandbox.sasapay.app/api/v2/waas/transactions/status/',
        'https://sandbox.sasapay.app/api/v2/waas/transactions/verify/',
        'https://sandbox.sasapay.app/api/v2/waas/merchant-balances/?merchantCode=600980',
        'https://sandbox.sasapay.app/api/v2/waas/channel-codes/',
        'https://sandbox.sasapay.app/api/v2/waas/countries/',
        'https://sandbox.sasapay.app/api/v2/waas/countries/sub-regions/?callingCode=254',
        'https://sandbox.sasapay.app/api/v2/waas/industries/',
        'https://sandbox.sasapay.app/api/v2/waas/sub-industries/?industryId=62',
        'https://sandbox.sasapay.app/api/v2/waas/business-types/',
        'https://sandbox.sasapay.app/api/v2/waas/products/',
        'https://sandbox.sasapay.app/api/v2/waas/nearest-agent/?Longitude=36.8157532&Latitude=-1.2827683',
        'https://sandbox.sasapay.app/api/v2/waas/utilities/',
        'https://sandbox.sasapay.app/api/v1/utilities/bill-query',
    );
});

it('sends waas kyc uploads as multipart when files are provided', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v2/waas/business-onboarding/kyc/' => Http::response(['status' => true], 200),
        'https://sandbox.sasapay.app/api/v2/waas/personal-onboarding/kyc/' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'waas_payment_defaults' => [
            'merchantCode' => '600980',
            'currencyCode' => 'KES',
            'callbackUrl' => 'https://example.com/ignored-for-kyc',
        ],
    ], $tokenProvider);

    $client->waasBusinessKyc(
        ['requestId' => 'REQ-123'],
        ['businessKraPin' => ['name' => 'kra.jpg', 'contents' => 'kra contents']],
    );
    $client->waasPersonalKyc(
        ['customerMobileNumber' => '254700000000'],
        ['passportSizePhoto' => ['name' => 'passport.jpg', 'contents' => 'passport contents']],
    );

    Http::assertSent(function ($request): bool {
        $contentType = $request->header('Content-Type')[0] ?? '';
        $body = $request->body();

        return $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/business-onboarding/kyc/'
            && $request->hasHeader('Authorization', 'Bearer token')
            && str_contains($contentType, 'multipart/form-data')
            && str_contains($body, 'merchantCode')
            && str_contains($body, '600980')
            && str_contains($body, 'requestId')
            && str_contains($body, 'REQ-123')
            && str_contains($body, 'businessKraPin')
            && str_contains($body, 'kra contents')
            && ! str_contains($body, 'callbackUrl')
            && ! str_contains($body, 'currencyCode');
    });

    Http::assertSent(function ($request): bool {
        $body = $request->body();

        return $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/personal-onboarding/kyc/'
            && str_contains($body, 'passportSizePhoto')
            && str_contains($body, 'passport contents');
    });
});

it('keeps waas kyc json behavior without files and accepts old options argument shape', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v2/waas/personal-onboarding/kyc/' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'provider-token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], $tokenProvider);

    $client->waasPersonalKyc(
        ['merchantCode' => '600980'],
        new RequestOptions(headers: ['X-Request-Id' => 'kyc-json'], accessToken: 'manual-token'),
    );

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/personal-onboarding/kyc/'
        && $request->hasHeader('Authorization', 'Bearer manual-token')
        && $request->hasHeader('X-Request-Id', 'kyc-json')
        && $request->data() === ['merchantCode' => '600980']);
});

it('exposes raw authorized helpers for core and waas APIs', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/custom/raw' => Http::response(['status' => true], 200),
        'https://sandbox.sasapay.app/api/v1/custom/raw-get?Amount=100' => Http::response(['status' => true], 200),
        'https://sandbox.sasapay.app/api/v2/waas/custom/raw' => Http::response(['status' => true], 200),
        'https://sandbox.sasapay.app/api/v2/waas/custom/raw-get?amount=100.5' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return $forceRefresh ? 'fresh-token' : 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], $tokenProvider);

    $client->authorizedPost('/custom/raw', ['Amount' => 100]);
    $client->authorizedGet('/custom/raw-get', ['Amount' => 100]);
    $client->waasAuthorizedPost('/custom/raw', ['amount' => 100.5], ['force_token_refresh' => true]);
    $client->waasAuthorizedGet('/custom/raw-get', ['amount' => 100.5]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v1/custom/raw'
        && $request->hasHeader('Authorization', 'Bearer token')
        && $request->data()['Amount'] === 100);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/custom/raw'
        && $request->hasHeader('Authorization', 'Bearer fresh-token')
        && $request->data()['amount'] === 100.5);
});

it('honors per-provider endpoint overrides for both v1 and waas', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/custom/request-payment' => Http::response(['ResponseCode' => '0'], 200),
        'https://sandbox.sasapay.app/api/v2/waas/custom/send-money' => Http::response(['ResponseCode' => '0'], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'endpoints' => [
            'request_payment' => '/custom/request-payment',
        ],
        'waas_endpoints' => [
            'send_money' => '/custom/send-money',
        ],
    ], $tokenProvider);

    $client->requestPayment(['Amount' => 1]);
    $client->waasSendMoney(['amount' => 1]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v1/custom/request-payment');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/custom/send-money');
});

it('uses configured user_agent when default_headers omits one', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/payments/check-balance/*' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'user_agent' => 'laravel-payments/sasapay',
    ], $tokenProvider);

    $client->merchantBalance('600980');

    Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', 'laravel-payments/sasapay'));
});

it('keeps a User-Agent supplied via default_headers and ignores the user_agent fallback', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/payments/check-balance/*' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'default_headers' => ['User-Agent' => 'explicit-sasapay/1.0'],
        'user_agent' => 'should-be-ignored',
    ], $tokenProvider);

    $client->merchantBalance('600980');

    Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', 'explicit-sasapay/1.0'));
});

it('exposes v1 and waas tokens via the client when a custom provider is supplied', function (): void {
    Http::fake([
        'https://custom.sasapay.test/*' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return $forceRefresh ? 'sasapay-token-fresh' : 'sasapay-token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'base_url' => 'https://custom.sasapay.test/api/v1',
        'waas_base_url' => 'https://custom.sasapay.test/api/v2/waas',
        'environment' => 'production',
    ], $tokenProvider);

    expect($client->getAccessToken(forceRefresh: true))->toBe('sasapay-token-fresh')
        ->and($client->getWaasAccessToken())->toBe('sasapay-token');
});

it('throws when v1 credentials are missing and no custom token provider is supplied', function (): void {
    expect(fn () => SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ]))->toThrow(ConfigurationException::class, 'SasaPayClient requires either client_id and client_secret');
});

it('throws when shared and waas credentials are both blank', function (): void {
    expect(fn () => SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'client_id' => 'client',
        'client_secret' => 'secret',
        'waas_client_id' => '',
        'waas_client_secret' => '',
    ]))->toThrow(ConfigurationException::class, 'SasaPay WAAS requires either waas_client_id');
});

it('throws ConfigurationException when waas methods are called without a waas base url', function (): void {
    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'staging',
        'base_url' => 'https://custom.sasapay.test/api/v1',
    ], $tokenProvider);

    expect(fn () => $client->waasChannelCodes())
        ->toThrow(ConfigurationException::class, 'SasaPay WAAS production waas_base_url must be provided explicitly.');
});

it('throws ConfigurationException when waas tokens are absent on direct construction', function (): void {
    $transport = new HttpTransport(Http::getFacadeRoot(), 'https://custom.sasapay.test/api/v1');
    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = new SasaPayClient(
        http: $transport,
        tokens: $tokenProvider,
        waasHttp: $transport,
    );

    expect(fn () => $client->getWaasAccessToken())
        ->toThrow(ConfigurationException::class, 'SasaPay WAAS requires either WAAS credentials');
});

it('exposes b2c, b2b, register_ipn_url, lipa_fare and bulk-payment paths against custom hosts', function (): void {
    Http::fake([
        'https://custom.sasapay.test/api/v1/*' => Http::response(['status' => true], 200),
        'https://custom.sasapay.test/api/v2/waas/*' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'base_url' => 'https://custom.sasapay.test/api/v1',
        'waas_base_url' => 'https://custom.sasapay.test/api/v2/waas',
        'environment' => 'production',
    ], $tokenProvider);

    $client->b2cPayment(['Amount' => 10]);
    $client->b2bPayment(['Amount' => 20]);
    $client->registerIpnUrl(['MerchantCode' => '600980']);
    $client->waasBulkPayment(['merchantCode' => '600980']);
    $client->waasBulkPaymentStatus(['merchantCode' => '600980']);
    $client->waasUtilityBillQuery(['merchantCode' => '600980']);

    $urls = collect(Http::recorded())->map(fn (array $record): string => $record[0]->url())->all();

    expect($urls)->toContain(
        'https://custom.sasapay.test/api/v1/payments/b2c/',
        'https://custom.sasapay.test/api/v1/payments/b2b/',
        'https://custom.sasapay.test/api/v1/payments/register-ipn-url/',
        'https://custom.sasapay.test/api/v1/payments/bulk-payments/',
        'https://custom.sasapay.test/api/v1/payments/bulk-payments/status/',
        'https://custom.sasapay.test/api/v1/utilities/bill-query',
    );
});

it('detects sasapay business failures returned with http 200', function (): void {
    $failure = ['status' => false, 'detail' => 'Invalid merchant code'];

    Http::fake([
        'https://sandbox.sasapay.app/*' => Http::response($failure, 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $lenient = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], $tokenProvider);

    expect($lenient->channelCodes())->toBe($failure)
        ->and(SasaPayClient::succeeded($failure))->toBeFalse()
        ->and(SasaPayClient::statusMessage($failure))->toBe('Invalid merchant code')
        ->and(SasaPayClient::succeeded(['status' => true]))->toBeTrue();

    $strict = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'throw_on_business_error' => true,
    ], $tokenProvider);

    expect(fn () => $strict->channelCodes())
        ->toThrow(BusinessException::class, 'Invalid merchant code');
});
