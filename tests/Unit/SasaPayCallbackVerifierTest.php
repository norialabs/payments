<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\Http\Middleware\VerifySasaPayCallback;
use NoriaLabs\Payments\SasaPayCallbackVerifier;

function sasapayCallbackPayload(array $overrides = []): array
{
    return array_replace([
        'TransactionCode' => 'SPEJ4T4SFEWE',
        'MerchantCode' => '600980',
        'AccountNumber' => '254712345678',
        'BillRefNumber' => 'INV20251001',
        'TransAmount' => '1500.00',
    ], $overrides);
}

function sasapayCallbackSignature(array $payload, string $secretKey = 'Merchant API Client ID'): string
{
    return hash_hmac(
        'sha512',
        'SPEJ4T4SFEWE-600980-254712345678-INV20251001-1500.00',
        $secretKey,
    );
}

it('builds and verifies the documented sasapay callback signature message', function (): void {
    $payload = sasapayCallbackPayload();
    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID');

    expect($verifier->message($payload))
        ->toBe('SPEJ4T4SFEWE-600980-254712345678-INV20251001-1500.00')
        ->and($verifier->verify($payload, sasapayCallbackSignature($payload)))
        ->toBeTrue();
});

it('extracts the documented callback signature payload field', function (): void {
    $payload = sasapayCallbackPayload();
    $signature = sasapayCallbackSignature($payload);
    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID');

    expect($verifier->verify($payload + ['sasapay_signature' => $signature]))
        ->toBeTrue()
        ->and($verifier->verify($payload + ['signature' => $signature]))
        ->toBeFalse()
        ->and($verifier->verify($payload, signature: $signature))
        ->toBeTrue();

    $request = Request::create(
        uri: '/sasapay/callback',
        method: 'POST',
        parameters: $payload + ['sasapay_signature' => $signature],
        server: [
            'REMOTE_ADDR' => '47.129.43.141',
        ],
    );

    expect($verifier->verifyRequest($request))->toBeTrue();
});

it('rejects invalid, missing, or tampered callback signatures', function (): void {
    $payload = sasapayCallbackPayload();
    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID');

    expect($verifier->verify($payload, 'invalid-signature'))
        ->toBeFalse()
        ->and($verifier->verify($payload))
        ->toBeFalse()
        ->and($verifier->verify(array_replace($payload, ['TransAmount' => '1501.00']), sasapayCallbackSignature($payload)))
        ->toBeFalse()
        ->and($verifier->verify(['TransactionCode' => 'SPEJ4T4SFEWE'], sasapayCallbackSignature($payload)))
        ->toBeFalse();
});

it('can enforce the documented sasapay callback source ip whitelist', function (): void {
    $payload = sasapayCallbackPayload();
    $signature = sasapayCallbackSignature($payload);
    $verifier = SasaPayCallbackVerifier::make([
        'client_id' => 'Merchant API Client ID',
        'callback_security' => [
            'enforce_ip_whitelist' => true,
        ],
    ]);

    expect($verifier->verify($payload, $signature, '47.129.43.141'))
        ->toBeTrue()
        ->and($verifier->verify($payload, $signature, '8.8.8.8'))
        ->toBeFalse()
        ->and($verifier->isTrustedIp('155.12.30.58'))
        ->toBeTrue()
        ->and($verifier->isTrustedIp('8.8.8.8'))
        ->toBeFalse();
});

it('allows overriding the sasapay callback trusted ip allowlist', function (): void {
    $payload = sasapayCallbackPayload();
    $signature = sasapayCallbackSignature($payload);
    $verifier = SasaPayCallbackVerifier::make([
        'client_id' => 'Merchant API Client ID',
        'callback_security' => [
            'trusted_ips' => '203.0.113.10, 198.51.100.25',
            'enforce_ip_whitelist' => true,
        ],
    ]);

    expect($verifier->trustedIps())
        ->toBe(['203.0.113.10', '198.51.100.25'])
        ->and($verifier->verify($payload, $signature, '203.0.113.10'))
        ->toBeTrue()
        ->and($verifier->verify($payload, $signature, '47.129.43.141'))
        ->toBeFalse();
});

it('keeps third party transaction ids separate from the sasapay transaction code', function (): void {
    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID');

    $payload = sasapayCallbackPayload([
        'ThirdPartyTransID' => 'MPESA123456',
    ]);

    expect(SasaPayCallbackVerifier::fieldAliases('sasapay_transaction_code'))
        ->toBe(['sasapay_transaction_code', 'TransactionCode', 'TransID', 'SasaPayTransactionCode'])
        ->and(SasaPayCallbackVerifier::fieldAliases('third_party_transaction_id'))
        ->toBe(['ThirdPartyTransID', 'ThirdPartyTransactionCode', 'third_party_transaction_code'])
        ->and($verifier->callbackValue($payload, 'third_party_transaction_id'))
        ->toBe('MPESA123456')
        ->and($verifier->callbackValue([
            'MobileNetworkTransactionCode' => 'UNDOCUMENTED',
            'MpesaReceiptNumber' => 'UNDOCUMENTED',
        ], 'third_party_transaction_id'))
        ->toBeNull()
        ->and($verifier->message($payload))
        ->toBe('SPEJ4T4SFEWE-600980-254712345678-INV20251001-1500.00');

    expect($verifier->callbackValue([
        'ThirdPartyTransactionCode' => 'AIRTEL123',
    ], 'third_party_transaction_id'))->toBe('AIRTEL123');

    expect($verifier->callbackValue([
        'third_party_transaction_code' => 'MPEF00023DFFG',
    ], 'third_party_transaction_id'))->toBe('MPEF00023DFFG');

    $payloadWithoutSasaPayCode = sasapayCallbackPayload([
        'ThirdPartyTransID' => 'MPESA123456',
    ]);

    unset($payloadWithoutSasaPayCode['TransactionCode']);

    expect($verifier->callbackValue($payloadWithoutSasaPayCode, 'third_party_transaction_id'))
        ->toBe('MPESA123456')
        ->and($verifier->verify($payloadWithoutSasaPayCode, sasapayCallbackSignature($payloadWithoutSasaPayCode)))
        ->toBeFalse();
});

it('covers documented sasapay callback aliases across products', function (): void {
    expect(SasaPayCallbackVerifier::fieldAliases('merchant_code'))
        ->toBe(['merchant_code', 'merchantCode', 'MerchantCode', 'BusinessShortCode'])
        ->and(SasaPayCallbackVerifier::fieldAliases('account_number'))
        ->toBe([
            'account_number',
            'accountNumber',
            'AccountNumber',
            'CustomerMobile',
            'MSISDN',
            'RecipientAccountNumber',
            'BeneficiaryAccountNumber',
            'SenderAccountNumber',
            'ContactNumber',
            'DestinationAccountNumber',
        ])
        ->and(SasaPayCallbackVerifier::fieldAliases('payment_reference'))
        ->toBe([
            'payment_reference',
            'BillRefNumber',
            'InvoiceNumber',
            'MerchantReference',
            'merchantReference',
            'MerchantTransactionReference',
            'TransactionReference',
            'transactionReference',
            'PaymentRequestID',
            'MerchantRequestID',
            'bulk_payment_reference',
        ])
        ->and(SasaPayCallbackVerifier::fieldAliases('amount'))
        ->toBe([
            'amount',
            'TransactionAmount',
            'TransAmount',
            'AmountPaid',
            'PaidAmount',
            'Amount',
            'RequestedAmount',
        ])
        ->and(SasaPayCallbackVerifier::fieldAliases('sasapay_transaction_id'))
        ->toBe(['SasaPayTransactionID'])
        ->and(SasaPayCallbackVerifier::fieldAliases('checkout_request_id'))
        ->toBe(['CheckoutRequestID', 'CheckoutRequestId', 'checkoutRequestId']);
});

it('builds signatures from documented callback field names beyond c2b', function (): void {
    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID');

    $b2cPayload = [
        'SasaPayTransactionCode' => 'CRVSUVGIRP',
        'MerchantCode' => '600980',
        'RecipientAccountNumber' => '254712345678',
        'MerchantRequestID' => 'QUEUED-REQUEST-001',
        'MerchantTransactionReference' => 'SALARY-001',
        'TransactionAmount' => '1500.00',
        'RequestedAmount' => '2000.00',
        'ThirdPartyTransactionCode' => 'SQ33424',
        'SasaPayTransactionID' => 'PR52',
    ];

    $ipnPayload = [
        'TransID' => 'CDVISAIHD',
        'MerchantCode' => '600980',
        'MSISDN' => '254700000005',
        'InvoiceNumber' => 'INV-278-RID-6754',
        'TransAmount' => '10.00',
    ];

    $bulkDetailPayload = [
        'sasapay_transaction_code' => 'SPE011383838X',
        'merchant_code' => '600980',
        'account_number' => '254712345678',
        'payment_reference' => 'PAY_REF_001',
        'amount' => '1500.00',
        'third_party_transaction_code' => 'MPEF00023DFFG',
    ];

    expect($verifier->message($b2cPayload))
        ->toBe('CRVSUVGIRP-600980-254712345678-SALARY-001-1500.00')
        ->and($verifier->callbackValue($b2cPayload, 'third_party_transaction_id'))
        ->toBe('SQ33424')
        ->and($verifier->callbackValue($b2cPayload, 'sasapay_transaction_id'))
        ->toBe('PR52')
        ->and($verifier->message($ipnPayload))
        ->toBe('CDVISAIHD-600980-254700000005-INV-278-RID-6754-10.00')
        ->and($verifier->message($bulkDetailPayload))
        ->toBe('SPE011383838X-600980-254712345678-PAY_REF_001-1500.00')
        ->and($verifier->callbackValue($bulkDetailPayload, 'third_party_transaction_id'))
        ->toBe('MPEF00023DFFG');
});

it('supports observed sasapay disbursement callback field names', function (): void {
    $payload = [
        'MerchantRequestID' => '01kq9fadpwx5krbwkf6kfg5trz',
        'CheckoutRequestID' => '1b5a4a38-2276-46c4-83ad-97d0d3a4a3d0',
        'ResultCode' => '0',
        'ResultDesc' => 'Transaction processed successfully.',
        'MerchantCode' => '796279',
        'TransactionAmount' => '4800.00',
        'TransactionCharge' => '0.00',
        'MerchantTransactionReference' => '01kq9fadpwx5krbwkf6kfg5trz',
        'TransactionDate' => '20260428072303',
        'SourceChannel' => 'SasaPay',
        'DestinationChannel' => 'M-PESA',
        'SasaPayTransactionCode' => 'SWEJ7QKM8T2SUWD',
        'LinkedTransactionCode' => null,
        'SasaPayTransactionID' => 'PR64683569',
        'ThirdPartyTransactionCode' => 'UDSSBAR9HS',
        'RecipientAccountNumber' => '777001',
        'RecipientName' => 'SMEP MICROFINANCE BANK LTD',
        'MerchantAccountBalance' => '6766.03',
    ];

    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID');

    expect($verifier->callbackValue($payload, 'sasapay_transaction_code'))
        ->toBe('SWEJ7QKM8T2SUWD')
        ->and($verifier->callbackValue($payload, 'sasapay_transaction_id'))
        ->toBe('PR64683569')
        ->and($verifier->callbackValue($payload, 'third_party_transaction_id'))
        ->toBe('UDSSBAR9HS')
        ->and($verifier->message($payload))
        ->toBe('SWEJ7QKM8T2SUWD-796279-777001-01kq9fadpwx5krbwkf6kfg5trz-4800.00');
});

it('can disable callback signature verification independently from ip allowlisting', function (): void {
    $payload = [
        'ThirdPartyTransID' => 'MPESA123456',
    ];

    $signatureOnlyDisabled = SasaPayCallbackVerifier::make([
        'callback_security' => [
            'verify_signature' => false,
        ],
    ]);

    expect($signatureOnlyDisabled->verifiesSignature())
        ->toBeFalse()
        ->and($signatureOnlyDisabled->verify($payload))
        ->toBeTrue();

    $ipOnlyVerifier = SasaPayCallbackVerifier::make([
        'callback_security' => [
            'trusted_ips' => ['203.0.113.10'],
            'enforce_ip_whitelist' => true,
            'verify_signature' => false,
        ],
    ]);

    expect($ipOnlyVerifier->verify($payload, ip: '203.0.113.10'))
        ->toBeTrue()
        ->and($ipOnlyVerifier->verify($payload, ip: '47.129.43.141'))
        ->toBeFalse();
});

it('uses configured sasapay client id as the default callback hmac secret', function (): void {
    config()->set('payments.sasapay.client_id', 'Merchant API Client ID');

    $verifier = app(SasaPayCallbackVerifier::class);
    $payload = sasapayCallbackPayload(['sasapay_signature' => sasapayCallbackSignature(sasapayCallbackPayload())]);

    expect($verifier->verify($payload))->toBeTrue();
});

it('requires a sasapay callback secret or client id before validating signatures', function (): void {
    $verifier = SasaPayCallbackVerifier::make([
        'callback_security' => [
            'secret_key' => null,
        ],
    ]);

    $verifier->expectedSignature(sasapayCallbackPayload());
})->throws(ConfigurationException::class);

it('rejects invalid callbacks through the laravel middleware', function (): void {
    config()->set('payments.sasapay.client_id', 'Merchant API Client ID');

    Route::post('/sasapay/callback', fn () => response('ok'))
        ->middleware(VerifySasaPayCallback::class);

    $this->post('/sasapay/callback', sasapayCallbackPayload([
        'sasapay_signature' => 'invalid-signature',
    ]))->assertForbidden();
});

it('lets the laravel middleware opt out of callback signature verification', function (): void {
    config()->set('payments.sasapay.callback_security.verify_signature', false);
    config()->set('payments.sasapay.callback_security.enforce_ip_whitelist', false);

    Route::post('/sasapay/signature-disabled-callback', fn () => response('ok'))
        ->middleware(VerifySasaPayCallback::class);

    $this->post('/sasapay/signature-disabled-callback', [
        'ThirdPartyTransID' => 'MPESA123456',
    ])->assertOk();
});

it('accepts valid callbacks through the laravel middleware', function (): void {
    config()->set('payments.sasapay.client_id', 'Merchant API Client ID');

    Route::post('/sasapay/valid-callback', fn () => response('ok'))
        ->middleware(VerifySasaPayCallback::class);

    $payload = sasapayCallbackPayload();

    $this->post('/sasapay/valid-callback', $payload + [
        'sasapay_signature' => sasapayCallbackSignature($payload),
    ])->assertOk();
});

it('returns null for a non-scalar signature payload value', function (): void {
    $verifier = new SasaPayCallbackVerifier('secret');

    expect($verifier->signatureFromPayload(['sasapay_signature' => ['not-scalar']]))->toBeNull();
});

it('trims surrounding whitespace from canonical callback values', function (): void {
    $verifier = new SasaPayCallbackVerifier('secret');

    expect($verifier->callbackValue(['TransactionCode' => ' SPE123 '], 'sasapay_transaction_code'))->toBe('SPE123');
});

it('throws InvalidArgumentException when querying an unknown canonical field', function (): void {
    $verifier = new SasaPayCallbackVerifier('secret');

    expect(fn () => SasaPayCallbackVerifier::fieldAliases('unknown'))
        ->toThrow(InvalidArgumentException::class, 'Unknown SasaPay callback field [unknown].');

    expect(fn () => $verifier->callbackValue([], 'unknown'))
        ->toThrow(InvalidArgumentException::class, 'Unknown SasaPay callback field [unknown].');
});

it('throws InvalidArgumentException when a callback field value is non-scalar', function (): void {
    $verifier = new SasaPayCallbackVerifier('secret');

    expect(fn () => $verifier->callbackValue(['TransactionCode' => ['array']], 'sasapay_transaction_code'))
        ->toThrow(InvalidArgumentException::class, 'SasaPay callback field [sasapay_transaction_code] must be scalar.');
});

it('throws InvalidArgumentException when a callback field value is empty after trimming', function (): void {
    $verifier = new SasaPayCallbackVerifier('secret');

    expect(fn () => $verifier->callbackValue(['TransactionCode' => '   '], 'sasapay_transaction_code'))
        ->toThrow(InvalidArgumentException::class, 'SasaPay callback field [sasapay_transaction_code] cannot be empty.');
});

it('falls back to the documented IP allowlist when trusted_ips is not array-like', function (): void {
    $verifier = SasaPayCallbackVerifier::make([
        'client_id' => 'secret',
        'callback_security' => [
            'trusted_ips' => 123,
            'enforce_ip_whitelist' => true,
            'verify_signature' => true,
        ],
    ]);

    expect($verifier->trustedIps())->toBe(SasaPayCallbackVerifier::TRUSTED_CALLBACK_IPS)
        ->and($verifier->enforcesIpWhitelist())->toBeTrue()
        ->and($verifier->verifiesSignature())->toBeTrue();
});

it('coerces stringy boolean callback_security flags', function (): void {
    $verifier = SasaPayCallbackVerifier::make([
        'client_id' => 'secret',
        'callback_security' => [
            'enforce_ip_whitelist' => 'true',
            'verify_signature' => 'false',
        ],
    ]);

    expect($verifier->enforcesIpWhitelist())->toBeTrue()
        ->and($verifier->verifiesSignature())->toBeFalse();
});

it('exposes a complete field alias map via the static accessor', function (): void {
    expect(SasaPayCallbackVerifier::fieldAliases())->toHaveKey('sasapay_transaction_code');
});

it('returns false from isTrustedIp for null and empty inputs', function (): void {
    $verifier = new SasaPayCallbackVerifier('secret');

    expect($verifier->isTrustedIp(null))->toBeFalse()
        ->and($verifier->isTrustedIp(''))->toBeFalse();
});

it('reads the signature from the header sasapay sends', function (): void {
    $payload = sasapayCallbackPayload();
    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID');

    $request = fn (string $signature): Request => Request::create(
        uri: '/sasapay/callback',
        method: 'POST',
        parameters: $payload,
        server: ['HTTP_X_SASAPAY_SIGNATURE' => $signature],
    );

    expect($verifier->verifyRequest($request(sasapayCallbackSignature($payload))))->toBeTrue()
        ->and($verifier->verifyRequest($request(strtoupper(sasapayCallbackSignature($payload)))))->toBeTrue()
        ->and($verifier->verifyRequest($request('forged')))->toBeFalse();
});

it('prefers the header over a payload signature', function (): void {
    $payload = sasapayCallbackPayload();
    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID');

    $request = Request::create(
        uri: '/sasapay/callback',
        method: 'POST',
        parameters: $payload + ['sasapay_signature' => sasapayCallbackSignature($payload)],
        server: ['HTTP_X_SASAPAY_SIGNATURE' => 'forged'],
    );

    expect($verifier->verifyRequest($request))->toBeFalse();
});

it('rejects a request with no signature anywhere', function (): void {
    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID');

    expect($verifier->verifyRequest(Request::create('/sasapay/callback', 'POST', sasapayCallbackPayload())))->toBeFalse();
});

it('accepts the signature header through the laravel middleware', function (): void {
    config()->set('payments.sasapay.client_id', 'Merchant API Client ID');

    Route::post('/sasapay/header-callback', fn () => response('ok'))
        ->middleware(VerifySasaPayCallback::class);

    $payload = sasapayCallbackPayload();

    $this->post('/sasapay/header-callback', $payload, ['X-SasaPay-Signature' => sasapayCallbackSignature($payload)])->assertOk();
    $this->post('/sasapay/header-callback', $payload, ['X-SasaPay-Signature' => 'forged'])->assertForbidden();
    $this->post('/sasapay/header-callback', $payload)->assertForbidden();
});

$signedBy = fn (string $message): string => hash_hmac('sha512', $message, 'Merchant API Client ID');

it('verifies each callback shape sasapay documents', function (array $payload, string $message, ?string $merchantCode) use ($signedBy): void {
    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID', merchantCode: $merchantCode);

    expect($verifier->verify($payload, $signedBy($message)))->toBeTrue()
        ->and($verifier->verify($payload, $signedBy($message.'0')))->toBeFalse();
})->with([
    'c2b result, signed to two decimals' => [
        ['MerchantRequestID' => '12345678', 'ResultCode' => '0', 'TransAmount' => '1.000000', 'RequestedAmount' => '1.00', 'BillRefNumber' => '12345678', 'CustomerMobile' => '254712345678', 'TransactionCode' => 'SPEJ47VZTWXC49G'],
        'SPEJ47VZTWXC49G-600980-254712345678-12345678-1.00',
        '600980',
    ],
    'c2b result, signed as sent' => [
        ['ResultCode' => '0', 'TransAmount' => '1.000000', 'BillRefNumber' => '12345678', 'CustomerMobile' => '254712345678', 'TransactionCode' => 'SPEJ47VZTWXC49G'],
        'SPEJ47VZTWXC49G-600980-254712345678-12345678-1.000000',
        '600980',
    ],
    'ipn' => [
        ['MerchantCode' => '600980', 'BusinessShortCode' => '600980', 'InvoiceNumber' => 'INV-278-RID-6754', 'TransID' => 'CDVISAIHD', 'TransactionType' => 'C2B', 'MSISDN' => '254700000005', 'TransAmount' => '10.00', 'BillRefNumber' => '12345'],
        'CDVISAIHD-600980-254700000005-12345-10.00',
        null,
    ],
    'payout result' => [
        ['MerchantCode' => '600980', 'RecipientAccountNumber' => '254700000007', 'ResultCode' => '0', 'SasaPayTransactionCode' => 'CRVSUVGIRP', 'TransactionAmount' => '10.00', 'MerchantTransactionReference' => '25477'],
        'CRVSUVGIRP-600980-254700000007-25477-10.00',
        null,
    ],
]);

it('prefers the merchant code a callback carries over the configured one', function (): void {
    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID', merchantCode: '999999');
    $payload = sasapayCallbackPayload();

    expect($verifier->verify($payload, sasapayCallbackSignature($payload)))->toBeTrue();
});

it('fails closed when a c2b result has no merchant code to sign with', function () use ($signedBy): void {
    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID');
    $payload = ['ResultCode' => '0', 'TransAmount' => '1.00', 'BillRefNumber' => '1', 'CustomerMobile' => '254712345678', 'TransactionCode' => 'SP1'];

    expect($verifier->verify($payload, $signedBy('SP1-600980-254712345678-1-1.00')))->toBeFalse();
});

it('takes the fallback merchant code from config', function () use ($signedBy): void {
    $verifier = SasaPayCallbackVerifier::make(['client_id' => 'Merchant API Client ID', 'merchant_code' => '600980']);
    $payload = ['ResultCode' => '0', 'TransAmount' => '1.00', 'BillRefNumber' => '1', 'CustomerMobile' => '254712345678', 'TransactionCode' => 'SP1'];

    expect($verifier->verify($payload, $signedBy('SP1-600980-254712345678-1-1.00')))->toBeTrue();
});

it('rejects an amount changed after signing', function (): void {
    $verifier = new SasaPayCallbackVerifier('Merchant API Client ID');
    $signature = sasapayCallbackSignature(sasapayCallbackPayload());

    expect($verifier->verify(sasapayCallbackPayload(['amount' => '1.00']), $signature))->toBeFalse();
});
