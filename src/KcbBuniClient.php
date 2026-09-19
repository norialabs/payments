<?php

namespace NoriaLabs\Payments;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory;
use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\Support\BusinessStatus;
use NoriaLabs\Payments\Support\CachedAccessTokenProvider;
use NoriaLabs\Payments\Support\ClientCredentialsTokenProvider;
use NoriaLabs\Payments\Support\FieldRules;
use NoriaLabs\Payments\Support\Hooks;
use NoriaLabs\Payments\Support\HttpTransport;
use NoriaLabs\Payments\Support\Payload;
use NoriaLabs\Payments\Support\RequestOptions;
use NoriaLabs\Payments\Support\RetryPolicy;

class KcbBuniClient
{
    public const BASE_URLS = [
        'uat' => 'https://uat.buni.kcbgroup.com',
        'production' => 'https://api.buni.kcbgroup.com',
    ];

    public const ENDPOINTS = [
        'mpesa_stk_push' => '/mm/api/request/1.0.0/stkpush',
        'funds_transfer' => '/fundstransfer/1.0.0/api/v1/transfer',
        'query_core_transaction_status' => '/v1/core/t24/querytransaction/1.0.0/api/transactioninfo',
        'query_transaction_details' => '/kcb/transaction/query/1.0.0/api/v1/payment/query/{identifier}',
        'vending_validate_request' => '/kcb/vendingGateway/v1/1.0.0/api/validate-request',
        'vending_vendor_confirmation' => '/kcb/vendingGateway/v1/1.0.0/api/vendor-confirmation',
        'vending_transaction_status' => '/kcb/vendingGateway/v1/1.0.0/api/query/transaction-status',
        'etims' => '/kcb/ke/kra/etims/1.0.0/{path}',
        'p2p_transfer_status_inquiry' => '/kcb/bi/ips/p2p/transfer/status/inquiry/1.0.0/{path}',
    ];

    public const MPESA_STK_PUSH_RULES = [
        'phoneNumber' => ['required' => true, 'notEmpty' => true, 'max' => 12, 'pattern' => '/^254\d{9}$/', 'format' => '2547XXXXXXXX'],
        'amount' => ['required' => true, 'max' => 18, 'numeric' => true],
        'invoiceNumber' => ['required' => true, 'notEmpty' => true, 'max' => 24],
        'sharedShortCode' => ['required' => true, 'boolean' => true],
        'orgShortCode' => ['required' => true, 'max' => 12],
        'orgPassKey' => ['required' => true],
        'callbackUrl' => ['required' => true, 'notEmpty' => true],
        'transactionDescription' => ['required' => true, 'notEmpty' => true, 'max' => 13],
    ];

    public const MPESA_STK_PUSH_HEADER_RULES = [
        'routeCode' => ['required' => true, 'notEmpty' => true, 'max' => 64],
        'operation' => ['required' => true, 'notEmpty' => true, 'max' => 64],
        'messageId' => ['required' => true, 'notEmpty' => true, 'max' => 32],
    ];

    public const FUNDS_TRANSFER_RULES = [
        'companyCode' => ['required' => true, 'notEmpty' => true, 'max' => 15],
        'transactionType' => ['required' => true, 'notEmpty' => true, 'max' => 2],
        'debitAccountNumber' => ['required' => true, 'notEmpty' => true, 'max' => 10],
        'creditAccountNumber' => ['required' => true, 'notEmpty' => true, 'max' => 10],
        'debitAmount' => ['required' => true, 'numeric' => true],
        'paymentDetails' => ['required' => true, 'notEmpty' => true, 'max' => 35],
        'transactionReference' => ['required' => true, 'notEmpty' => true, 'max' => 12],
        'currency' => ['required' => true, 'notEmpty' => true, 'max' => 3],
        'beneficiaryDetails' => ['required' => true, 'notEmpty' => true, 'max' => 35],
        'beneficiaryBankCode' => ['max' => 20],
    ];

    /**
     * @param  array<string, string>  $endpoints
     * @param  array<string, mixed>  $mpesaExpress
     */
    public function __construct(
        private readonly HttpTransport $http,
        private readonly AccessTokenProvider $tokens,
        private readonly array $endpoints = self::ENDPOINTS,
        private readonly array $mpesaExpress = [],
        private readonly string $amountNormalization = 'string',
        private readonly bool $validatePayloads = true,
        private readonly bool $throwOnBusinessError = false,
    ) {}

    public static function make(
        Factory $httpFactory,
        array $config = [],
        ?AccessTokenProvider $tokenProvider = null,
        ?Hooks $hooks = null,
        ?CacheFactory $cacheFactory = null,
    ): self {
        $baseUrl = self::resolveBaseUrl($config);

        $transport = new HttpTransport(
            http: $httpFactory,
            baseUrl: $baseUrl,
            timeoutSeconds: isset($config['timeout_seconds']) ? (float) $config['timeout_seconds'] : null,
            defaultHeaders: self::resolveDefaultHeaders($config),
            retry: RetryPolicy::fromArray($config['retry'] ?? null),
            hooks: $hooks,
        );

        return new self(
            http: $transport,
            tokens: $tokenProvider ?? self::tokenProvider($httpFactory, $config, $baseUrl, $cacheFactory),
            endpoints: self::resolveEndpoints($config),
            mpesaExpress: (array) ($config['mpesa_express'] ?? []),
            amountNormalization: Payload::resolveAmountNormalization($config['amount_normalization'] ?? null),
            validatePayloads: self::boolean($config['validate_payloads'] ?? true),
            throwOnBusinessError: self::boolean($config['throw_on_business_error'] ?? false),
        );
    }

    public function getAccessToken(bool $forceRefresh = false): string
    {
        return $this->tokens->getAccessToken($forceRefresh);
    }

    public function mpesaStkPush(
        array $payload,
        string $messageId,
        array|RequestOptions|null $options = null,
        ?string $routeCode = null,
    ): mixed {
        $requestOptions = $this->withOptionHeaders($options, [
            'routeCode' => $routeCode ?? $this->requiredMpesaRouteCode(),
            'operation' => self::nullableString($this->mpesaExpress['operation'] ?? null) ?? 'STKPush',
            'messageId' => $messageId,
        ]);

        $payload = Payload::normalizeKenyanPhoneNumbers($payload, ['phoneNumber']);
        $payload = $this->withAmount($payload, $requestOptions);

        if ($this->shouldValidate($requestOptions)) {
            FieldRules::assert(
                array_intersect_key($requestOptions->headers, self::MPESA_STK_PUSH_HEADER_RULES),
                self::MPESA_STK_PUSH_HEADER_RULES,
                'KCB Buni M-PESA Express header',
            );

            FieldRules::assert($payload, self::MPESA_STK_PUSH_RULES, 'KCB Buni M-PESA Express');
        }

        return $this->authorizedRequest(
            path: $this->endpoint('mpesa_stk_push'),
            method: 'POST',
            payload: $payload,
            query: null,
            options: $requestOptions,
            businessContext: 'KCB Buni M-PESA Express',
        );
    }

    public function authorizedPost(string $path, array $payload = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($path, 'POST', $payload, null, $options);
    }

    public function authorizedGet(
        string $path,
        array $query = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->authorizedRequest($path, 'GET', null, $query, $options);
    }

    public function transferFunds(array $payload, array|RequestOptions|null $options = null): mixed
    {
        $requestOptions = RequestOptions::fromArray($options);

        if ($this->shouldValidate($requestOptions)) {
            FieldRules::assert($payload, self::FUNDS_TRANSFER_RULES, 'KCB Buni Funds Transfer');
        }

        return $this->authorizedRequest(
            path: $this->endpoint('funds_transfer'),
            method: 'POST',
            payload: $payload,
            query: null,
            options: $requestOptions,
            businessContext: 'KCB Buni Funds Transfer',
        );
    }

    public function queryCoreTransactionStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            path: $this->endpoint('query_core_transaction_status'),
            method: 'POST',
            payload: $payload,
            query: null,
            options: $options,
            businessContext: 'KCB Buni Core Transaction Status',
        );
    }

    public function queryTransactionDetails(
        string|int $identifier,
        array $query = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->authorizedRequest(
            path: $this->endpoint('query_transaction_details', ['identifier' => rawurlencode((string) $identifier)]),
            method: 'GET',
            payload: null,
            query: $query,
            options: $options,
            businessContext: 'KCB Buni Transaction Details',
        );
    }

    public function vendingValidateRequest(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            path: $this->endpoint('vending_validate_request'),
            method: 'POST',
            payload: $payload,
            query: null,
            options: $options,
            businessContext: 'KCB Buni Vending Validate Request',
        );
    }

    public function vendingVendorConfirmation(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            path: $this->endpoint('vending_vendor_confirmation'),
            method: 'POST',
            payload: $payload,
            query: null,
            options: $options,
            businessContext: 'KCB Buni Vending Vendor Confirmation',
        );
    }

    public function vendingTransactionStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            path: $this->endpoint('vending_transaction_status'),
            method: 'POST',
            payload: $payload,
            query: null,
            options: $options,
            businessContext: 'KCB Buni Vending Transaction Status',
        );
    }

    public function etimsRequest(
        string $path,
        array $payload = [],
        string $method = 'POST',
        array $query = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        $method = strtoupper($method);

        return $this->authorizedRequest(
            path: $this->endpoint('etims', ['path' => ltrim($path, '/')]),
            method: $method,
            payload: $method === 'GET' ? null : $payload,
            query: $query,
            options: $options,
            businessContext: 'KCB Buni eTIMS',
        );
    }

    public function p2pTransferStatusInquiry(
        array $payload,
        string $path = '',
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->authorizedRequest(
            path: $this->endpoint('p2p_transfer_status_inquiry', ['path' => ltrim($path, '/')]),
            method: 'POST',
            payload: $payload,
            query: null,
            options: $options,
            businessContext: 'KCB Buni P2P Transfer Status Inquiry',
        );
    }

    public static function succeeded(mixed $response): ?bool
    {
        return BusinessStatus::succeeded(BusinessStatus::KCB_BUNI, $response);
    }

    public static function statusCode(mixed $response): ?string
    {
        return BusinessStatus::statusCode(BusinessStatus::KCB_BUNI, $response);
    }

    public static function statusMessage(mixed $response): ?string
    {
        return BusinessStatus::statusMessage(BusinessStatus::KCB_BUNI, $response);
    }

    private function authorizedRequest(
        string $path,
        string $method,
        ?array $payload,
        ?array $query,
        array|RequestOptions|null $options,
        ?string $businessContext = null,
    ): mixed {
        $requestOptions = RequestOptions::fromArray($options);
        $token = $requestOptions->accessToken ?? $this->tokens->getAccessToken($requestOptions->forceTokenRefresh);

        $headers = array_merge($requestOptions->headers, [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        $response = $this->http->send(
            path: $path,
            method: $method,
            headers: $headers,
            query: $query,
            body: $payload,
            timeoutSeconds: $requestOptions->timeoutSeconds,
            retry: $requestOptions->retry,
        );

        if ($businessContext !== null && ($requestOptions->throwOnBusinessError ?? $this->throwOnBusinessError)) {
            BusinessStatus::assert(BusinessStatus::KCB_BUNI, $response, $businessContext);
        }

        return $response;
    }

    private function shouldValidate(RequestOptions $options): bool
    {
        return $options->validate ?? $this->validatePayloads;
    }

    private function endpoint(string $name, array $replacements = []): string
    {
        $endpoint = $this->endpoints[$name] ?? self::ENDPOINTS[$name];

        foreach ($replacements as $key => $value) {
            $endpoint = str_replace('{'.$key.'}', (string) $value, $endpoint);
        }

        return $endpoint;
    }

    private function requiredMpesaRouteCode(): string
    {
        $routeCode = self::nullableString($this->mpesaExpress['route_code'] ?? null);

        if ($routeCode === null) {
            throw new ConfigurationException(
                'KcbBuniClient M-PESA STK Push requires a routeCode. Pass routeCode to mpesaStkPush() or configure payments.kcb_buni.mpesa_express.route_code.'
            );
        }

        return $routeCode;
    }

    private function withOptionHeaders(array|RequestOptions|null $options, array $headers): RequestOptions
    {
        $requestOptions = RequestOptions::fromArray($options);

        return new RequestOptions(
            headers: array_merge($requestOptions->headers, $headers),
            timeoutSeconds: $requestOptions->timeoutSeconds,
            retry: $requestOptions->retry,
            accessToken: $requestOptions->accessToken,
            forceTokenRefresh: $requestOptions->forceTokenRefresh,
            amountNormalization: $requestOptions->amountNormalization,
            validate: $requestOptions->validate,
            throwOnBusinessError: $requestOptions->throwOnBusinessError,
        );
    }

    private function withAmount(array $payload, array|RequestOptions|null $options): array
    {
        $requestOptions = RequestOptions::fromArray($options);

        return Payload::normalizeAmount($payload, $requestOptions->amountNormalization ?? $this->amountNormalization);
    }

    private static function tokenProvider(
        Factory $httpFactory,
        array $config,
        string $baseUrl,
        ?CacheFactory $cacheFactory,
    ): AccessTokenProvider {
        $consumerKey = self::nullableString($config['consumer_key'] ?? null);
        $consumerSecret = self::nullableString($config['consumer_secret'] ?? null);

        if ($consumerKey === null || $consumerSecret === null) {
            throw new ConfigurationException(
                'KcbBuniClient requires either consumer_key and consumer_secret, or a custom token provider.'
            );
        }

        $skew = (int) ($config['token_cache_skew_seconds'] ?? 60);
        $provider = new ClientCredentialsTokenProvider(
            http: $httpFactory,
            tokenUrl: self::resolveTokenUrl($config, $baseUrl),
            clientId: $consumerKey,
            clientSecret: $consumerSecret,
            timeoutSeconds: isset($config['timeout_seconds']) ? (float) $config['timeout_seconds'] : null,
            cacheSkewSeconds: $skew,
            method: 'POST',
            body: ['grant_type' => 'client_credentials'],
            asForm: true,
        );

        $cacheStore = $config['cache_store'] ?? null;

        if ($cacheFactory === null || $cacheStore === null || $cacheStore === false) {
            return $provider;
        }

        $repository = $cacheStore === true || $cacheStore === '' || $cacheStore === 'default'
            ? $cacheFactory->store()
            : $cacheFactory->store((string) $cacheStore);

        return new CachedAccessTokenProvider(
            inner: $provider,
            cache: $repository,
            cacheKey: self::tokenCacheKey($config, $baseUrl, $consumerKey),
            cacheSkewSeconds: $skew,
            cacheTtlSeconds: isset($config['cache_ttl_seconds']) ? (int) $config['cache_ttl_seconds'] : null,
        );
    }

    private static function resolveBaseUrl(array $config): string
    {
        $baseUrl = self::nullableString($config['base_url'] ?? null);

        if ($baseUrl !== null) {
            return $baseUrl;
        }

        $environment = (string) ($config['environment'] ?? 'uat');

        if (isset(self::BASE_URLS[$environment])) {
            return self::BASE_URLS[$environment];
        }

        throw new ConfigurationException(
            'KcbBuniClient base_url must be provided explicitly for environments other than ['
            .implode(', ', array_keys(self::BASE_URLS)).'].'
        );
    }

    private static function resolveTokenUrl(array $config, string $baseUrl): string
    {
        $tokenUrl = self::nullableString($config['token_url'] ?? null);

        if ($tokenUrl !== null) {
            return $tokenUrl;
        }

        $tokenPath = self::nullableString($config['token_path'] ?? null) ?? '/token';

        return rtrim($baseUrl, '/').'/'.ltrim($tokenPath, '/');
    }

    private static function resolveEndpoints(array $config): array
    {
        $endpoints = self::ENDPOINTS;

        foreach ((array) ($config['endpoints'] ?? []) as $name => $path) {
            if ($path !== null && $path !== '') {
                $endpoints[$name] = (string) $path;
            }
        }

        return $endpoints;
    }

    private static function resolveDefaultHeaders(array $config): array
    {
        $headers = (array) ($config['default_headers'] ?? []);
        $userAgent = $config['user_agent'] ?? null;

        if (is_string($userAgent) && $userAgent !== '' && ! self::hasHeader($headers, 'User-Agent')) {
            $headers['User-Agent'] = $userAgent;
        }

        $apiKey = self::nullableString($config['api_key'] ?? null);

        if ($apiKey !== null && ! self::hasHeader($headers, 'apikey')) {
            $headers['apikey'] = $apiKey;
        }

        return $headers;
    }

    private static function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function tokenCacheKey(array $config, string $baseUrl, string $consumerKey): string
    {
        $env = (string) ($config['environment'] ?? 'uat');

        return 'payments:kcb_buni:token:'.sha1($env.'|'.$baseUrl.'|'.$consumerKey);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

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
