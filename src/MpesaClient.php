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

class MpesaClient
{
    public const BASE_URLS = [
        'sandbox' => 'https://sandbox.safaricom.co.ke',
        'production' => 'https://api.safaricom.co.ke',
    ];

    public const ENDPOINTS = [
        'oauth_token' => '/oauth/v1/generate',
        'stk_push' => '/mpesa/stkpush/v1/processrequest',
        'stk_push_query' => '/mpesa/stkpushquery/v1/query',
        'c2b_register_url' => '/mpesa/c2b/{version}/registerurl',
        'c2b_simulate' => '/mpesa/c2b/v1/simulate',
        'b2c_payment' => '/mpesa/b2c/{version}/paymentrequest',
        'b2b_payment' => '/mpesa/b2b/v1/paymentrequest',
        'b2b_express_checkout' => '/v1/ussdpush/get-msisdn',
        'reversal' => '/mpesa/reversal/v1/request',
        'transaction_status' => '/mpesa/transactionstatus/v1/query',
        'account_balance' => '/mpesa/accountbalance/v1/query',
        'dynamic_qr' => '/mpesa/qrcode/v1/generate',
        'tax_remittance' => '/mpesa/b2b/v1/remittax',
        'bill_manager_opt_in' => '/v1/billmanager-invoice/optin',
        'bill_manager_single_invoice' => '/v1/billmanager-invoice/single-invoicing',
        'bill_manager_bulk_invoicing' => '/v1/billmanager-invoice/bulk-invoicing',
        'bill_manager_reconciliation' => '/v1/billmanager-invoice/reconciliation',
        'bill_manager_cancel_single_invoice' => '/v1/billmanager-invoice/cancel-single-invoice',
        'bill_manager_cancel_bulk_invoice' => '/v1/billmanager-invoice/cancel-bulk-invoice',
        'bill_manager_update_onboarding_details' => '/v1/billmanager-invoice/change-optin-details',
        'bill_manager_update_single_invoice' => '/v1/billmanager-invoice/change-invoice',
        'bill_manager_update_bulk_invoice' => '/v1/billmanager-invoice/change-invoices',
        'ratiba_standing_order' => '/standingorder/v1/createStandingOrderExternal',
        'pull_transactions_register' => '/pulltransactions/v1/register',
        'pull_transactions' => '/pulltransactions/v1/query',
    ];

    public function __construct(
        private readonly HttpTransport $http,
        private readonly AccessTokenProvider $tokens,
        private readonly array $endpoints = self::ENDPOINTS,
        private readonly string $defaultB2cVersion = 'v1',
        private readonly string $amountNormalization = 'string',
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

        $endpoints = self::resolveEndpoints($config);
        $tokens = $tokenProvider ?? ClientCredentialsTokenProvider::forConfig(
            httpFactory: $httpFactory,
            tokenUrl: rtrim($baseUrl, '/').$endpoints['oauth_token'],
            config: $config,
            idKey: 'consumer_key',
            secretKey: 'consumer_secret',
            missingCredentialsMessage: 'MpesaClient requires either consumer_key and consumer_secret, or a custom token provider.',
            cacheFactory: $cacheFactory,
            cacheKey: self::tokenCacheKey($config),
        );

        return new self(
            http: $transport,
            tokens: $tokens,
            endpoints: $endpoints,
            defaultB2cVersion: (string) ($config['b2c_version'] ?? 'v1'),
            amountNormalization: Payload::resolveAmountNormalization($config['amount_normalization'] ?? null),
            throwOnBusinessError: self::boolean($config['throw_on_business_error'] ?? false),
        );
    }

    public function getAccessToken(bool $forceRefresh = false): string
    {
        return $this->tokens->getAccessToken($forceRefresh);
    }

    public function stkPush(array $payload, array|RequestOptions|null $options = null): mixed
    {
        $payload = Payload::normalizeKenyanPhoneNumbers($payload, ['PartyA', 'PhoneNumber']);

        return $this->authorizedRequest($this->endpoint('stk_push'), $this->withAmount($payload, $options), $options);
    }

    public function stkPushQuery(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('stk_push_query'), $payload, $options);
    }

    public function registerC2BUrls(
        array $payload,
        string $version = 'v2',
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->authorizedRequest(
            $this->endpoint('c2b_register_url', ['version' => $version]),
            $payload,
            $options,
        );
    }

    public function registerC2BUrlsV1(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->registerC2BUrls($payload, 'v1', $options);
    }

    public function c2bSimulate(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('c2b_simulate'), $this->withAmount($payload, $options), $options);
    }

    public function b2cPayment(
        array $payload,
        array|RequestOptions|null $options = null,
        ?string $version = null,
    ): mixed {
        return $this->authorizedRequest(
            $this->endpoint('b2c_payment', ['version' => $version ?? $this->defaultB2cVersion]),
            $this->withAmount($payload, $options),
            $options,
        );
    }

    public function b2cPaymentV3(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->b2cPayment($payload, $options, 'v3');
    }

    public function b2bPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('b2b_payment'), $this->withAmount($payload, $options), $options);
    }

    public function b2cAccountTopUp(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            $this->endpoint('b2b_payment'),
            $this->withAmount($this->withCommand($payload, 'BusinessPayToBulk'), $options),
            $options,
        );
    }

    public function businessPayBill(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            $this->endpoint('b2b_payment'),
            $this->withAmount($this->withCommand($payload, 'BusinessPayBill'), $options),
            $options,
        );
    }

    public function businessBuyGoods(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            $this->endpoint('b2b_payment'),
            $this->withAmount($this->withCommand($payload, 'BusinessBuyGoods'), $options),
            $options,
        );
    }

    public function b2bExpressCheckout(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest(
            $this->endpoint('b2b_express_checkout'),
            $this->withAmount($payload, $options),
            $options,
        );
    }

    public function reversal(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('reversal'), $this->withAmount($payload, $options), $options);
    }

    public function transactionStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('transaction_status'), $payload, $options);
    }

    public function accountBalance(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('account_balance'), $payload, $options);
    }

    public function generateQrCode(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('dynamic_qr'), $this->withAmount($payload, $options), $options);
    }

    public function taxRemittance(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('tax_remittance'), $this->withAmount($payload, $options), $options);
    }

    public function billManagerOptIn(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bill_manager_opt_in'), $payload, $options);
    }

    public function billManagerSingleInvoice(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bill_manager_single_invoice'), $this->withAmount($payload, $options), $options);
    }

    public function billManagerBulkInvoicing(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bill_manager_bulk_invoicing'), $payload, $options);
    }

    public function billManagerReconciliation(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bill_manager_reconciliation'), $payload, $options);
    }

    public function billManagerCancelSingleInvoice(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bill_manager_cancel_single_invoice'), $payload, $options);
    }

    public function billManagerCancelBulkInvoice(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bill_manager_cancel_bulk_invoice'), $payload, $options);
    }

    public function billManagerUpdateOnboardingDetails(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bill_manager_update_onboarding_details'), $payload, $options);
    }

    public function billManagerUpdateSingleInvoice(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bill_manager_update_single_invoice'), $this->withAmount($payload, $options), $options);
    }

    public function billManagerUpdateBulkInvoice(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bill_manager_update_bulk_invoice'), $payload, $options);
    }

    public function ratibaStandingOrder(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('ratiba_standing_order'), $this->withAmount($payload, $options), $options);
    }

    public function registerPullTransactions(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('pull_transactions_register'), $payload, $options);
    }

    public function pullTransactions(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('pull_transactions'), $payload, $options);
    }

    public static function succeeded(mixed $response): ?bool
    {
        return BusinessStatus::succeeded(BusinessStatus::MPESA, $response);
    }

    public static function statusCode(mixed $response): ?string
    {
        return BusinessStatus::statusCode(BusinessStatus::MPESA, $response);
    }

    public static function statusMessage(mixed $response): ?string
    {
        return BusinessStatus::statusMessage(BusinessStatus::MPESA, $response);
    }

    public static function buildTimestamp(?\DateTimeInterface $dateTime = null): string
    {
        return ($dateTime ?? new \DateTimeImmutable)->format('YmdHis');
    }

    public static function buildStkPassword(string $businessShortCode, string $passkey, string $timestamp): string
    {
        return base64_encode($businessShortCode.$passkey.$timestamp);
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
        return $this->authorizedRequest($path, null, $options, 'GET', $query);
    }

    private function authorizedRequest(
        string $path,
        ?array $payload,
        array|RequestOptions|null $options,
        string $method = 'POST',
        ?array $query = null,
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

        if ($requestOptions->throwOnBusinessError ?? $this->throwOnBusinessError) {
            BusinessStatus::assert(BusinessStatus::MPESA, $response, "M-PESA {$method} {$path}");
        }

        return $response;
    }

    private function withAmount(array $payload, array|RequestOptions|null $options): array
    {
        $requestOptions = RequestOptions::fromArray($options);

        return Payload::normalizeAmount($payload, $requestOptions->amountNormalization ?? $this->amountNormalization);
    }

    private function withCommand(array $payload, string $commandId): array
    {
        if (! array_key_exists('CommandID', $payload)) {
            $payload['CommandID'] = $commandId;
        }

        return $payload;
    }

    private function endpoint(string $name, array $replacements = []): string
    {
        $endpoint = $this->endpoints[$name];

        foreach ($replacements as $key => $value) {
            $endpoint = str_replace('{'.$key.'}', (string) $value, $endpoint);
        }

        return $endpoint;
    }

    private static function resolveBaseUrl(array $config): string
    {
        $baseUrl = $config['base_url'] ?? null;

        if (is_string($baseUrl) && trim($baseUrl) !== '') {
            return trim($baseUrl);
        }

        $environment = (string) ($config['environment'] ?? 'sandbox');

        if (isset(self::BASE_URLS[$environment])) {
            return self::BASE_URLS[$environment];
        }

        throw new ConfigurationException(
            "Unknown M-PESA environment [{$environment}]. Use one of ["
            .implode(', ', array_keys(self::BASE_URLS)).'] or set an explicit base_url.'
        );
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

    private static function tokenCacheKey(array $config): string
    {
        $env = (string) ($config['environment'] ?? 'sandbox');
        $base = self::resolveBaseUrl($config);
        $consumer = (string) ($config['consumer_key'] ?? '');

        return 'payments:mpesa:token:'.sha1($env.'|'.$base.'|'.$consumer);
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
