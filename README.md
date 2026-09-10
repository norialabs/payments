# Laravel Payments

Laravel package for payment providers:

- M-PESA Daraja
- SasaPay v1 merchant APIs
- SasaPay Wallet as a Service (WAAS) v2 APIs
- KCB Buni APIs
- Paystack APIs

The package is a Laravel-native HTTP SDK. It registers container bindings, publishes config, obtains and caches OAuth tokens where providers require them, sends authenticated requests, supports retries and hooks, verifies KCB Buni IPNs and Paystack webhooks, applies the available controls to SasaPay callbacks, and throws typed exceptions for HTTP and network failures.

It does not persist transactions, define your application callback controllers, reconcile settlements, or transform provider callback payloads. Your application owns those concerns.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13

## Installation

```bash
composer require thekiharani/laravel-payments
```

The service provider is auto-discovered. Publish the config:

```bash
php artisan vendor:publish --tag="payments-config"
```

## Bindings

The package registers:

- `NoriaLabs\Payments\PaymentsManager`
- `NoriaLabs\Payments\MpesaClient`
- `NoriaLabs\Payments\SasaPayClient`
- `NoriaLabs\Payments\SasaPayCallbackVerifier`
- `NoriaLabs\Payments\KcbBuniClient`
- `NoriaLabs\Payments\KcbBuniIpnVerifier`
- `NoriaLabs\Payments\PaystackClient`
- `NoriaLabs\Payments\PaystackWebhookVerifier`

It also registers the facade alias:

- `Payments`

And these route middleware aliases:

- `kcb-buni.ipn` → `VerifyKcbBuniIpn`
- `sasapay.callback` → `VerifySasaPayCallback`
- `paystack.webhook` → `VerifyPaystackWebhook`

## Config

Published config file: `config/payments.php`

Top-level sections:

- `http`
- `mpesa`
- `sasapay`
- `kcb_buni`
- `paystack`

### Shared HTTP Config

| Key | Description |
| --- | --- |
| `timeout_seconds` | Default request timeout. |
| `default_headers` | Headers applied to every provider request. |
| `user_agent` | Optional `User-Agent` fallback applied when `default_headers` does not already include one. |
| `cache_store` | Optional Laravel cache store for provider OAuth tokens. Use `true` or `default` for the default store. Leave unset to use only per-client in-memory token caching. |
| `cache_ttl_seconds` | Optional OAuth-token cache TTL override. When omitted, token `expires_in` is used. |
| `retry.max_attempts` | Total attempts including the first request. |
| `retry.retry_methods` | Methods eligible for retry, for example `POST`. Empty means all methods. |
| `retry.retry_on_statuses` | HTTP statuses eligible for retry. |
| `retry.retry_on_network_error` | Whether connection failures/timeouts are retried. |
| `retry.base_delay_seconds` | Initial retry delay. |
| `retry.max_delay_seconds` | Maximum retry delay. |
| `retry.backoff_multiplier` | Retry delay multiplier. |
| `retry.jitter_seconds` | Maximum random jitter added to computed backoff delays. |
| `retry.respect_retry_after` | Whether retryable HTTP responses should honor a `Retry-After` header before using configured backoff. |

### M-PESA Config

| Key | Description |
| --- | --- |
| `environment` | `sandbox` or `production`. Any other value requires an explicit `base_url`, otherwise the client throws `ConfigurationException`. |
| `base_url` | Optional full base URL override. |
| `throw_on_business_error` | Throw `BusinessException` when Daraja answers HTTP 200 with an `errorCode` or a non-zero `ResponseCode`/`ResultCode`. Defaults to `false`. See [Business-Level Failures](#business-level-failures). |
| `consumer_key` | Daraja consumer key. |
| `consumer_secret` | Daraja consumer secret. |
| `token_cache_skew_seconds` | Refresh token before expiry by this many seconds. |
| `b2c_version` | Default B2C payment API version. Defaults to `v1`; set `MPESA_B2C_VERSION=v3` only when your Daraja app is enabled for the v3 B2C path. |
| `amount_normalization` | M-PESA amount handling. Defaults to `string`; set to `none` to preserve raw numeric `Amount`/`amount` values. |
| `cache_store` | Optional M-PESA-specific token cache store override. |
| `cache_ttl_seconds` | Optional M-PESA-specific token cache TTL override. |
| `endpoints` | Optional endpoint-path overrides keyed by `MpesaClient::ENDPOINTS`. Useful when Safaricom enables tenant-specific or newer product paths. |

### SasaPay Config

| Key | Description |
| --- | --- |
| `environment` | `sandbox` or `production`. Any other value requires an explicit `base_url`. |
| `base_url` | SasaPay v1 base URL. Defaults to `https://sandbox.sasapay.app/api/v1` in sandbox and `https://api.sasapay.app/api/v1` in production. |
| `waas_base_url` | SasaPay WAAS v2 base URL. Defaults to `https://sandbox.sasapay.app/api/v2/waas` in sandbox and `https://api.sasapay.app/api/v2/waas` in production. |
| `token_url` | Optional full SasaPay v1 OAuth URL. Defaults to `/auth/token/` appended to `base_url`. |
| `waas_token_url` | Optional full WAAS OAuth URL. Defaults to `/auth/token/` appended to `waas_base_url`. It does not fall back to `token_url`: the two are different endpoints. |
| `throw_on_business_error` | Throw `BusinessException` when SasaPay answers HTTP 200 with `"status": false`. Defaults to `false`. See [Business-Level Failures](#business-level-failures). |
| `client_id` | SasaPay v1 client ID. Also used for WAAS unless WAAS-specific credentials are configured. |
| `client_secret` | SasaPay v1 client secret. Also used for WAAS unless WAAS-specific credentials are configured. |
| `waas_client_id` | Optional WAAS-specific client ID. |
| `waas_client_secret` | Optional WAAS-specific client secret. |
| `token_cache_skew_seconds` | v1 token cache skew. |
| `waas_token_cache_skew_seconds` | WAAS token cache skew. |
| `cache_store` | Optional SasaPay-specific token cache store override. |
| `cache_ttl_seconds` | Optional SasaPay-specific token cache TTL override. |
| `amount_normalization` | SasaPay amount handling. Defaults to `string`; set to `none` to preserve raw numeric `Amount`/`amount` values. |
| `payment_defaults` | Optional v1 defaults for `MerchantCode`, `Currency`, and `CallBackURL`. Defaults are added only when the payload omits the key. |
| `waas_payment_defaults` | Optional WAAS defaults for `merchantCode`, `currencyCode`, and `callbackUrl`. Defaults are added only when the payload omits the key. |
| `endpoints` | Optional SasaPay v1 endpoint-path overrides keyed by `SasaPayClient::ENDPOINTS`. |
| `waas_endpoints` | Optional SasaPay WAAS endpoint-path overrides keyed by `SasaPayClient::WAAS_ENDPOINTS`. |
| `callback_security.secret_key` | HMAC secret for inbound callbacks. Defaults to the SasaPay client ID, as documented by SasaPay. |
| `callback_security.trusted_ips` | SasaPay callback source IP allowlist. Defaults to the documented SasaPay list. Override in published config or with comma-separated `SASAPAY_CALLBACK_TRUSTED_IPS`. |
| `callback_security.enforce_ip_whitelist` | Reject callbacks from non-allowlisted IPs when using `verifyRequest()` or the middleware. Defaults to `false`; enable it after Laravel trusted proxy handling is configured for your deployment. |
| `callback_security.verify_signature` | Verify callback HMAC signatures when using `verifyRequest()` or the middleware. Defaults to `true`, which fails closed: SasaPay publishes no signing scheme, so unless it has issued you one every callback is rejected. Set `SASAPAY_CALLBACK_VERIFY_SIGNATURE=false` when you rely on a capability token or IP allowlisting instead. See [SasaPay Callback Security](#sasapay-callback-security). |

SasaPay documents OAuth client-credentials authentication separately for each
surface, and each authenticates on its own base URL:

| Surface | Token endpoint | Docs |
| --- | --- | --- |
| v1 | `GET {base_url}/auth/token/?grant_type=client_credentials` | [authentication](https://developer.sasapay.app/docs/apis/authentication) |
| WAAS | `GET {waas_base_url}/auth/token/?grant_type=client_credentials` | [waas/authentication](https://developer.sasapay.app/docs/apis/waas/authentication) |

Both send HTTP Basic with the client ID and secret, and carry `grant_type` in the
query string. The two responses differ: v1 reports success as `"status": true`
with `detail` and `scope`, WAAS as `"statusCode": 0`. Override `token_url` /
`waas_token_url` if SasaPay issues your application a different authentication
host.

### KCB Buni Config

| Key | Description |
| --- | --- |
| `environment` | `uat` (default) or `production`. Anything else requires an explicit `base_url`. |
| `base_url` | Optional full base URL override. |
| `validate_payloads` | Validate outbound payloads against the constraints published in Buni's OpenAPI documents before sending. Defaults to `true`. See [KCB Buni Payload Validation](#kcb-buni-payload-validation). |
| `throw_on_business_error` | Throw `BusinessException` when Buni answers HTTP 200 with a non-zero status. Defaults to `false`. See [Business-Level Failures](#business-level-failures). |
| `token_url` | Optional full OAuth token URL override. |
| `token_path` | Token path used with `base_url` when `token_url` is unset. Defaults to `/token`. |
| `consumer_key` | Buni application consumer key. |
| `consumer_secret` | Buni application consumer secret. |
| `api_key` | Optional WSO2 `apikey` header value when your subscribed API requires it. The verified M-PESA Express Postman collection used bearer auth without an `apikey` header. |
| `token_cache_skew_seconds` | Refresh token before expiry by this many seconds. |
| `amount_normalization` | KCB Buni M-PESA Express amount handling. Defaults to `string`; set to `none` to preserve raw numeric `amount` values. |
| `cache_store` | Optional KCB Buni-specific token cache store override. |
| `cache_ttl_seconds` | Optional KCB Buni-specific token cache TTL override. |
| `endpoints` | Optional endpoint-path overrides keyed by `KcbBuniClient::ENDPOINTS`. |
| `mpesa_express.route_code` | Required `routeCode` header for `mpesaStkPush()` unless passed per call. Buni's M-PESA Express docs show `207` for M-PESA. |
| `mpesa_express.operation` | `operation` header for `mpesaStkPush()`. Defaults to the documented `STKPush`. |
| `ipn_security.public_key` | KCB public key used to verify inbound IPN `Signature` headers with SHA256withRSA. |
| `ipn_security.trusted_ips` | Optional KCB Buni IPN source IP allowlist. The verified public docs specify signature verification but do not publish a fixed IP list. |
| `ipn_security.enforce_ip_whitelist` | Reject IPNs from non-allowlisted IPs when using `verifyRequest()` or the middleware. Defaults to `false`. |
| `ipn_security.verify_signature` | Verify the IPN `Signature` header over the raw request body. Defaults to `true`. |

KCB Buni token acquisition is `POST /token` with HTTP Basic client credentials and `grant_type=client_credentials` as form data. This was verified against the UAT token endpoint: GET returns HTTP 405, while POST reaches OAuth client validation. Buni also accepts `grant_type` as a query parameter; the form-encoded body used here works on both hosts.

#### KCB Buni hosts

| Environment | Host | How it was established |
| --- | --- | --- |
| `uat` | `https://uat.buni.kcbgroup.com` | Published on the Buni DevPortal. |
| `production` | `https://api.buni.kcbgroup.com` | **Not published by KCB.** Determined by probing the live gateway: `/token` returns the same OAuth client-validation response as UAT, and `/mm/api/request/1.0.0/stkpush` returns the same WSO2 gateway response. |

Because the production host is inferred rather than documented, confirm it with
KCB before moving real money, or pin it yourself with `base_url`. Setting
`KCB_BUNI_ENVIRONMENT=production` is a deliberate opt-in; every other environment
name still fails fast with a `ConfigurationException`.

### Paystack Config

| Key | Description |
| --- | --- |
| `base_url` | Paystack API base URL. Defaults to `https://api.paystack.co`. Paystack uses your API key to determine test vs live mode. |
| `secret_key` | Paystack secret key used as the bearer token. |
| `public_key` | Optional Paystack public key. Used by `requeryCapitecPayCharge()`, which Paystack authorises with the public key rather than the secret key. |
| `throw_on_business_error` | Throw `BusinessException` when Paystack answers HTTP 200 with `"status": false`. Defaults to `false`. See [Business-Level Failures](#business-level-failures). |
| `endpoints` | Optional endpoint-path overrides keyed by `PaystackClient::ENDPOINTS`. |
| `webhook_security.secret_key` | HMAC secret for inbound webhooks. Defaults to `PAYSTACK_SECRET_KEY`. |
| `webhook_security.trusted_ips` | Paystack webhook source IP allowlist. Defaults to the documented Paystack list. Override in published config or with comma-separated `PAYSTACK_WEBHOOK_TRUSTED_IPS`. |
| `webhook_security.enforce_ip_whitelist` | Reject webhooks from non-allowlisted IPs when using `verifyRequest()` or the middleware. Defaults to `false`; enable it after Laravel trusted proxy handling is configured for your deployment. |
| `webhook_security.verify_signature` | Verify `x-paystack-signature` HMAC signatures when using `verifyRequest()` or the middleware. Defaults to `true`. |

## Usage

### M-PESA

```php
use NoriaLabs\Payments\MpesaClient;

$mpesa = app(MpesaClient::class);

$timestamp = MpesaClient::buildTimestamp();

$response = $mpesa->stkPush([
    'BusinessShortCode' => '174379',
    'Password' => MpesaClient::buildStkPassword('174379', config('services.mpesa.passkey'), $timestamp),
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => 1,
    'PartyA' => '254700000000',
    'PartyB' => '174379',
    'PhoneNumber' => '254700000000',
    'CallBackURL' => 'https://example.com/mpesa/callback',
    'AccountReference' => 'INV-001',
    'TransactionDesc' => 'Payment',
]);
```

Kenyan mobile numbers supplied to M-PESA payment requests are normalized automatically. Daraja
`stkPush()`, SasaPay `requestPayment()` / `waasRequestPayment()`, and KCB Buni `mpesaStkPush()`
accept local `07XXXXXXXX` and `01XXXXXXXX` numbers, unprefixed numbers, and formatted `+254`
numbers. They are sent to the provider as `2547XXXXXXXX` or `2541XXXXXXXX`.

### SasaPay v1 C2B

```php
use NoriaLabs\Payments\SasaPayClient;

$sasapay = app(SasaPayClient::class);

$response = $sasapay->requestPayment([
    'MerchantCode' => '600980',
    'NetworkCode' => '63902',
    'Currency' => 'KES',
    'Amount' => '1.00',
    'PhoneNumber' => '254700000080',
    'AccountReference' => '12345678',
    'TransactionDesc' => 'Request Payment',
    'CallBackURL' => 'https://example.com/sasapay/callback',
]);
```

### SasaPay WAAS Request Payment

```php
use NoriaLabs\Payments\SasaPayClient;

$sasapay = app(SasaPayClient::class);

$response = $sasapay->waasRequestPayment([
    'merchantReference' => 'TOPUP-001',
    'merchantCode' => '600980',
    'networkCode' => '63902',
    'mobileNumber' => '254700000080',
    'receiverAccountNumber' => '600980-1',
    'amount' => '50',
    'transactionFee' => '0',
    'currencyCode' => 'KES',
    'transactionDesc' => 'Wallet topup',
    'callbackUrl' => 'https://example.com/sasapay/waas/callback',
]);
```

### KCB Buni M-PESA Express

```php
use NoriaLabs\Payments\KcbBuniClient;

$buni = app(KcbBuniClient::class);

$response = $buni->mpesaStkPush([
    'phoneNumber' => '254722000000',
    'amount' => '10',
    'invoiceNumber' => '1234567-INV001',
    'sharedShortCode' => true,
    'orgShortCode' => '',
    'orgPassKey' => '',
    'callbackUrl' => 'https://example.com/kcb-buni/stk-callback',
    'transactionDescription' => 'school fees',
], messageId: '232323_KCBOrg_8875661561', routeCode: '207');
```

The payload is validated against the constraints in Buni's own M-PESA Express
schema before it is sent — `transactionDescription` is capped at 13 characters,
`phoneNumber` must be `2547XXXXXXXX`, `messageId` at 32, and so on. See
[KCB Buni Payload Validation](#kcb-buni-payload-validation).

> **`callbackUrl` is not the IPN endpoint.** The route you pass here receives
> Safaricom's Daraja-shaped STK result (`Body.stkCallback`) relayed by KCB. It is
> **unsigned** — it carries no `Signature` header — so do **not** put the
> `VerifyKcbBuniIpn` middleware on it. Instant Payment Notifications are a
> separate, signed API on separate routes; see
> [KCB Buni IPN Security](#kcb-buni-ipn-security).

### KCB Buni Funds Transfer

```php
use NoriaLabs\Payments\KcbBuniClient;

$buni = app(KcbBuniClient::class);

$response = $buni->transferFunds([
    'companyCode' => 'KE0010001',
    'transactionType' => 'IF',
    'debitAccountNumber' => '37890012',
    'creditAccountNumber' => '909099090',
    'debitAmount' => 10,
    'paymentDetails' => 'fee payment',
    'transactionReference' => 'MHSGS7883',
    'currency' => 'KES',
    'beneficiaryDetails' => 'JOHN DOE',
]);
```

### Paystack Initialize Transaction

```php
use NoriaLabs\Payments\PaystackClient;

$paystack = app(PaystackClient::class);

$response = $paystack->initializeTransaction([
    'email' => 'customer@example.com',
    'amount' => 10000,
    'currency' => 'NGN',
    'reference' => 'INV-001',
    'callback_url' => 'https://example.com/paystack/callback',
]);
```

### Paystack Webhook Security

Paystack documents two webhook-origin controls:

- verify the `x-paystack-signature` header with HMAC-SHA512 over the raw request body
- verify the request source IP against the Paystack allowlist

Use the middleware on your webhook route:

```php
use NoriaLabs\Payments\Http\Middleware\VerifyPaystackWebhook;

Route::post('/paystack/webhook', PaystackWebhookController::class)
    ->middleware(VerifyPaystackWebhook::class);
```

Or verify manually:

```php
use Illuminate\Http\Request;
use NoriaLabs\Payments\PaystackWebhookVerifier;

public function __invoke(Request $request, PaystackWebhookVerifier $verifier)
{
    if (! $verifier->verifyRequest($request, enforceIpWhitelist: true, verifySignature: true)) {
        abort(403);
    }

    // Process the already-authenticated webhook payload.
}
```

### SasaPay Callback Security

> **SasaPay publishes no callback authentication scheme.** Searching its
> documentation for `signature`, `hmac`, `sha256`, `checksum` and `allowlist`
> returns nothing for the C2B callback, the IPN, the B2C/B2B result callback or
> the status-query callback. The two controls below are *ours*, not SasaPay's,
> and the trusted-IP list is observed rather than published.

The controls this package provides:

- **HMAC-SHA512 over a canonical message.** Enable this only if SasaPay has
  separately issued your account this scheme. Against an account that signs
  nothing it rejects every legitimate callback, so `verify_signature` should be
  turned off unless you have confirmed otherwise.
- **Source-IP allowlisting** against the observed SasaPay egress addresses.

A stronger control, and the one to prefer when SasaPay has issued you nothing:
`CallBackURL` is supplied per request, so append a capability token of your own
at initiation (`?token=…`) and check it on receipt. It proves the caller knew a
secret you only ever sent to SasaPay. It authenticates the *caller*, not the
*body*, so still confirm with a transaction status query before settling.

The signed message format, when the HMAC scheme is in use, is:

```text
sasapay_transaction_code-merchant_code-account_number-payment_reference-amount
```

The HMAC secret defaults to the Merchant API Client ID unless you override `payments.sasapay.callback_security.secret_key`. Signature verification and IP allowlisting are independent controls:

- `SASAPAY_CALLBACK_VERIFY_SIGNATURE=true|false`
- `SASAPAY_CALLBACK_ENFORCE_IP_WHITELIST=true|false`
- `SASAPAY_CALLBACK_TRUSTED_IPS=203.0.113.10,198.51.100.25`

Use the middleware on your callback route:

```php
use NoriaLabs\Payments\Http\Middleware\VerifySasaPayCallback;

Route::post('/sasapay/callback', SasaPayCallbackController::class)
    ->middleware(VerifySasaPayCallback::class);
```

Or verify manually:

```php
use Illuminate\Http\Request;
use NoriaLabs\Payments\SasaPayCallbackVerifier;

public function __invoke(Request $request, SasaPayCallbackVerifier $verifier)
{
    if (! $verifier->verifyRequest($request, enforceIpWhitelist: true, verifySignature: true)) {
        abort(403);
    }

    // Process the already-authenticated callback payload.
}
```

The verifier reads callback fields by the names that appear in SasaPay's published callback examples; it does not infer case variants or names that are not in them. The aliases below are genuinely useful regardless of the signature question, because SasaPay names the same value differently across products. The signature is read from a `sasapay_signature` field; if your application receives it through another transport, pass it explicitly to `verify($payload, signature: $value)`.

Canonical callback fields, and the aliases SasaPay uses for them across C2B, IPN, checkout/card, B2C, B2B, remittance, utilities, WAAS, and bulk status payloads:

| Canonical field | Documented aliases |
| --- | --- |
| `sasapay_transaction_code` | `sasapay_transaction_code`, `TransactionCode`, `TransID`, `SasaPayTransactionCode` |
| `sasapay_transaction_id` | `SasaPayTransactionID` |
| `third_party_transaction_id` | `ThirdPartyTransID`, `ThirdPartyTransactionCode`, `third_party_transaction_code` |
| `merchant_code` | `merchant_code`, `merchantCode`, `MerchantCode`, `BusinessShortCode` |
| `account_number` | `account_number`, `accountNumber`, `AccountNumber`, `CustomerMobile`, `MSISDN`, `RecipientAccountNumber`, `BeneficiaryAccountNumber`, `SenderAccountNumber`, `ContactNumber`, `DestinationAccountNumber` |
| `checkout_request_id` | `CheckoutRequestID`, `CheckoutRequestId`, `checkoutRequestId` |
| `payment_reference` | `payment_reference`, `BillRefNumber`, `InvoiceNumber`, `MerchantReference`, `merchantReference`, `MerchantTransactionReference`, `TransactionReference`, `transactionReference`, `PaymentRequestID`, `MerchantRequestID`, `bulk_payment_reference` |
| `amount` | `amount`, `TransactionAmount`, `TransAmount`, `AmountPaid`, `PaidAmount`, `Amount`, `RequestedAmount` |

`third_party_transaction_id` and `sasapay_transaction_id` are intentionally not treated as SasaPay transaction-code aliases. Amount formatting is part of the signature input, so keep the exact provider value, for example `1500.00`.

### KCB Buni IPN Security

Buni's `InstantPaymentNotification` API (context `/ipn`, version `1.0.0`) defines
three inbound routes that KCB calls on **your** host. They do not share one
contract:

| Buni route | `Signature` header | Body | Response you must return |
| --- | --- | --- | --- |
| `/till-notification` | **required** | nested `header` + `requestPayload.additionalData.notificationData` | `header` + `responsePayload.transactionInfo` |
| `/account-notification` | **required** | flat transaction fields | `transactionID`, `statusCode`, `statusMessage` |
| `/validation` | **not sent** | `requestId`, `customerReference`, `organizationReference` | the above plus optional `CustomerName`, `billAmount`, `currency`, `billType`, `creditAccountIdentifier` |

The `Signature` header is a base64 SHA256withRSA signature of the raw request
body, signed by KCB and verified with the KCB public key.

Because `/validation` carries no signature, the middleware takes per-route
options. Applying the default middleware there would reject every validation
request with HTTP 403:

```php
use NoriaLabs\Payments\Http\Middleware\VerifyKcbBuniIpn;

// Signed notification routes — default configuration.
Route::post('/kcb-buni/ipn/till', TillNotificationController::class)
    ->middleware(VerifyKcbBuniIpn::class);

Route::post('/kcb-buni/ipn/account', AccountNotificationController::class)
    ->middleware(VerifyKcbBuniIpn::class);

// Unsigned validation route — opt out of signature verification.
Route::post('/kcb-buni/ipn/validation', ValidationController::class)
    ->middleware('kcb-buni.ipn:no-signature');
```

The package registers the aliases `kcb-buni.ipn`, `sasapay.callback` and
`paystack.webhook`. All three accept the same options, and several can be
combined:

| Option | Effect |
| --- | --- |
| `signature` | Force signature verification on for this route. |
| `no-signature` | Skip signature verification for this route. |
| `ip` | Enforce the configured source-IP allowlist for this route. |
| `no-ip` | Skip the source-IP allowlist for this route. |

```php
Route::post('/kcb-buni/ipn/validation', ValidationController::class)
    ->middleware('kcb-buni.ipn:no-signature,ip');
```

An unrecognised option throws `ConfigurationException` rather than being silently
ignored.

Or verify manually:

```php
use Illuminate\Http\Request;
use NoriaLabs\Payments\KcbBuniIpnVerifier;

public function __invoke(Request $request, KcbBuniIpnVerifier $verifier)
{
    if (! $verifier->verifyRequest($request, enforceIpWhitelist: true, verifySignature: true)) {
        abort(403);
    }

    // Process the already-authenticated IPN payload.
}
```

#### Reading IPN payloads and building acknowledgements

`NoriaLabs\Payments\Support\KcbBuniIpn` models the three contracts so you do not
have to hand-assemble the nested till envelope or remember which acknowledgement
shape each route expects.

```php
use NoriaLabs\Payments\Support\KcbBuniIpn;

public function __invoke(Request $request)
{
    $payload = $request->all();

    return match (KcbBuniIpn::type($payload)) {
        // Nested envelope: transaction fields live under
        // requestPayload.additionalData.notificationData
        KcbBuniIpn::TYPE_TILL => response()->json(
            KcbBuniIpn::tillAcknowledgement(
                $payload,
                $this->recordTill(KcbBuniIpn::tillNotificationData($payload)),
            )
        ),

        // Flat envelope
        KcbBuniIpn::TYPE_ACCOUNT => response()->json(
            KcbBuniIpn::accountAcknowledgement($this->recordAccount($payload))
        ),

        KcbBuniIpn::TYPE_VALIDATION => response()->json(
            KcbBuniIpn::validationResponse('LOCAL-1', [
                'CustomerName' => 'JOHN DOE',
                'billAmount' => '1500.00',
                'currency' => 'KES',
            ])
        ),

        default => response()->json(
            KcbBuniIpn::rejection($payload, '1', 'Unrecognised notification'), 400
        ),
    };
}
```

`KcbBuniIpn::rejection()` mirrors whichever contract the inbound payload belongs
to, so a rejection is shaped correctly without a second branch.

## Manager Usage

Use the manager when you want custom runtime clients instead of the default container bindings:

```php
use NoriaLabs\Payments\PaymentsManager;

$manager = app(PaymentsManager::class);

$sasapay = $manager->sasapay([
    'environment' => 'production',
    'base_url' => 'https://your-confirmed-production-host/api/v1',
    'waas_base_url' => 'https://your-confirmed-production-host/api/v2/waas',
    'default_headers' => [
        'X-App-Name' => 'billing',
    ],
]);

$paystack = $manager->paystack([
    'secret_key' => config('services.paystack.secret_key'),
]);

$buni = $manager->kcbBuni([
    'base_url' => 'https://your-confirmed-buni-production-host',
    'consumer_key' => config('services.kcb_buni.consumer_key'),
    'consumer_secret' => config('services.kcb_buni.consumer_secret'),
]);
```

## KCB Buni Coverage

The KCB Buni client keeps Buni field names exactly as documented. It does not translate `callbackUrl`, `transactionReference`, or nested request payloads. For `mpesaStkPush()`, it normalizes Kenyan `phoneNumber` values to `2547XXXXXXXX` or `2541XXXXXXXX` and string-casts `amount` by default, matching the Buni M-PESA Express schema. Set `amount_normalization` to `none` when you need to preserve raw JSON number types. Note that `debitAmount` on `transferFunds()` is never stringified — Buni's Funds Transfer schema types it as a JSON number.

### KCB Buni Payload Validation

`mpesaStkPush()` and `transferFunds()` validate their payloads against the
constraints published in Buni's own OpenAPI documents before the request leaves
your app, so an over-long or malformed field fails locally with a precise message
instead of a generic gateway rejection. The rule sets are public constants —
`KcbBuniClient::MPESA_STK_PUSH_RULES`, `::MPESA_STK_PUSH_HEADER_RULES` and
`::FUNDS_TRANSFER_RULES`.

```php
use NoriaLabs\Payments\Exceptions\ValidationException;

try {
    $buni->mpesaStkPush($payload, messageId: $id, routeCode: '207');
} catch (ValidationException $e) {
    $e->getMessage();  // "KCB Buni M-PESA Express payload is invalid: [transactionDescription] must not exceed 13 characters, got 21."
    $e->errors;        // ['[transactionDescription] must not exceed 13 characters, got 21.']
}
```

Required fields are checked for presence rather than non-emptiness where Buni's
schema allows a blank value — `orgShortCode` and `orgPassKey` must be present but
may be `''` when `sharedShortCode` is `true`.

Turn it off globally with `payments.kcb_buni.validate_payloads = false`, or per
call when you need to send something the published schema does not describe:

```php
$buni->transferFunds($payload, ['validate' => false]);
$buni->transferFunds($payload, new RequestOptions(validate: false));
```

Validation never applies to `authorizedPost()` / `authorizedGet()`.

### KCB Buni Endpoint Provenance

Not every endpoint below comes from the same source, which matters when you
subscribe an application on the Buni DevPortal:

- **In the DevPortal API catalog** (`MpesaExpressAPIService`, `FundsTransferAPIService`, `VENDINGGATEWAYAPIS`, `KCBKEeTIMSKraServices`, `KCBBIIpsP2PTransferStatusInquiry`, `InstantPaymentNotification`) — subscribable, with published OpenAPI documents.
- **Live on the gateway but absent from the catalog**: `queryCoreTransactionStatus()` and `queryTransactionDetails()`. Both resolve on the UAT gateway with the methods this package uses (`POST` and `GET` respectively, each returning HTTP 401 without credentials), but they are not listed as subscribable API products. A standard Buni application subscription may not grant your token access to them — ask KCB to enable them.
- **Not deployed on UAT**: `p2pTransferStatusInquiry()` is in the catalog but its UAT gateway route returns 404. Exercise it against the environment KCB enables for your subscription.

The DevPortal publishes UAT endpoint URLs only; see
[KCB Buni hosts](#kcb-buni-hosts) for how the production host was established.

### KCB Buni Auth and IPN

| API | Behavior |
| --- | --- |
| `KcbBuniClient::getAccessToken()` | Returns a Buni OAuth token from `POST /token` or a custom token-provider value. |
| `KcbBuniIpnVerifier::verify()` | Validates raw body/signature/IP checks according to configured or per-call toggles. |
| `KcbBuniIpnVerifier::verifyRequest()` | Extracts the raw body, `Signature` header, and IP from a Laravel request. |
| `KcbBuniIpnVerifier::isTrustedIp()` | Checks the configured KCB Buni IPN IP allowlist. |
| `KcbBuniIpnVerifier::verifiesSignature()` | Shows whether signature verification is enabled by default. |
| `VerifyKcbBuniIpn` middleware | Rejects invalid Laravel IPN requests with HTTP 403. Accepts per-route `signature` / `no-signature` / `ip` / `no-ip` options. |
| `KcbBuniIpn::type()` | Identifies which of the three inbound IPN contracts a payload belongs to. |
| `KcbBuniIpn::tillNotificationData()` | Reads `requestPayload.additionalData.notificationData` from a till notification. |
| `KcbBuniIpn::tillAcknowledgement()` | Builds the `/till-notification` acknowledgement, echoing the inbound `messageID`. |
| `KcbBuniIpn::accountAcknowledgement()` | Builds the `/account-notification` acknowledgement. |
| `KcbBuniIpn::validationResponse()` | Builds the `/validation` response, including the optional bill fields. |
| `KcbBuniIpn::rejection()` | Builds a non-zero-status response shaped to match the inbound contract. |

### KCB Buni Outbound APIs

| Method | Endpoint | Source |
| --- | --- | --- |
| `mpesaStkPush($payload, $messageId)` | `POST /mm/api/request/1.0.0/stkpush` | DevPortal catalog |
| `transferFunds()` | `POST /fundstransfer/1.0.0/api/v1/transfer` | DevPortal catalog |
| `vendingValidateRequest()` | `POST /kcb/vendingGateway/v1/1.0.0/api/validate-request` | DevPortal catalog |
| `vendingVendorConfirmation()` | `POST /kcb/vendingGateway/v1/1.0.0/api/vendor-confirmation` | DevPortal catalog |
| `vendingTransactionStatus()` | `POST /kcb/vendingGateway/v1/1.0.0/api/query/transaction-status` | DevPortal catalog |
| `etimsRequest($path, $payload, $method)` | `/kcb/ke/kra/etims/1.0.0/{path}` | DevPortal catalog (wildcard resource) |
| `p2pTransferStatusInquiry($payload, $path)` | `POST /kcb/bi/ips/p2p/transfer/status/inquiry/1.0.0/{path}` | DevPortal catalog (not deployed on UAT) |
| `queryCoreTransactionStatus()` | `POST /v1/core/t24/querytransaction/1.0.0/api/transactioninfo` | Live gateway, not in catalog |
| `queryTransactionDetails($identifier)` | `GET /kcb/transaction/query/1.0.0/api/v1/payment/query/{identifier}` | Live gateway, not in catalog |

`KCBKEeTIMSKraServices` and `KCBBIIpsP2PTransferStatusInquiry` publish a single
wildcard resource with no request schema, so the concrete operation path and body
come from the integration pack KCB issues with your subscription:

```php
$buni->etimsRequest('api/v1/sales', ['invoiceNumber' => 'INV-1']);
$buni->etimsRequest('api/v1/sales/INV-1', method: 'GET', query: ['detail' => 'full']);

$buni->p2pTransferStatusInquiry(['transactionReference' => 'FT000262556']);
```

The `InstantPaymentNotification` API is inbound-only — KCB calls your host. See
[KCB Buni IPN Security](#kcb-buni-ipn-security).

### KCB Buni Raw Authorized Helpers

Use these when KCB exposes an endpoint before this package has a named high-level method. They use the same token providers, retries, timeout handling, hooks, and exception mapping as the named APIs and do not normalize or rewrite payloads.

| Method | Behavior |
| --- | --- |
| `authorizedPost($path, $payload = [])` | POST on the configured Buni base URL with bearer auth. |
| `authorizedGet($path, $query = [])` | GET on the configured Buni base URL with bearer auth. |

## Paystack Coverage

Paystack uses one API host for test and live mode: `https://api.paystack.co`. The secret key determines the environment. The client keeps Paystack field names and amount units exactly as Paystack documents them; pass amounts in provider subunits.

### Paystack Auth and Webhooks

| API | Behavior |
| --- | --- |
| `PaystackClient::getAccessToken()` | Returns the configured secret key or custom token-provider value. |
| `PaystackClient::authorizedPost()/authorizedGet()/authorizedPut()/authorizedDelete()` | Raw bearer-authenticated helpers for Paystack endpoints not yet represented by named methods. Payloads and query keys are preserved. |
| `PaystackWebhookVerifier::expectedSignature()` | Computes the HMAC-SHA512 hex digest over the raw request body. |
| `PaystackWebhookVerifier::verify()` | Validates raw body/signature/IP checks according to configured or per-call toggles. |
| `PaystackWebhookVerifier::verifyRequest()` | Extracts the raw body, `x-paystack-signature`, and IP from a Laravel request. |
| `PaystackWebhookVerifier::isTrustedIp()` | Checks the documented Paystack webhook IP allowlist. |
| `PaystackWebhookVerifier::trustedIps()` | Returns the active Paystack webhook IP allowlist. |
| `PaystackWebhookVerifier::verifiesSignature()` | Shows whether signature verification is enabled by default. |
| `VerifyPaystackWebhook` middleware | Rejects invalid Laravel webhook requests with HTTP 403 according to webhook-security config. |

### Paystack Transactions, Charge, Bulk Charge, Subaccounts, Splits

| Method | Endpoint |
| --- | --- |
| `initializeTransaction()` | `POST /transaction/initialize` |
| `chargeAuthorization()` | `POST /transaction/charge_authorization` |
| `partialDebit()` | `POST /transaction/partial_debit` |
| `verifyTransaction($reference)` | `GET /transaction/verify/{reference}` |
| `listTransactions()` | `GET /transaction` |
| `fetchTransaction($id)` | `GET /transaction/{id}` |
| `transactionTimeline($id)` | `GET /transaction/timeline/{id}` |
| `transactionTotals()` | `GET /transaction/totals` |
| `exportTransactions()` | `GET /transaction/export` |
| `createCharge()` | `POST /charge` |
| `submitChargePin()` | `POST /charge/submit_pin` |
| `submitChargeOtp()` | `POST /charge/submit_otp` |
| `submitChargePhone()` | `POST /charge/submit_phone` |
| `submitChargeBirthday()` | `POST /charge/submit_birthday` |
| `submitChargeAddress()` | `POST /charge/submit_address` |
| `checkPendingCharge($reference)` | `GET /charge/{reference}` |
| `requeryCapitecPayCharge($reference, $publicKey)` | `POST /capitec-pay/requery/{ref}` — authorised with your **public** key |
| `initiateBulkCharge()` | `POST /bulkcharge` |
| `listBulkChargeBatches()` | `GET /bulkcharge` |
| `fetchBulkChargeBatch($code)` | `GET /bulkcharge/{code}` |
| `fetchBulkChargeBatchCharges($code)` | `GET /bulkcharge/{code}/charges` |
| `pauseBulkChargeBatch($code)` | `GET /bulkcharge/pause/{code}` |
| `resumeBulkChargeBatch($code)` | `GET /bulkcharge/resume/{code}` |
| `createSubaccount()` | `POST /subaccount` |
| `listSubaccounts()` | `GET /subaccount` |
| `fetchSubaccount($code)` | `GET /subaccount/{code}` |
| `updateSubaccount($code)` | `PUT /subaccount/{code}` |
| `createSplit()` | `POST /split` |
| `listSplits()` | `GET /split` |
| `fetchSplit($id)` | `GET /split/{id}` |
| `updateSplit($id)` | `PUT /split/{id}` |
| `addSubaccountToSplit($id)` | `POST /split/{id}/subaccount/add` |
| `removeSubaccountFromSplit($id)` | `POST /split/{id}/subaccount/remove` |

### Paystack Terminals

| Method | Endpoint |
| --- | --- |
| `sendTerminalEvent($id)` | `POST /terminal/{id}/event` |
| `fetchTerminalEventStatus($terminalId, $eventId)` | `GET /terminal/{terminal_id}/event/{event_id}` |
| `fetchTerminalStatus($terminalId)` | `GET /terminal/{terminal_id}/presence` |
| `listTerminals()` | `GET /terminal` |
| `fetchTerminal($terminalId)` | `GET /terminal/{terminal_id}` |
| `updateTerminal($terminalId)` | `PUT /terminal/{terminal_id}` |
| `commissionTerminal()` | `POST /terminal/commission_device` |
| `decommissionTerminal()` | `POST /terminal/decommission_device` |
| `createVirtualTerminal()` | `POST /virtual_terminal` |
| `listVirtualTerminals()` | `GET /virtual_terminal` |
| `fetchVirtualTerminal($code)` | `GET /virtual_terminal/{code}` |
| `updateVirtualTerminal($code)` | `PUT /virtual_terminal/{code}` |
| `deactivateVirtualTerminal($code)` | `PUT /virtual_terminal/{code}/deactivate` |
| `assignVirtualTerminalDestination($code)` | `POST /virtual_terminal/{code}/destination/assign` |
| `unassignVirtualTerminalDestination($code)` | `POST /virtual_terminal/{code}/destination/unassign` |
| `addVirtualTerminalSplitCode($code)` | `PUT /virtual_terminal/{code}/split_code` |
| `removeVirtualTerminalSplitCode($code)` | `DELETE /virtual_terminal/{code}/split_code` |

### Paystack Customers, Direct Debit, Dedicated Accounts, Apple Pay

| Method | Endpoint |
| --- | --- |
| `createCustomer()` | `POST /customer` |
| `listCustomers()` | `GET /customer` |
| `fetchCustomer($code)` | `GET /customer/{code}` |
| `updateCustomer($code)` | `PUT /customer/{code}` |
| `setCustomerRiskAction()` | `POST /customer/set_risk_action` |
| `validateCustomer($code)` | `POST /customer/{code}/identification` |
| `initializeAuthorization()` | `POST /customer/authorization/initialize` |
| `verifyAuthorization($reference)` | `GET /customer/authorization/verify/{reference}` |
| `deactivateAuthorization()` | `POST /customer/authorization/deactivate` |
| `initializeDirectDebit($id)` | `POST /customer/{id}/initialize-direct-debit` |
| `customerDirectDebitActivationCharge($id)` | `PUT /customer/{id}/directdebit-activation-charge` |
| `customerDirectDebitMandateAuthorizations($id)` | `GET /customer/{id}/directdebit-mandate-authorizations` |
| `triggerDirectDebitActivationCharge()` | `PUT /directdebit/activation-charge` |
| `listDirectDebitMandateAuthorizations()` | `GET /directdebit/mandate-authorizations` |
| `createDedicatedAccount()` | `POST /dedicated_account` |
| `listDedicatedAccounts()` | `GET /dedicated_account` |
| `assignDedicatedAccount()` | `POST /dedicated_account/assign` |
| `fetchDedicatedAccount($id)` | `GET /dedicated_account/{id}` |
| `deactivateDedicatedAccount($id)` | `DELETE /dedicated_account/{id}` |
| `requeryDedicatedAccount()` | `GET /dedicated_account/requery` |
| `splitDedicatedAccountTransaction()` | `POST /dedicated_account/split` |
| `removeSplitFromDedicatedAccount()` | `DELETE /dedicated_account/split` |
| `fetchDedicatedAccountProviders()` | `GET /dedicated_account/available_providers` |
| `registerApplePayDomain()` | `POST /apple-pay/domain` |
| `listApplePayDomains()` | `GET /apple-pay/domain` |
| `unregisterApplePayDomain()` | `DELETE /apple-pay/domain` |

### Paystack Plans, Subscriptions, Transfers

| Method | Endpoint |
| --- | --- |
| `createPlan()` | `POST /plan` |
| `listPlans()` | `GET /plan` |
| `fetchPlan($code)` | `GET /plan/{code}` |
| `updatePlan($code)` | `PUT /plan/{code}` |
| `createSubscription()` | `POST /subscription` |
| `listSubscriptions()` | `GET /subscription` |
| `fetchSubscription($code)` | `GET /subscription/{code}` |
| `disableSubscription()` | `POST /subscription/disable` |
| `enableSubscription()` | `POST /subscription/enable` |
| `subscriptionManagementLink($code)` | `GET /subscription/{code}/manage/link` |
| `sendSubscriptionManagementEmail($code)` | `POST /subscription/{code}/manage/email` |
| `createTransferRecipient()` | `POST /transferrecipient` |
| `listTransferRecipients()` | `GET /transferrecipient` |
| `bulkCreateTransferRecipients()` | `POST /transferrecipient/bulk` |
| `fetchTransferRecipient($code)` | `GET /transferrecipient/{code}` |
| `updateTransferRecipient($code)` | `PUT /transferrecipient/{code}` |
| `deleteTransferRecipient($code)` | `DELETE /transferrecipient/{code}` |
| `initiateTransfer()` | `POST /transfer` |
| `listTransfers()` | `GET /transfer` |
| `finalizeTransfer()` | `POST /transfer/finalize_transfer` |
| `initiateBulkTransfer()` | `POST /transfer/bulk` |
| `fetchTransfer($code)` | `GET /transfer/{code}` |
| `verifyTransfer($reference)` | `GET /transfer/verify/{reference}` |
| `exportTransfers()` | `GET /transfer/export` |
| `resendTransferOtp()` | `POST /transfer/resend_otp` |
| `disableTransferOtp()` | `POST /transfer/disable_otp` |
| `finalizeDisableTransferOtp()` | `POST /transfer/disable_otp_finalize` |
| `enableTransferOtp()` | `POST /transfer/enable_otp` |
| `balance()` | `GET /balance` |
| `balanceLedger()` | `GET /balance/ledger` |

### Paystack Payment Requests, Products, Storefronts, Orders, Pages

| Method | Endpoint |
| --- | --- |
| `createPaymentRequest()` | `POST /paymentrequest` |
| `listPaymentRequests()` | `GET /paymentrequest` |
| `fetchPaymentRequest($id)` | `GET /paymentrequest/{id}` |
| `updatePaymentRequest($id)` | `PUT /paymentrequest/{id}` |
| `verifyPaymentRequest($id)` | `GET /paymentrequest/verify/{id}` |
| `notifyPaymentRequest($id)` | `POST /paymentrequest/notify/{id}` |
| `paymentRequestTotals()` | `GET /paymentrequest/totals` |
| `finalizePaymentRequest($id)` | `POST /paymentrequest/finalize/{id}` |
| `archivePaymentRequest($id)` | `POST /paymentrequest/archive/{id}` |
| `createProduct()` | `POST /product` |
| `listProducts()` | `GET /product` |
| `fetchProduct($id)` | `GET /product/{id}` |
| `updateProduct($id)` | `PUT /product/{id}` |
| `deleteProduct($id)` | `DELETE /product/{id}` |
| `createStorefront()` | `POST /storefront` |
| `listStorefronts()` | `GET /storefront` |
| `fetchStorefront($id)` | `GET /storefront/{id}` |
| `updateStorefront($id)` | `PUT /storefront/{id}` |
| `deleteStorefront($id)` | `DELETE /storefront/{id}` |
| `verifyStorefront($slug)` | `GET /storefront/verify/{slug}` |
| `listStorefrontOrders($id)` | `GET /storefront/{id}/order` |
| `addStorefrontProducts($id)` | `POST /storefront/{id}/product` |
| `listStorefrontProducts($id)` | `GET /storefront/{id}/product` |
| `publishStorefront($id)` | `POST /storefront/{id}/publish` |
| `duplicateStorefront($id)` | `POST /storefront/{id}/duplicate` |
| `createOrder()` | `POST /order` |
| `listOrders()` | `GET /order` |
| `fetchOrder($id)` | `GET /order/{id}` |
| `listProductOrders($id)` | `GET /order/product/{id}` |
| `validateOrder($code)` | `GET /order/{code}/validate` |
| `createPage()` | `POST /page` |
| `listPages()` | `GET /page` |
| `fetchPage($id)` | `GET /page/{id}` |
| `updatePage($id)` | `PUT /page/{id}` |
| `checkSlugAvailability($slug)` | `GET /page/check_slug_availability/{slug}` |
| `addProductsToPage($id)` | `POST /page/{id}/product` |

### Paystack Settlements, Integration, Refunds, Disputes, Verification

| Method | Endpoint |
| --- | --- |
| `listSettlements()` | `GET /settlement` |
| `listSettlementTransactions($id)` | `GET /settlement/{id}/transactions` |
| `fetchPaymentSessionTimeout()` | `GET /integration/payment_session_timeout` |
| `updatePaymentSessionTimeout()` | `PUT /integration/payment_session_timeout` |
| `createRefund()` | `POST /refund` |
| `listRefunds()` | `GET /refund` |
| `retryRefundWithCustomerDetails($id)` | `POST /refund/retry_with_customer_details/{id}` |
| `fetchRefund($id)` | `GET /refund/{id}` |
| `listDisputes()` | `GET /dispute` |
| `fetchDispute($id)` | `GET /dispute/{id}` |
| `updateDispute($id)` | `PUT /dispute/{id}` |
| `disputeUploadUrl($id)` | `GET /dispute/{id}/upload_url` |
| `exportDisputes()` | `GET /dispute/export` |
| `transactionDisputes($id)` | `GET /dispute/transaction/{id}` |
| `resolveDispute($id)` | `PUT /dispute/{id}/resolve` |
| `addDisputeEvidence($id)` | `POST /dispute/{id}/evidence` |
| `listBanks()` | `GET /bank` |
| `resolveBankAccount()` | `GET /bank/resolve` |
| `validateBankAccount()` | `POST /bank/validate` |
| `resolveCardBin($bin)` | `GET /decision/bin/{bin}` |
| `listCountries()` | `GET /country` |
| `listAddressVerificationStates()` | `GET /address_verification/states` |


## SasaPay Coverage

The SasaPay client intentionally keeps provider field names as documented. It accepts raw arrays and does not translate `MerchantCode` to `merchantCode`, `CallBackURL` to `callbackUrl`, or similar. Pass the exact payload expected by the specific SasaPay endpoint.

Methods return parsed JSON or text responses. HTTP 4xx/5xx responses throw `ApiException`. A SasaPay business failure returned with HTTP 200 is returned to you as the provider sent it, because SasaPay uses fields such as `status`, `responseCode`, `ResponseCode`, and `statusCode` differently across endpoints.

SasaPay amount fields are string-cast by default for backward compatibility. Pass `new RequestOptions(amountNormalization: 'none')` or set `sasapay.amount_normalization` to `none` when an endpoint requires numeric JSON values.

### SasaPay Callback Security

| API | Behavior |
| --- | --- |
| `SasaPayCallbackVerifier::message()` | Builds the documented `sasapay_transaction_code-merchant_code-account_number-payment_reference-amount` message. |
| `SasaPayCallbackVerifier::expectedSignature()` | Computes the HMAC-SHA512 hex digest. |
| `SasaPayCallbackVerifier::verify()` | Validates payload/signature/IP checks according to configured or per-call toggles. |
| `SasaPayCallbackVerifier::verifyRequest()` | Extracts payload, signature, and IP from a Laravel request, then applies the configured or per-call toggles. |
| `SasaPayCallbackVerifier::callbackValue()` | Reads a canonical callback field from any supported alias, for example `third_party_transaction_id`. |
| `SasaPayCallbackVerifier::fieldAliases()` | Returns supported aliases for a canonical callback field. |
| `SasaPayCallbackVerifier::isTrustedIp()` | Checks the documented SasaPay callback IP allowlist. |
| `SasaPayCallbackVerifier::verifiesSignature()` | Shows whether signature verification is enabled by default. |
| `VerifySasaPayCallback` middleware | Rejects invalid Laravel callback requests with HTTP 403 according to callback-security config. |

### SasaPay v1 Auth

| Method | Endpoint |
| --- | --- |
| `getAccessToken()` | `GET /api/v1/auth/token/?grant_type=client_credentials` |

### SasaPay v1 Payments

| Method | Endpoint |
| --- | --- |
| `requestPayment()` | `POST /payments/request-payment/` |
| `processPayment()` | `POST /payments/process-payment/` |
| `b2cPayment()` | `POST /payments/b2c/` |
| `b2bPayment()` | `POST /payments/b2b/` |
| `cardPayment()` | `POST /payments/card-payments/` |
| `preApprovedPayment()` | `POST /payments/approved/` |
| `remittancePayment()` | `POST /remittances/remittance-payments/` |
| `businessToBeneficiary()` | `POST /payments/b2c/beneficiary/` |
| `registerIpnUrl()` | `POST /payments/register-ipn-url/` |
| `lipaFare()` | `POST /payments/lipa-fare/` |
| `bulkPayment()` | `POST /payments/bulk-payments/` |
| `bulkPaymentStatus()` | `POST /payments/bulk-payments/status/` |

### SasaPay v1 Transactions, Balances, Validation, Utilities

| Method | Endpoint |
| --- | --- |
| `accountValidation()` | `POST /accounts/account-validation/` |
| `internalFundMovement()` | `POST /transactions/fund-movement/` |
| `transactionStatus()` | `POST /transactions/status/` |
| `transactionStatusQuery()` | `POST /transactions/status-query/` |
| `transactionStatusExact()` | `POST /transactions/status/` |
| `requestPaymentStatus()` | `POST /payments/request-payment/status/` |
| `merchantBalance($merchantCode)` | `GET /payments/check-balance/?MerchantCode=...` |
| `verifyTransaction()` | `POST /transactions/verify/` |
| `transactions()` | `GET /transactions/` |
| `channelCodes()` | `GET /payments/channel-codes/` |
| `utilityPayment()` | `POST /utilities/` |
| `utilityBillQuery()` | `POST /utilities/bill-query` |

### SasaPay v1 Dealer Onboarding

| Method | Endpoint |
| --- | --- |
| `dealerBusinessTypes()` | `GET /accounts/business-types/` |
| `dealerCountries()` | `GET /accounts/countries/` |
| `dealerSubCounties($countyId)` | `GET /accounts/sub-counties/?county_id=...` |
| `dealerIndustries()` | `GET /accounts/industries/` |
| `availableBillNumber()` | `GET /accounts/available-bill-number/` |
| `merchantOnboarding()` | `POST /accounts/merchant-onboarding/` |

### SasaPay WAAS Auth

| Method | Endpoint |
| --- | --- |
| `getWaasAccessToken()` | `GET /api/v2/waas/auth/token/?grant_type=client_credentials` |

### SasaPay WAAS Onboarding and Customers

| Method | Endpoint |
| --- | --- |
| `waasPersonalOnboarding()` | `POST /personal-onboarding/` |
| `waasConfirmPersonalOnboarding()` | `POST /personal-onboarding/confirmation/` |
| `waasPersonalKyc()` | `POST /personal-onboarding/kyc/`; sends multipart when files are provided. |
| `waasBusinessOnboarding()` | `POST /business-onboarding/` |
| `waasConfirmBusinessOnboarding()` | `POST /business-onboarding/confirmation/` |
| `waasBusinessKyc()` | `POST /business-onboarding/kyc/`; sends multipart when files are provided. |
| `waasCustomers()` | `GET /customers/` |
| `waasCustomerDetails()` | `POST /customer-details/` |
| `waasUpdateCustomerDetails()` | `POST /customer-details/update/` |
| `waasCreateSubWallet()` | `POST /sub-wallets/` |

### SasaPay WAAS Payments

| Method | Endpoint |
| --- | --- |
| `waasRequestPayment()` | `POST /payments/request-payment/` |
| `waasProcessPayment()` | `POST /payments/process-payment/` |
| `waasMerchantTransfer()` | `POST /payments/merchant-transfers/` |
| `waasSendMoney()` | `POST /payments/send-money/` |
| `waasPayBill()` | `POST /payments/pay-bills/` |
| `waasBulkPayment()` | Alias of v1 `bulkPayment()` because the current WAAS docs point to `/api/v1/payments/bulk-payments/`. |
| `waasBulkPaymentStatus()` | Alias of v1 `bulkPaymentStatus()` for the same reason. |

### SasaPay WAAS Transactions, Balances, Lookups, Utilities

| Method | Endpoint |
| --- | --- |
| `waasTransactions()` | `GET /transactions/` |
| `waasTransactionStatus()` | `POST /transactions/status/` |
| `waasVerifyTransaction()` | `POST /transactions/verify/` |
| `waasMerchantBalance($merchantCode)` | `GET /merchant-balances/?merchantCode=...` |
| `waasChannelCodes()` | `GET /channel-codes/` |
| `waasCountries()` | `GET /countries/` |
| `waasCountrySubRegions($callingCode)` | `GET /countries/sub-regions/?callingCode=...` |
| `waasIndustries()` | `GET /industries/` |
| `waasSubIndustries($industryId)` | `GET /sub-industries/?industryId=...` |
| `waasBusinessTypes()` | `GET /business-types/` |
| `waasProducts()` | `GET /products/` |
| `waasNearestAgents($longitude, $latitude)` | `GET /nearest-agent/?Longitude=...&Latitude=...` |
| `waasUtilityPayment()` | `POST /utilities/` |
| `waasUtilityBillQuery()` | Alias of v1 `utilityBillQuery()` because the current WAAS utilities docs point bill query to `/api/v1/utilities/bill-query`. |

### SasaPay Raw Authorized Helpers

Use these when SasaPay exposes an endpoint before this package has a named high-level method. They use the same token providers, retries, timeout handling, hooks, and exception mapping as the named APIs and do not normalize or rewrite payloads.

| Method | Behavior |
| --- | --- |
| `authorizedPost($path, $payload = [])` | POST on the v1 base URL with bearer auth. |
| `authorizedGet($path, $query = [])` | GET on the v1 base URL with bearer auth. |
| `authorizedMultipartPost($path, $fields = [], $files = [])` | Multipart POST on the v1 base URL with bearer auth. |
| `waasAuthorizedPost($path, $payload = [])` | POST on the WAAS base URL with bearer auth. |
| `waasAuthorizedGet($path, $query = [])` | GET on the WAAS base URL with bearer auth. |
| `waasAuthorizedMultipartPost($path, $fields = [], $files = [])` | Multipart POST on the WAAS base URL with bearer auth. |

The SasaPay docs also contain status-code pages. Those pages document static values, not API endpoints, so they are not represented as HTTP methods.

## M-PESA Coverage

| Method | Endpoint |
| --- | --- |
| `getAccessToken()` | `GET /oauth/v1/generate?grant_type=client_credentials` |
| `stkPush()` | `POST /mpesa/stkpush/v1/processrequest` |
| `stkPushQuery()` | `POST /mpesa/stkpushquery/v1/query` |
| `registerC2BUrls()` | `POST /mpesa/c2b/v2/registerurl` by default for backward compatibility; pass `version: 'v1'` for the current documented C2B Register URL path. |
| `registerC2BUrlsV1()` | `POST /mpesa/c2b/v1/registerurl` |
| `c2bSimulate()` | `POST /mpesa/c2b/v1/simulate` |
| `b2cPayment()` | `POST /mpesa/b2c/{version}/paymentrequest` |
| `b2cPaymentV3()` | `POST /mpesa/b2c/v3/paymentrequest` |
| `b2bPayment()` | `POST /mpesa/b2b/v1/paymentrequest` |
| `b2cAccountTopUp()` | `POST /mpesa/b2b/v1/paymentrequest` with `CommandID=BusinessPayToBulk` unless already supplied. |
| `businessPayBill()` | `POST /mpesa/b2b/v1/paymentrequest` with `CommandID=BusinessPayBill` unless already supplied. |
| `businessBuyGoods()` | `POST /mpesa/b2b/v1/paymentrequest` with `CommandID=BusinessBuyGoods` unless already supplied. |
| `b2bExpressCheckout()` | `POST /v1/ussdpush/get-msisdn` |
| `reversal()` | `POST /mpesa/reversal/v1/request` |
| `transactionStatus()` | `POST /mpesa/transactionstatus/v1/query` |
| `accountBalance()` | `POST /mpesa/accountbalance/v1/query` |
| `generateQrCode()` | `POST /mpesa/qrcode/v1/generate` |
| `taxRemittance()` | `POST /mpesa/b2b/v1/remittax` |
| `billManagerOptIn()` | `POST /v1/billmanager-invoice/optin` |
| `billManagerSingleInvoice()` | `POST /v1/billmanager-invoice/single-invoicing` |
| `billManagerBulkInvoicing()` | `POST /v1/billmanager-invoice/bulk-invoicing` |
| `billManagerReconciliation()` | `POST /v1/billmanager-invoice/reconciliation` |
| `billManagerCancelSingleInvoice()` | `POST /v1/billmanager-invoice/cancel-single-invoice` |
| `billManagerCancelBulkInvoice()` | `POST /v1/billmanager-invoice/cancel-bulk-invoice` |
| `billManagerUpdateOnboardingDetails()` | `POST /v1/billmanager-invoice/change-optin-details` |
| `billManagerUpdateSingleInvoice()` | `POST /v1/billmanager-invoice/change-invoice` |
| `billManagerUpdateBulkInvoice()` | `POST /v1/billmanager-invoice/change-invoices` |
| `ratibaStandingOrder()` | `POST /standingorder/v1/createStandingOrderExternal` |
| `registerPullTransactions()` | `POST /pulltransactions/v1/register` |
| `pullTransactions()` | `POST /pulltransactions/v1/query` |

M-PESA methods intentionally preserve Daraja field names. The client only string-casts `Amount` or `amount` when present by default and, for the named B2B product helpers, adds the documented `CommandID` only when the caller has not supplied one. Set `amount_normalization` to `none` globally or per request when you need to preserve raw JSON number types.

Endpoint overrides are available when a Daraja tenant is provisioned with a different path:

```php
use NoriaLabs\Payments\Facades\Payments;

$mpesa = Payments::mpesa([
    'endpoints' => [
        'b2c_payment' => '/mpesa/b2c/v3/paymentrequest',
    ],
]);
```

Helper methods:

- `MpesaClient::buildTimestamp(?DateTimeInterface $dateTime = null): string`
- `MpesaClient::buildStkPassword(string $businessShortCode, string $passkey, string $timestamp): string`
- `MpesaClient::authorizedPost(string $path, array $payload = [], array|RequestOptions|null $options = null): mixed`
- `MpesaClient::authorizedGet(string $path, array $query = [], array|RequestOptions|null $options = null): mixed`

## Request Options

Every provider method accepts either:

- `null`
- `array`
- `NoriaLabs\Payments\Support\RequestOptions`

Fields:

| Field | Description |
| --- | --- |
| `headers` | Request-specific headers. |
| `timeout_seconds` | Request-specific timeout. |
| `retry` | Request-specific retry policy or `false` to disable retries. |
| `access_token` | Explicit bearer token override. |
| `force_token_refresh` | Forces the next token lookup to refresh. |
| `amount_normalization` | Per-request override for providers that normalize amount fields by default. Use `none` to preserve raw numeric `Amount` and `amount` values for SasaPay, M-PESA, and KCB Buni M-PESA Express calls. |
| `validate` | Per-request override for [KCB Buni payload validation](#kcb-buni-payload-validation). `false` sends the payload through untouched. |
| `throw_on_business_error` | Per-request override for [business-level failure handling](#business-level-failures). `true` throws `BusinessException` when the provider reports a failure inside an HTTP 200 body. |

Example:

```php
use NoriaLabs\Payments\Support\RequestOptions;
use NoriaLabs\Payments\Support\RetryPolicy;

$response = $sasapay->requestPayment($payload, new RequestOptions(
    headers: ['X-Request-Id' => 'abc-123'],
    timeoutSeconds: 15.0,
    retry: new RetryPolicy(
        maxAttempts: 2,
        retryMethods: ['POST'],
        retryOnStatuses: [500, 502, 503, 504],
        baseDelaySeconds: 0.25,
    ),
));
```

## Custom Token Providers

Implement `NoriaLabs\Payments\Contracts\AccessTokenProvider`:

```php
use NoriaLabs\Payments\Contracts\AccessTokenProvider;

class MyTokenProvider implements AccessTokenProvider
{
    public function getAccessToken(bool $forceRefresh = false): string
    {
        return 'my-token';
    }
}
```

Inject via the manager:

```php
$client = app(\NoriaLabs\Payments\PaymentsManager::class)->paystack(
    overrides: [],
    tokenProvider: new MyTokenProvider(),
);
```

When you supply a custom token provider:

- the package does not call a provider OAuth token endpoint or use a configured static secret for that client
- provider credentials become optional for that runtime client
- you own token freshness

## Request Hooks

Use `NoriaLabs\Payments\Support\Hooks` to observe and mutate transport behavior:

```php
use NoriaLabs\Payments\Support\Hooks;

$hooks = new Hooks(
    beforeRequest: function ($context): void {
        $context->headers['X-Correlation-Id'] = 'corr-123';
    },
    afterResponse: function ($context): void {
        logger()->info('payment response', [
            'url' => $context->url,
            'status' => $context->response->status(),
        ]);
    },
    onError: function ($context): void {
        logger()->error('payment error', [
            'url' => $context->url,
            'error' => $context->error->getMessage(),
        ]);
    },
);
```

Hook contexts expose:

- `BeforeRequestContext`: `url`, `path`, `method`, `headers`, `body`, `attempt`
- `AfterResponseContext`: plus `response`, `responseBody`
- `ErrorContext`: plus `error`, optional `response`, optional `responseBody`

## Error Classes

The package throws:

- `NoriaLabs\Payments\Exceptions\ConfigurationException`
- `NoriaLabs\Payments\Exceptions\AuthenticationException`
- `NoriaLabs\Payments\Exceptions\TimeoutException`
- `NoriaLabs\Payments\Exceptions\NetworkException`
- `NoriaLabs\Payments\Exceptions\ApiException`
- `NoriaLabs\Payments\Exceptions\ValidationException`
- `NoriaLabs\Payments\Exceptions\BusinessException`

All of them extend `PaymentsException` and expose a machine-readable `codeName`.

`ApiException` includes:

- `statusCode`
- `responseBody`
- `details`

`ValidationException` includes `errors`, the list of individual field failures.

`BusinessException` includes `provider`, `statusCode` and `responseBody`.

## Business-Level Failures

**Every provider in this package can answer HTTP 200 while reporting a failure in
the body.** `HttpTransport` only maps transport and HTTP-status failures, so by
default a rejected transfer is returned to you as an ordinary response array:

| Provider | Failure marker |
| --- | --- |
| KCB Buni | `header.statusCode` other than `"0"`, or `response.ResponseCode` other than `0` |
| M-PESA Daraja | an `errorCode` field, or `ResponseCode` / `ResultCode` other than `0` |
| SasaPay | `"status": false` |
| Paystack | `"status": false` |

The default is unchanged for backwards compatibility — you must opt in. There are
two ways.

**Inspect explicitly.** Each client exposes static readers that never throw and
return `null` for a shape they do not recognise, so a new response format is
never misread as a failure:

```php
$response = $buni->transferFunds($payload);

if (KcbBuniClient::succeeded($response) === false) {
    report(new RuntimeException(KcbBuniClient::statusMessage($response)));
}
```

`MpesaClient`, `SasaPayClient` and `PaystackClient` expose the same `succeeded()`
and `statusMessage()` readers (`statusCode()` too, except on Paystack).

**Or let the client throw.** Enable it per provider, or per request:

```php
// config/payments.php
'kcb_buni' => ['throw_on_business_error' => true],

// or per call
$buni->transferFunds($payload, ['throw_on_business_error' => true]);
```

```php
use NoriaLabs\Payments\Exceptions\BusinessException;

try {
    $buni->transferFunds($payload);
} catch (BusinessException $e) {
    $e->provider;      // 'kcb_buni'
    $e->statusCode;    // '1'
    $e->responseBody;  // the full decoded body
}
```

Enabling this is strongly recommended for anything that moves money. It is
off by default only so that upgrading the package cannot silently change how an
existing integration handles responses.

## Async Settlement

For all supported providers, most important operations are asynchronous.

Treat the immediate response as accepted, queued, or processing unless the provider explicitly says otherwise. Final status usually arrives by callback, IPN, transaction-status query, or verification endpoint.

For SasaPay callbacks, KCB Buni IPNs, and Paystack webhooks, verify the provider signature before mutating local order, wallet, or ledger state.

## Development Quality

Run the same quality gates locally that CI enforces:

```bash
composer quality

# Or run each gate separately:
composer format:test
composer analyse
composer test:coverage
```

Use `composer format` to apply Laravel Pint formatting.

## M-PESA Documentation References

The M-PESA Daraja endpoint matrix was aligned with Safaricom's public Daraja portal and official Safaricom SDK references:

- https://developer.safaricom.co.ke/
- https://developer.safaricom.co.ke/apis
- https://developer.safaricom.co.ke/c2b/apis/post/registerurl
- https://developer.safaricom.co.ke/lipa-na-m-pesa-online/apis/post/stkpush/v1/processrequest
- https://github.com/safaricom/mpesa-node-library

## KCB Buni Documentation References

The KCB Buni endpoint matrix, field constraints, IPN contracts and host list were
re-verified on August 18, 2026 against the DevPortal API catalog (which returns
exactly six APIs), the OpenAPI document of each, and live probes of the UAT and
production gateways.

- https://buni.kcbgroup.com/discover-apis
- https://sandbox.buni.kcbgroup.com/api/am/devportal/v3/apis

Per-API OpenAPI documents (`.../api/am/devportal/v3/apis/{id}/swagger`):

| API | Context | ID |
| --- | --- | --- |
| `MpesaExpressAPIService` | `/mm/api/request` | `6396efd5-de10-4b04-adec-128f54349614` |
| `FundsTransferAPIService` | `/fundstransfer` | `372552ef-5ebd-4921-9a0d-2f3b1da8cb86` |
| `VENDINGGATEWAYAPIS` | `/kcb/vendingGateway/v1` | `906bbc54-0f39-4271-a912-290346764be7` |
| `InstantPaymentNotification` | `/ipn` | `01b3ebbd-1452-4baf-a068-2913ecd3af73` |
| `KCBKEeTIMSKraServices` | `/kcb/ke/kra/etims` | `d7a2b6ad-8047-4da6-9f90-54e50c9f1d8e` |
| `KCBBIIpsP2PTransferStatusInquiry` | `/kcb/bi/ips/p2p/transfer/status/inquiry` | `7cf05ab8-5b43-42a0-ba9d-951bc5eb128e` |

Findings that are **not** in KCB's published documentation and were established
by probing the live gateway are labelled as such in
[KCB Buni hosts](#kcb-buni-hosts) and
[KCB Buni Endpoint Provenance](#kcb-buni-endpoint-provenance). Confirm them with
KCB before relying on them in production.

## SasaPay Documentation References

The SasaPay endpoint matrix was aligned with the public SasaPay docs:

- https://docs.sasapay.app/docs/introduction/
- https://docs.sasapay.app/docs/authentication/
- https://docs.sasapay.app/docs/customerTobusiness/
- https://docs.sasapay.app/docs/b2c/
- https://docs.sasapay.app/docs/b2b/
- https://docs.sasapay.app/docs/channel-codes/
- https://docs.sasapay.app/docs/waas/introduction/
- https://docs.sasapay.app/docs/waas/auth/
- https://docs.sasapay.app/docs/waas/getchannelcodes/
- https://developer.sasapay.app/docs/apis/callback-security?country=ke

## Paystack Documentation References

The Paystack endpoint matrix and webhook security behavior were aligned with Paystack's public developer docs and official OpenAPI repository:

- https://paystack.com/docs/api
- https://paystack.com/docs/payments/webhooks/
- https://github.com/PaystackOSS/openapi
