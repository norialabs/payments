<?php

namespace NoriaLabs\Payments;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory;
use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\Support\BusinessStatus;
use NoriaLabs\Payments\Support\ClientCredentialsTokenProvider;
use NoriaLabs\Payments\Support\Hooks;
use NoriaLabs\Payments\Support\HttpTransport;
use NoriaLabs\Payments\Support\Payload;
use NoriaLabs\Payments\Support\RequestOptions;
use NoriaLabs\Payments\Support\RetryPolicy;

class SasaPayClient
{
    public const SANDBOX_BASE_URL = 'https://sandbox.sasapay.app/api/v1';

    public const WAAS_SANDBOX_BASE_URL = 'https://sandbox.sasapay.app/api/v2/waas';

    /**
     * SasaPay documents the sandbox hosts only. These were established by probing
     * the live hosts; override with `base_url` / `waas_base_url` if SasaPay issues
     * your production application a different host.
     */
    public const PRODUCTION_BASE_URL = 'https://api.sasapay.app/api/v1';

    public const WAAS_PRODUCTION_BASE_URL = 'https://api.sasapay.app/api/v2/waas';

    /**
     * Both surfaces authenticate at `{base_url}/auth/token/`, GET, HTTP Basic, with
     * `grant_type` in the query string.
     *
     * @see https://developer.sasapay.app/docs/apis/authentication
     * @see https://developer.sasapay.app/docs/apis/waas/authentication
     */
    public const TOKEN_PATH = '/auth/token/';

    public const ENDPOINTS = [
        'request_payment' => '/payments/request-payment/',
        'process_payment' => '/payments/process-payment/',
        'b2c_payment' => '/payments/b2c/',
        'b2b_payment' => '/payments/b2b/',
        'card_payment' => '/payments/card-payments/',
        'pre_approved_payment' => '/payments/approved/',
        'remittance_payment' => '/remittances/remittance-payments/',
        'account_validation' => '/accounts/account-validation/',
        'internal_fund_movement' => '/transactions/fund-movement/',
        'transaction_status' => '/transactions/status/',
        'transaction_status_query' => '/transactions/status-query/',
        'transaction_status_exact' => '/transactions/status/',
        'request_payment_status' => '/payments/request-payment/status/',
        'merchant_balance' => '/payments/check-balance/',
        'verify_transaction' => '/transactions/verify/',
        'business_to_beneficiary' => '/payments/b2c/beneficiary/',
        'register_ipn_url' => '/payments/register-ipn-url/',
        'lipa_fare' => '/payments/lipa-fare/',
        'transactions' => '/transactions/',
        'channel_codes' => '/payments/channel-codes/',
        'utility_payment' => '/utilities/',
        'utility_bill_query' => '/utilities/bill-query',
        'bulk_payment' => '/payments/bulk-payments/',
        'bulk_payment_status' => '/payments/bulk-payments/status/',
        'dealer_business_types' => '/accounts/business-types/',
        'dealer_countries' => '/accounts/countries/',
        'dealer_sub_counties' => '/accounts/sub-counties/',
        'dealer_industries' => '/accounts/industries/',
        'available_bill_number' => '/accounts/available-bill-number/',
        'merchant_onboarding' => '/accounts/merchant-onboarding/',
    ];

    public const WAAS_ENDPOINTS = [
        'personal_onboarding' => '/personal-onboarding/',
        'personal_onboarding_confirmation' => '/personal-onboarding/confirmation/',
        'personal_kyc' => '/personal-onboarding/kyc/',
        'business_onboarding' => '/business-onboarding/',
        'business_onboarding_confirmation' => '/business-onboarding/confirmation/',
        'business_kyc' => '/business-onboarding/kyc/',
        'customers' => '/customers/',
        'customer_details' => '/customer-details/',
        'customer_details_update' => '/customer-details/update/',
        'request_payment' => '/payments/request-payment/',
        'process_payment' => '/payments/process-payment/',
        'merchant_transfers' => '/payments/merchant-transfers/',
        'send_money' => '/payments/send-money/',
        'pay_bills' => '/payments/pay-bills/',
        'create_sub_wallet' => '/sub-wallets/',
        'transactions' => '/transactions/',
        'transaction_status' => '/transactions/status/',
        'verify_transaction' => '/transactions/verify/',
        'merchant_balance' => '/merchant-balances/',
        'channel_codes' => '/channel-codes/',
        'countries' => '/countries/',
        'country_sub_regions' => '/countries/sub-regions/',
        'industries' => '/industries/',
        'sub_industries' => '/sub-industries/',
        'business_types' => '/business-types/',
        'products' => '/products/',
        'nearest_agents' => '/nearest-agent/',
        'utility_payment' => '/utilities/',
    ];

    /**
     * @param  array<string, string>  $endpoints
     * @param  array<string, string>  $waasEndpoints
     */
    public function __construct(
        private readonly HttpTransport $http,
        private readonly AccessTokenProvider $tokens,
        private readonly array $endpoints = self::ENDPOINTS,
        private readonly ?HttpTransport $waasHttp = null,
        private readonly ?AccessTokenProvider $waasTokens = null,
        private readonly array $waasEndpoints = self::WAAS_ENDPOINTS,
        private readonly string $amountNormalization = 'string',
        private readonly array $paymentDefaults = [],
        private readonly array $waasPaymentDefaults = [],
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
        $waasBaseUrl = self::resolveWaasBaseUrl($config);
        $defaultHeaders = self::resolveDefaultHeaders($config);

        $transport = new HttpTransport(
            http: $httpFactory,
            baseUrl: $baseUrl,
            timeoutSeconds: isset($config['timeout_seconds']) ? (float) $config['timeout_seconds'] : null,
            defaultHeaders: $defaultHeaders,
            retry: RetryPolicy::fromArray($config['retry'] ?? null),
            hooks: $hooks,
        );

        $tokenUrl = self::resolveTokenUrl($config, $baseUrl);

        $tokens = $tokenProvider ?? ClientCredentialsTokenProvider::forConfig(
            httpFactory: $httpFactory,
            tokenUrl: $tokenUrl,
            config: $config,
            idKey: 'client_id',
            secretKey: 'client_secret',
            missingCredentialsMessage: 'SasaPayClient requires either client_id and client_secret, or a custom token provider.',
            cacheFactory: $cacheFactory,
            cacheKey: self::tokenCacheKey('v1', $config, $tokenUrl, (string) ($config['client_id'] ?? '')),
        );

        $waasTransport = $waasBaseUrl === null ? null : new HttpTransport(
            http: $httpFactory,
            baseUrl: $waasBaseUrl,
            timeoutSeconds: isset($config['timeout_seconds']) ? (float) $config['timeout_seconds'] : null,
            defaultHeaders: $defaultHeaders,
            retry: RetryPolicy::fromArray($config['retry'] ?? null),
            hooks: $hooks,
        );

        $waasTokens = $waasBaseUrl === null
            ? null
            : ($tokenProvider ?? self::resolveWaasTokenProvider($httpFactory, $waasBaseUrl, $config, $cacheFactory));

        return new self(
            http: $transport,
            tokens: $tokens,
            endpoints: self::resolveEndpoints($config, 'endpoints', self::ENDPOINTS),
            waasHttp: $waasTransport,
            waasTokens: $waasTokens,
            waasEndpoints: self::resolveEndpoints($config, 'waas_endpoints', self::WAAS_ENDPOINTS),
            amountNormalization: Payload::resolveAmountNormalization($config['amount_normalization'] ?? null),
            paymentDefaults: self::resolveDefaults($config, 'payment_defaults', ['MerchantCode', 'Currency', 'CallBackURL']),
            waasPaymentDefaults: self::resolveDefaults($config, 'waas_payment_defaults', ['merchantCode', 'currencyCode', 'callbackUrl']),
            throwOnBusinessError: self::boolean($config['throw_on_business_error'] ?? false),
        );
    }

    public static function succeeded(mixed $response): ?bool
    {
        return BusinessStatus::succeeded(BusinessStatus::SASAPAY, $response);
    }

    public static function statusCode(mixed $response): ?string
    {
        return BusinessStatus::statusCode(BusinessStatus::SASAPAY, $response);
    }

    public static function statusMessage(mixed $response): ?string
    {
        return BusinessStatus::statusMessage(BusinessStatus::SASAPAY, $response);
    }

    public function getAccessToken(bool $forceRefresh = false): string
    {
        return $this->tokens->getAccessToken($forceRefresh);
    }

    public function getWaasAccessToken(bool $forceRefresh = false): string
    {
        return $this->ensureWaasTokens()->getAccessToken($forceRefresh);
    }

    public function requestPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        $payload = Payload::normalizeKenyanPhoneNumbers($payload, ['PhoneNumber']);

        return $this->authorizedRequest(
            $this->endpoint('request_payment'),
            $this->withAmount($this->withPaymentDefaults($payload), $options),
            $options
        );
    }

    public function processPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('process_payment'), $payload, $options);
    }

    public function b2cPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            $this->endpoint('b2c_payment'),
            $this->withAmount($this->withPaymentDefaults($payload), $options),
            $options
        );
    }

    public function b2bPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            $this->endpoint('b2b_payment'),
            $this->withAmount($this->withPaymentDefaults($payload), $options),
            $options
        );
    }

    public function cardPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            $this->endpoint('card_payment'),
            $this->withAmount($this->withPaymentDefaults($payload), $options),
            $options
        );
    }

    public function preApprovedPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            $this->endpoint('pre_approved_payment'),
            $this->withAmount($this->withPaymentDefaults($payload), $options),
            $options
        );
    }

    public function remittancePayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            $this->endpoint('remittance_payment'),
            $this->withAmount($this->withPaymentDefaults($payload), $options),
            $options
        );
    }

    public function accountValidation(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('account_validation'), $payload, $options);
    }

    public function internalFundMovement(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('internal_fund_movement'), $payload, $options);
    }

    public function transactionStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('transaction_status'), $payload, $options);
    }

    public function transactionStatusQuery(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('transaction_status_query'), $payload, $options);
    }

    public function transactionStatusExact(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('transaction_status_exact'), $payload, $options);
    }

    public function requestPaymentStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('request_payment_status'), $payload, $options);
    }

    public function merchantBalance(string|int $merchantCode, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('merchant_balance'), [
            'MerchantCode' => (string) $merchantCode,
        ], $options);
    }

    public function verifyTransaction(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('verify_transaction'), $payload, $options);
    }

    public function businessToBeneficiary(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('business_to_beneficiary'), $this->withAmount($payload, $options), $options);
    }

    public function registerIpnUrl(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('register_ipn_url'), $payload, $options);
    }

    public function lipaFare(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            $this->endpoint('lipa_fare'),
            $this->withAmount($this->withPaymentDefaults($payload), $options),
            $options
        );
    }

    public function transactions(array $query, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('transactions'), $query, $options);
    }

    public function channelCodes(array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('channel_codes'), options: $options);
    }

    public function utilityPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('utility_payment'), $payload, $options);
    }

    public function utilityBillQuery(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('utility_bill_query'), $payload, $options);
    }

    public function bulkPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bulk_payment'), $payload, $options);
    }

    public function bulkPaymentStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bulk_payment_status'), $payload, $options);
    }

    public function dealerBusinessTypes(array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('dealer_business_types'), options: $options);
    }

    public function dealerCountries(array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('dealer_countries'), options: $options);
    }

    public function dealerSubCounties(string|int $countyId, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('dealer_sub_counties'), [
            'county_id' => (string) $countyId,
        ], $options);
    }

    public function dealerIndustries(array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('dealer_industries'), options: $options);
    }

    public function availableBillNumber(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('available_bill_number'), $query, $options);
    }

    public function merchantOnboarding(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('merchant_onboarding'), $payload, $options);
    }

    public function waasPersonalOnboarding(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest(
            $this->waasEndpoint('personal_onboarding'),
            $this->withWaasPaymentDefaults($payload, ['currencyCode']),
            $options
        );
    }

    public function waasConfirmPersonalOnboarding(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest(
            $this->waasEndpoint('personal_onboarding_confirmation'),
            $this->withWaasPaymentDefaults($payload, ['currencyCode', 'callbackUrl']),
            $options
        );
    }

    public function waasPersonalKyc(
        array $payload,
        array|RequestOptions|null $files = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        [$resolvedFiles, $resolvedOptions] = $this->resolveFilesAndOptions($files, $options);
        $payload = $this->withWaasPaymentDefaults($payload, ['currencyCode', 'callbackUrl']);

        if ($resolvedFiles === []) {
            return $this->waasAuthorizedRequest($this->waasEndpoint('personal_kyc'), $payload, $resolvedOptions);
        }

        return $this->waasAuthorizedMultipartPost($this->waasEndpoint('personal_kyc'), $payload, $resolvedFiles, $resolvedOptions);
    }

    public function waasBusinessOnboarding(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest(
            $this->waasEndpoint('business_onboarding'),
            $this->withWaasPaymentDefaults($payload, ['currencyCode']),
            $options
        );
    }

    public function waasConfirmBusinessOnboarding(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest(
            $this->waasEndpoint('business_onboarding_confirmation'),
            $this->withWaasPaymentDefaults($payload, ['currencyCode', 'callbackUrl']),
            $options
        );
    }

    public function waasBusinessKyc(
        array $payload,
        array|RequestOptions|null $files = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        [$resolvedFiles, $resolvedOptions] = $this->resolveFilesAndOptions($files, $options);
        $payload = $this->withWaasPaymentDefaults($payload, ['currencyCode', 'callbackUrl']);

        if ($resolvedFiles === []) {
            return $this->waasAuthorizedRequest($this->waasEndpoint('business_kyc'), $payload, $resolvedOptions);
        }

        return $this->waasAuthorizedMultipartPost($this->waasEndpoint('business_kyc'), $payload, $resolvedFiles, $resolvedOptions);
    }

    public function waasCustomers(array $query, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('customers'), $query, $options);
    }

    public function waasCustomerDetails(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('customer_details'), $payload, $options);
    }

    public function waasUpdateCustomerDetails(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('customer_details_update'), $payload, $options);
    }

    public function waasRequestPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        $payload = Payload::normalizeKenyanPhoneNumbers($payload, ['mobileNumber']);

        return $this->waasAuthorizedRequest($this->waasEndpoint('request_payment'), $this->withWaasPaymentDefaults($payload), $options);
    }

    public function waasProcessPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('process_payment'), $payload, $options);
    }

    public function waasMerchantTransfer(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('merchant_transfers'), $this->withWaasPaymentDefaults($payload), $options);
    }

    public function waasSendMoney(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('send_money'), $this->withWaasPaymentDefaults($payload), $options);
    }

    public function waasPayBill(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('pay_bills'), $this->withWaasPaymentDefaults($payload), $options);
    }

    public function waasBulkPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->bulkPayment($payload, $options);
    }

    public function waasBulkPaymentStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->bulkPaymentStatus($payload, $options);
    }

    public function waasCreateSubWallet(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('create_sub_wallet'), $payload, $options);
    }

    public function waasTransactions(array $query, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('transactions'), $query, $options);
    }

    public function waasTransactionStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('transaction_status'), $payload, $options);
    }

    public function waasVerifyTransaction(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('verify_transaction'), $payload, $options);
    }

    public function waasMerchantBalance(string|int $merchantCode, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('merchant_balance'), [
            'merchantCode' => (string) $merchantCode,
        ], $options);
    }

    public function waasChannelCodes(array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('channel_codes'), options: $options);
    }

    public function waasCountries(array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('countries'), options: $options);
    }

    public function waasCountrySubRegions(string|int $callingCode, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('country_sub_regions'), [
            'callingCode' => (string) $callingCode,
        ], $options);
    }

    public function waasIndustries(array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('industries'), options: $options);
    }

    public function waasSubIndustries(string|int $industryId, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('sub_industries'), [
            'industryId' => (string) $industryId,
        ], $options);
    }

    public function waasBusinessTypes(array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('business_types'), options: $options);
    }

    public function waasProducts(array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('products'), options: $options);
    }

    public function waasNearestAgents(string|float $longitude, string|float $latitude, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('nearest_agents'), [
            'Longitude' => (string) $longitude,
            'Latitude' => (string) $latitude,
        ], $options);
    }

    public function waasUtilityPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('utility_payment'), $payload, $options);
    }

    public function waasUtilityBillQuery(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->utilityBillQuery($payload, $options);
    }

    public function authorizedPost(string $path, array $payload = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($path, $payload, $options);
    }

    public function authorizedGet(
        string $path,
        array $query = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->sendAuthorized($this->http, $this->tokens, $path, 'GET', null, $query, $options);
    }

    public function authorizedMultipartPost(
        string $path,
        array $fields = [],
        array $files = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->sendAuthorizedMultipart($this->http, $this->tokens, $path, $fields, $files, $options);
    }

    public function waasAuthorizedPost(string $path, array $payload = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($path, $payload, $options);
    }

    public function waasAuthorizedGet(
        string $path,
        array $query = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->sendAuthorized($this->ensureWaasHttp(), $this->ensureWaasTokens(), $path, 'GET', null, $query, $options);
    }

    public function waasAuthorizedMultipartPost(
        string $path,
        array $fields = [],
        array $files = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->sendAuthorizedMultipart($this->ensureWaasHttp(), $this->ensureWaasTokens(), $path, $fields, $files, $options);
    }

    private function authorizedRequest(string $path, array $payload, array|RequestOptions|null $options): mixed
    {
        return $this->sendAuthorized($this->http, $this->tokens, $path, 'POST', $payload, null, $options);
    }

    private function waasAuthorizedRequest(string $path, array $payload, array|RequestOptions|null $options): mixed
    {
        return $this->sendAuthorized($this->ensureWaasHttp(), $this->ensureWaasTokens(), $path, 'POST', $payload, null, $options);
    }

    private function sendAuthorized(
        HttpTransport $transport,
        AccessTokenProvider $tokens,
        string $path,
        string $method,
        ?array $payload,
        ?array $query,
        array|RequestOptions|null $options,
    ): mixed {
        $requestOptions = RequestOptions::fromArray($options);
        $token = $requestOptions->accessToken ?? $tokens->getAccessToken($requestOptions->forceTokenRefresh);

        $headers = array_merge($requestOptions->headers, [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        return $this->assertBusinessStatus(
            $transport->send(
                path: $path,
                method: $method,
                headers: $headers,
                query: $query,
                body: $payload,
                timeoutSeconds: $requestOptions->timeoutSeconds,
                retry: $requestOptions->retry,
            ),
            $requestOptions,
            "SasaPay {$method} {$path}",
        );
    }

    private function sendAuthorizedMultipart(
        HttpTransport $transport,
        AccessTokenProvider $tokens,
        string $path,
        array $fields,
        array $files,
        array|RequestOptions|null $options,
    ): mixed {
        $requestOptions = RequestOptions::fromArray($options);
        $token = $requestOptions->accessToken ?? $tokens->getAccessToken($requestOptions->forceTokenRefresh);

        $headers = array_merge($requestOptions->headers, [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        return $this->assertBusinessStatus(
            $transport->sendMultipart(
                path: $path,
                method: 'POST',
                fields: $fields,
                files: $files,
                headers: $headers,
                timeoutSeconds: $requestOptions->timeoutSeconds,
                retry: $requestOptions->retry,
            ),
            $requestOptions,
            "SasaPay POST {$path}",
        );
    }

    private function assertBusinessStatus(mixed $response, RequestOptions $options, string $context): mixed
    {
        if ($options->throwOnBusinessError ?? $this->throwOnBusinessError) {
            BusinessStatus::assert(BusinessStatus::SASAPAY, $response, $context);
        }

        return $response;
    }

    private function withAmount(array $payload, array|RequestOptions|null $options): array
    {
        $requestOptions = RequestOptions::fromArray($options);

        return Payload::normalizeAmount($payload, $requestOptions->amountNormalization ?? $this->amountNormalization);
    }

    private function withPaymentDefaults(array $payload): array
    {
        return $this->withDefaults($payload, $this->paymentDefaults);
    }

    /**
     * @param  array<int, string>  $except
     */
    private function withWaasPaymentDefaults(array $payload, array $except = []): array
    {
        return $this->withDefaults($payload, array_diff_key($this->waasPaymentDefaults, array_flip($except)));
    }

    private function withDefaults(array $payload, array $defaults): array
    {
        foreach ($defaults as $key => $value) {
            if ($value !== null && ! array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @return array{0: array, 1: array|RequestOptions|null}
     */
    private function resolveFilesAndOptions(
        array|RequestOptions|null $files,
        array|RequestOptions|null $options,
    ): array {
        if ($files instanceof RequestOptions || $files === null) {
            return [[], $options ?? $files];
        }

        if ($options === null && $this->looksLikeRequestOptions($files)) {
            return [[], $files];
        }

        return [$files, $options];
    }

    private function looksLikeRequestOptions(array $value): bool
    {
        foreach (['headers', 'timeout_seconds', 'retry', 'access_token', 'force_token_refresh', 'amount_normalization', 'amountNormalization'] as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }

        return false;
    }

    private function endpoint(string $name): string
    {
        return $this->endpoints[$name] ?? self::ENDPOINTS[$name];
    }

    private function waasEndpoint(string $name): string
    {
        return $this->waasEndpoints[$name] ?? self::WAAS_ENDPOINTS[$name];
    }

    private static function resolveBaseUrl(array $config): string
    {
        if (! empty($config['base_url'])) {
            return (string) $config['base_url'];
        }

        $environment = (string) ($config['environment'] ?? 'sandbox');

        return match ($environment) {
            'sandbox' => self::SANDBOX_BASE_URL,
            'production', 'live' => self::PRODUCTION_BASE_URL,
            default => throw new ConfigurationException(
                "Unknown SasaPay environment [{$environment}]. Use sandbox or production, or set an explicit base_url."
            ),
        };
    }

    private static function resolveWaasBaseUrl(array $config): ?string
    {
        if (! empty($config['waas_base_url'])) {
            return (string) $config['waas_base_url'];
        }

        return match ((string) ($config['environment'] ?? 'sandbox')) {
            'sandbox' => self::WAAS_SANDBOX_BASE_URL,
            'production', 'live' => self::WAAS_PRODUCTION_BASE_URL,
            default => null,
        };
    }

    private function ensureWaasHttp(): HttpTransport
    {
        if ($this->waasHttp === null) {
            throw new ConfigurationException(
                'SasaPay WAAS production waas_base_url must be provided explicitly.'
            );
        }

        return $this->waasHttp;
    }

    private function ensureWaasTokens(): AccessTokenProvider
    {
        if ($this->waasTokens === null) {
            throw new ConfigurationException(
                'SasaPay WAAS requires either WAAS credentials, shared SasaPay credentials, or a custom token provider.'
            );
        }

        return $this->waasTokens;
    }

    private static function resolveWaasTokenProvider(
        Factory $httpFactory,
        string $baseUrl,
        array $config,
        ?CacheFactory $cacheFactory,
    ): AccessTokenProvider {
        $clientId = $config['waas_client_id'] ?? $config['client_id'] ?? null;
        $clientSecret = $config['waas_client_secret'] ?? $config['client_secret'] ?? null;

        if (empty($clientId) || empty($clientSecret)) {
            throw new ConfigurationException(
                'SasaPay WAAS requires either waas_client_id and waas_client_secret, shared client_id and client_secret, or a custom token provider.'
            );
        }

        $waasConfig = $config;
        $waasConfig['client_id'] = $clientId;
        $waasConfig['client_secret'] = $clientSecret;
        $waasConfig['token_cache_skew_seconds'] = (int) ($config['waas_token_cache_skew_seconds']
            ?? $config['token_cache_skew_seconds']
            ?? 60);
        $tokenUrl = self::resolveTokenUrl($config, $baseUrl, 'waas_token_url');

        return ClientCredentialsTokenProvider::forConfig(
            httpFactory: $httpFactory,
            tokenUrl: $tokenUrl,
            config: $waasConfig,
            idKey: 'client_id',
            secretKey: 'client_secret',
            missingCredentialsMessage: 'SasaPay WAAS requires either waas_client_id and waas_client_secret, shared client_id and client_secret, or a custom token provider.',
            cacheFactory: $cacheFactory,
            cacheKey: self::tokenCacheKey('waas', $config, $tokenUrl, (string) $clientId),
        );
    }

    /**
     * The token path hangs off the whole base URL, including its `/api/v1` or
     * `/api/v2/waas` prefix. Rebuilding from `scheme://host` alone would drop that
     * prefix and point both surfaces at a path SasaPay does not serve.
     *
     * `waas_token_url` deliberately does not fall back to `token_url`: the two are
     * different endpoints, so reusing a configured v1 URL for WAAS would authenticate
     * against the wrong surface.
     */
    private static function resolveTokenUrl(array $config, string $baseUrl, string $key = 'token_url'): string
    {
        $configured = $config[$key] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $baseUrl = trim($baseUrl);
        $parts = parse_url($baseUrl);

        if ($baseUrl === '' || ! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException(
                "Unable to derive the SasaPay authentication URL from base URL [{$baseUrl}]. Set {$key} explicitly."
            );
        }

        return rtrim($baseUrl, '/').self::TOKEN_PATH;
    }

    private static function resolveEndpoints(array $config, string $key, array $defaults): array
    {
        $endpoints = $defaults;

        foreach ((array) ($config[$key] ?? []) as $name => $path) {
            if ($path !== null && $path !== '') {
                $endpoints[$name] = (string) $path;
            }
        }

        return $endpoints;
    }

    /**
     * @param  array<int, string>  $allowedKeys
     * @return array<string, mixed>
     */
    private static function resolveDefaults(array $config, string $key, array $allowedKeys): array
    {
        $defaults = [];
        $configured = (array) ($config[$key] ?? []);

        foreach ($allowedKeys as $allowedKey) {
            if (array_key_exists($allowedKey, $configured) && $configured[$allowedKey] !== null) {
                $defaults[$allowedKey] = $configured[$allowedKey];
            }
        }

        return $defaults;
    }

    private static function resolveDefaultHeaders(array $config): array
    {
        $headers = (array) ($config['default_headers'] ?? []);
        $userAgent = $config['user_agent'] ?? null;

        if (is_string($userAgent) && $userAgent !== '' && ! self::hasHeader($headers, 'User-Agent')) {
            $headers['User-Agent'] = $userAgent;
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

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function tokenCacheKey(string $variant, array $config, string $baseUrl, string $clientId): string
    {
        $env = (string) ($config['environment'] ?? 'sandbox');

        return 'payments:sasapay:'.$variant.':token:'.sha1($env.'|'.$baseUrl.'|'.$clientId);
    }
}
