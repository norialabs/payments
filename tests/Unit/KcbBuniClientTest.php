<?php

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Exceptions\BusinessException;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\Exceptions\ValidationException;
use NoriaLabs\Payments\KcbBuniClient;
use NoriaLabs\Payments\Support\Hooks;
use NoriaLabs\Payments\Support\RequestOptions;

function kcbBuniTokenProvider(string $token = 'kcb-token'): AccessTokenProvider
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

function kcbBuniArrayCacheFactory(): CacheManager
{
    $app = new Application;
    $app['config'] = new Repository([
        'cache' => [
            'default' => 'array',
            'stores' => [
                'array' => ['driver' => 'array'],
            ],
            'prefix' => '',
        ],
    ]);

    return new CacheManager($app);
}

it('requests a documented post client-credentials token and sends mpesa stk push', function (): void {
    Http::fake([
        'https://uat.buni.kcbgroup.com/token' => Http::response([
            'access_token' => 'buni-token',
            'expires_in' => 3600,
        ], 200),
        'https://uat.buni.kcbgroup.com/mm/api/request/1.0.0/stkpush' => Http::response([
            'header' => ['statusCode' => '0'],
            'response' => ['ResponseCode' => 0],
        ], 200),
    ]);

    $client = KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'uat',
        'consumer_key' => 'consumer-key',
        'consumer_secret' => 'consumer-secret',
        'mpesa_express' => [
            'route_code' => '207',
        ],
    ]);

    $response = $client->mpesaStkPush([
        'phoneNumber' => '254722000000',
        'amount' => 10,
        'invoiceNumber' => '1234567-INV001',
        'sharedShortCode' => true,
        'orgShortCode' => '',
        'orgPassKey' => '',
        'callbackUrl' => 'https://example.com/kcb-buni/ipn',
        'transactionDescription' => 'school fees',
    ], '232323_KCBOrg_8875661561');

    expect($response['header']['statusCode'])->toBe('0');

    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://uat.buni.kcbgroup.com/token'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('consumer-key:consumer-secret'))
            && $request['grant_type'] === 'client_credentials';
    });

    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://uat.buni.kcbgroup.com/mm/api/request/1.0.0/stkpush'
            && $request['amount'] === '10'
            && $request->hasHeader('Authorization', 'Bearer buni-token')
            && $request->hasHeader('routeCode', '207')
            && $request->hasHeader('operation', 'STKPush')
            && $request->hasHeader('messageId', '232323_KCBOrg_8875661561');
    });
});

it('can preserve raw kcb buni mpesa express amount values when normalization is disabled', function (): void {
    Http::fake([
        'https://uat.buni.kcbgroup.com/mm/api/request/1.0.0/stkpush' => Http::response([
            'header' => ['statusCode' => '0'],
        ], 200),
    ]);

    $rawByConfig = KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'uat',
        'amount_normalization' => 'none',
        'validate_payloads' => false,
    ], kcbBuniTokenProvider('raw-token'));

    $rawByConfig->mpesaStkPush(['amount' => 10.50], 'message-config', routeCode: '207');

    $rawByRequest = KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'uat',
        'validate_payloads' => false,
    ], kcbBuniTokenProvider('raw-token'));

    $rawByRequest->mpesaStkPush(
        ['amount' => 100],
        'message-request',
        new RequestOptions(amountNormalization: 'none'),
        routeCode: '207',
    );

    Http::assertSent(fn ($request): bool => $request->url() === 'https://uat.buni.kcbgroup.com/mm/api/request/1.0.0/stkpush'
        && $request->hasHeader('messageId', 'message-config')
        && $request->data()['amount'] === 10.50);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://uat.buni.kcbgroup.com/mm/api/request/1.0.0/stkpush'
        && $request->hasHeader('messageId', 'message-request')
        && $request->data()['amount'] === 100);
});

it('exposes raw authorized kcb buni helpers without rewriting payloads', function (): void {
    Http::fake([
        'https://uat.buni.kcbgroup.com/custom/raw' => Http::response(['status' => 'posted'], 200),
        'https://uat.buni.kcbgroup.com/custom/raw-get*' => Http::response(['status' => 'fetched'], 200),
    ]);

    $client = KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'uat',
    ], kcbBuniTokenProvider('helper-token'));

    expect($client->authorizedPost('/custom/raw', ['amount' => 10])['status'])->toBe('posted')
        ->and($client->authorizedGet('/custom/raw-get', ['transactionReference' => 'ABC123'])['status'])->toBe('fetched');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://uat.buni.kcbgroup.com/custom/raw'
        && $request->data()['amount'] === 10
        && $request->hasHeader('Authorization', 'Bearer helper-token'));
    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && $request->url() === 'https://uat.buni.kcbgroup.com/custom/raw-get?transactionReference=ABC123'
        && $request->hasHeader('Authorization', 'Bearer helper-token'));
});

it('supports cached tokens custom token urls endpoint overrides and preserved default headers', function (): void {
    Http::fake([
        'https://auth.buni.test/oauth2/token' => Http::response([
            'access_token' => 'cached-buni-token',
            'expires_in' => 3600,
        ], 200),
        'https://uat.buni.kcbgroup.com/custom/transfer' => Http::response(['status' => 'ok'], 200),
    ]);

    $cacheFactory = kcbBuniArrayCacheFactory();
    $config = [
        'environment' => 'uat',
        'validate_payloads' => false,
        'consumer_key' => 'consumer-key',
        'consumer_secret' => 'consumer-secret',
        'token_url' => 'https://auth.buni.test/oauth2/token',
        'cache_store' => 'array',
        'cache_ttl_seconds' => 600,
        'api_key' => 'ignored-subscription-key',
        'user_agent' => 'laravel-payments/kcb-buni-test',
        'default_headers' => [
            'apikey' => 'configured-subscription-key',
        ],
        'endpoints' => [
            'funds_transfer' => '/custom/transfer',
        ],
    ];

    $first = KcbBuniClient::make(
        httpFactory: Http::getFacadeRoot(),
        config: $config,
        cacheFactory: $cacheFactory,
    );

    $second = KcbBuniClient::make(
        httpFactory: Http::getFacadeRoot(),
        config: $config,
        cacheFactory: $cacheFactory,
    );

    $first->transferFunds(['transactionReference' => 'FIRST']);
    $second->transferFunds(['transactionReference' => 'SECOND']);

    Http::assertSentCount(3);
    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://auth.buni.test/oauth2/token'
            && $request->method() === 'POST'
            && $request['grant_type'] === 'client_credentials';
    });
    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://uat.buni.kcbgroup.com/custom/transfer'
            && $request->hasHeader('Authorization', 'Bearer cached-buni-token')
            && $request->hasHeader('User-Agent', 'laravel-payments/kcb-buni-test')
            && $request->hasHeader('apikey', 'configured-subscription-key');
    });
});

it('uses the default cache store for kcb buni tokens when cache_store is true', function (): void {
    Http::fake([
        'https://uat.buni.kcbgroup.com/token' => Http::response([
            'access_token' => 'default-cache-token',
            'expires_in' => 3600,
        ], 200),
    ]);

    $client = KcbBuniClient::make(
        httpFactory: Http::getFacadeRoot(),
        config: [
            'environment' => 'uat',
            'consumer_key' => 'consumer-key',
            'consumer_secret' => 'consumer-secret',
            'cache_store' => true,
        ],
        cacheFactory: kcbBuniArrayCacheFactory(),
    );

    expect($client->getAccessToken())->toBe('default-cache-token');
});

it('maps the verified buni outbound api paths without reshaping payloads', function (): void {
    Http::fake([
        'https://uat.buni.kcbgroup.com/*' => Http::response(['status' => 'ok'], 200),
    ]);

    $client = KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'uat',
        'validate_payloads' => false,
    ], kcbBuniTokenProvider('mapped-token'));

    $client->transferFunds(['debitAmount' => 10]);
    $client->queryCoreTransactionStatus(['header' => ['messageID' => 'message']]);
    $client->queryTransactionDetails('FT220367DV7J');
    $client->vendingValidateRequest(['requestId' => 'validation']);
    $client->vendingVendorConfirmation(['requestId' => 'confirmation']);
    $client->vendingTransactionStatus(['requestId' => 'status']);

    $urls = collect(Http::recorded())->map(fn (array $record): string => $record[0]->url())->all();

    expect($urls)->toContain(
        'https://uat.buni.kcbgroup.com/fundstransfer/1.0.0/api/v1/transfer',
        'https://uat.buni.kcbgroup.com/v1/core/t24/querytransaction/1.0.0/api/transactioninfo',
        'https://uat.buni.kcbgroup.com/kcb/transaction/query/1.0.0/api/v1/payment/query/FT220367DV7J',
        'https://uat.buni.kcbgroup.com/kcb/vendingGateway/v1/1.0.0/api/validate-request',
        'https://uat.buni.kcbgroup.com/kcb/vendingGateway/v1/1.0.0/api/vendor-confirmation',
        'https://uat.buni.kcbgroup.com/kcb/vendingGateway/v1/1.0.0/api/query/transaction-status',
    );

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer mapped-token'));
    Http::assertSent(fn ($request): bool => ($request->data()['debitAmount'] ?? null) === 10);
});

it('supports api key default headers hooks and per-request access token overrides', function (): void {
    Http::fake([
        'https://custom.buni.test/fundstransfer/1.0.0/api/v1/transfer' => Http::response(['status' => 'ok'], 200),
    ]);

    $hooks = new Hooks(
        beforeRequest: function ($context): void {
            $context->headers['X-Hook-Header'] = 'hooked';
        },
    );

    $client = KcbBuniClient::make(Http::getFacadeRoot(), [
        'base_url' => 'https://custom.buni.test',
        'environment' => 'production',
        'validate_payloads' => false,
        'api_key' => 'subscription-key',
        'default_headers' => ['X-Client-Header' => 'client'],
    ], kcbBuniTokenProvider('provider-token'), $hooks);

    $client->transferFunds(['transactionReference' => 'MHSGS7883'], new RequestOptions(
        headers: ['X-Request-Header' => 'request'],
        accessToken: 'request-token',
    ));

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer request-token')
            && $request->hasHeader('apikey', 'subscription-key')
            && $request->hasHeader('X-Client-Header', 'client')
            && $request->hasHeader('X-Request-Header', 'request')
            && $request->hasHeader('X-Hook-Header', 'hooked');
    });
});

it('requires mpesa routeCode and explicit base urls for unmapped environments', function (): void {
    $client = KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'uat',
    ], kcbBuniTokenProvider('token'));

    expect(fn () => $client->mpesaStkPush(['amount' => 10], 'message-id'))
        ->toThrow(ConfigurationException::class, 'routeCode');

    expect(fn () => KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'staging',
    ], kcbBuniTokenProvider('token')))
        ->toThrow(ConfigurationException::class, 'base_url');
});

it('resolves the live buni gateway for the production environment', function (): void {
    Http::fake([
        'https://api.buni.kcbgroup.com/*' => Http::response(['header' => ['statusCode' => '0']], 200),
    ]);

    $client = KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'production',
        'validate_payloads' => false,
    ], kcbBuniTokenProvider('prod-token'));

    $client->transferFunds(['transactionReference' => 'PROD1']);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.buni.kcbgroup.com/fundstransfer/1.0.0/api/v1/transfer');
});

it('exposes the access token from the configured provider', function (): void {
    $client = KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'uat',
    ], kcbBuniTokenProvider('kcb-token'));

    expect($client->getAccessToken())->toBe('kcb-token')
        ->and($client->getAccessToken(forceRefresh: true))->toBe('kcb-token-fresh');
});

it('throws ConfigurationException when no credentials and no token provider are supplied', function (): void {
    expect(fn () => KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'uat',
    ]))->toThrow(ConfigurationException::class);
});

it('validates the documented mpesa express constraints before sending', function (): void {
    Http::fake([
        'https://uat.buni.kcbgroup.com/*' => Http::response(['header' => ['statusCode' => '0']], 200),
    ]);

    $client = KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'uat',
        'mpesa_express' => ['route_code' => '207'],
    ], kcbBuniTokenProvider('validation-token'));

    $valid = [
        'phoneNumber' => '254722000000',
        'amount' => 10,
        'invoiceNumber' => '1234567-INV001',
        'sharedShortCode' => true,
        'orgShortCode' => '',
        'orgPassKey' => '',
        'callbackUrl' => 'https://example.com/kcb-buni/stk-callback',
        'transactionDescription' => 'school fees',
    ];

    expect($client->mpesaStkPush($valid, '232323_KCBOrg_8875661561')['header']['statusCode'])->toBe('0');

    expect(fn () => $client->mpesaStkPush(
        array_replace($valid, ['transactionDescription' => 'a school fees payment']),
        'MSG1',
    ))->toThrow(ValidationException::class, '[transactionDescription] must not exceed 13 characters');

    $client->mpesaStkPush(array_replace($valid, ['phoneNumber' => '0722000000']), 'MSG1');

    Http::assertSent(fn ($request): bool => $request['phoneNumber'] === '254722000000');

    expect(fn () => $client->mpesaStkPush(array_replace($valid, ['sharedShortCode' => 'true']), 'MSG1'))
        ->toThrow(ValidationException::class, '[sharedShortCode] must be a boolean.');

    $missing = $valid;
    unset($missing['orgPassKey']);
    expect(fn () => $client->mpesaStkPush($missing, 'MSG1'))
        ->toThrow(ValidationException::class, '[orgPassKey] is required.');

    expect(fn () => $client->mpesaStkPush($valid, str_repeat('m', 33)))
        ->toThrow(ValidationException::class, '[messageId] must not exceed 32 characters');
});

it('validates the documented funds transfer constraints before sending', function (): void {
    Http::fake([
        'https://uat.buni.kcbgroup.com/*' => Http::response(['header' => ['statusCode' => '0']], 200),
    ]);

    $client = KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'uat',
    ], kcbBuniTokenProvider('validation-token'));

    $valid = [
        'companyCode' => 'KE0010001',
        'transactionType' => 'IF',
        'debitAccountNumber' => '37890012',
        'creditAccountNumber' => '909099090',
        'debitAmount' => 10,
        'paymentDetails' => 'fee payment',
        'transactionReference' => 'MHSGS7883',
        'currency' => 'KES',
        'beneficiaryDetails' => 'JOHN DOE',
    ];

    expect($client->transferFunds($valid)['header']['statusCode'])->toBe('0');

    expect(fn () => $client->transferFunds(array_replace($valid, ['transactionReference' => 'REFERENCE1234567'])))
        ->toThrow(ValidationException::class, '[transactionReference] must not exceed 12 characters');

    expect(fn () => $client->transferFunds(array_replace($valid, ['debitAmount' => 'ten'])))
        ->toThrow(ValidationException::class, '[debitAmount] must be numeric.');

    Http::assertSent(fn ($request): bool => ($request->data()['debitAmount'] ?? null) === 10);
});

it('can disable payload validation per request', function (): void {
    Http::fake([
        'https://uat.buni.kcbgroup.com/*' => Http::response(['header' => ['statusCode' => '0']], 200),
    ]);

    $client = KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'uat',
    ], kcbBuniTokenProvider('opt-out-token'));

    $client->transferFunds(['transactionReference' => 'PARTIAL'], new RequestOptions(validate: false));
    $client->transferFunds(['transactionReference' => 'PARTIAL2'], ['validate' => false]);

    Http::assertSentCount(2);
});

it('maps the wildcard etims and p2p buni apis', function (): void {
    Http::fake([
        'https://uat.buni.kcbgroup.com/*' => Http::response(['status' => 'ok'], 200),
    ]);

    $client = KcbBuniClient::make(Http::getFacadeRoot(), [
        'environment' => 'uat',
    ], kcbBuniTokenProvider('wildcard-token'));

    $client->etimsRequest('api/v1/sales', ['invoiceNumber' => 'INV1']);
    $client->etimsRequest('api/v1/sales/INV1', method: 'GET', query: ['detail' => 'full']);
    $client->p2pTransferStatusInquiry(['transactionReference' => 'P2P1']);

    $requests = collect(Http::recorded())->map(fn (array $record): array => [
        $record[0]->method(),
        strtok($record[0]->url(), '?'),
    ])->all();

    expect($requests)->toBe([
        ['POST', 'https://uat.buni.kcbgroup.com/kcb/ke/kra/etims/1.0.0/api/v1/sales'],
        ['GET', 'https://uat.buni.kcbgroup.com/kcb/ke/kra/etims/1.0.0/api/v1/sales/INV1'],
        ['POST', 'https://uat.buni.kcbgroup.com/kcb/bi/ips/p2p/transfer/status/inquiry/1.0.0/'],
    ]);
});

it('detects buni business failures returned with http 200', function (): void {
    $failure = [
        'header' => ['statusCode' => '1', 'statusMessage' => 'Insufficient funds'],
    ];

    Http::fake([
        'https://uat.buni.kcbgroup.com/*' => Http::response($failure, 200),
    ]);

    $config = [
        'environment' => 'uat',
        'validate_payloads' => false,
    ];

    $lenient = KcbBuniClient::make(Http::getFacadeRoot(), $config, kcbBuniTokenProvider('t'));
    expect($lenient->transferFunds(['transactionReference' => 'X']))->toBe($failure);

    expect(KcbBuniClient::succeeded($failure))->toBeFalse()
        ->and(KcbBuniClient::statusCode($failure))->toBe('1')
        ->and(KcbBuniClient::statusMessage($failure))->toBe('Insufficient funds')
        ->and(KcbBuniClient::succeeded(['header' => ['statusCode' => '0']]))->toBeTrue()
        ->and(KcbBuniClient::succeeded(['response' => ['ResponseCode' => 0]]))->toBeTrue()
        ->and(KcbBuniClient::succeeded(['unrelated' => 'shape']))->toBeNull();

    $strict = KcbBuniClient::make(
        Http::getFacadeRoot(),
        array_replace($config, ['throw_on_business_error' => true]),
        kcbBuniTokenProvider('t'),
    );

    expect(fn () => $strict->transferFunds(['transactionReference' => 'X']))
        ->toThrow(BusinessException::class, 'KCB Buni Funds Transfer failed: Insufficient funds (status 1)');

    expect(fn () => $lenient->transferFunds(['transactionReference' => 'X'], ['throw_on_business_error' => true]))
        ->toThrow(BusinessException::class, 'Insufficient funds');
});
