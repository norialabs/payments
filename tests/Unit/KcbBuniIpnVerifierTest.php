<?php

use Illuminate\Support\Facades\Route;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\Http\Middleware\VerifyKcbBuniIpn;
use NoriaLabs\Payments\KcbBuniIpnVerifier;

function kcbBuniKeyPair(): array
{
    $privateKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($privateKey, $privatePem);

    $details = openssl_pkey_get_details($privateKey);

    return [$privatePem, $details['key']];
}

function kcbBuniSignature(string $payload, string $privateKey): string
{
    openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    return base64_encode($signature);
}

it('verifies documented sha256withrsa ipn signatures over the raw request body', function (): void {
    [$privateKey, $publicKey] = kcbBuniKeyPair();
    $payload = '{"transactionReference":"FT000262556","transactionAmount":"1000"}';
    $signature = kcbBuniSignature($payload, $privateKey);

    $verifier = new KcbBuniIpnVerifier($publicKey);

    expect($verifier->verify($payload, $signature))->toBeTrue()
        ->and($verifier->verify($payload.' ', $signature))->toBeFalse()
        ->and($verifier->verify($payload, null))->toBeFalse()
        ->and($verifier->verify($payload, 'not-base64'))->toBeFalse();
});

it('accepts public keys with escaped newlines from env configuration', function (): void {
    [$privateKey, $publicKey] = kcbBuniKeyPair();
    $payload = '{"requestId":"12345"}';
    $signature = kcbBuniSignature($payload, $privateKey);

    $verifier = KcbBuniIpnVerifier::make([
        'ipn_security' => [
            'public_key' => str_replace("\n", '\\n', $publicKey),
        ],
    ]);

    expect($verifier->verify($payload, $signature))->toBeTrue();
});

it('can enforce ip allowlisting independently from signature verification', function (): void {
    [$privateKey, $publicKey] = kcbBuniKeyPair();
    $payload = '{"requestId":"12345"}';
    $signature = kcbBuniSignature($payload, $privateKey);

    $verifier = KcbBuniIpnVerifier::make([
        'ipn_security' => [
            'public_key' => $publicKey,
            'trusted_ips' => '196.201.214.200,10.0.0.0/8',
            'enforce_ip_whitelist' => true,
        ],
    ]);

    expect($verifier->verify($payload, $signature, '196.201.214.200'))->toBeTrue()
        ->and($verifier->verify($payload, $signature, '192.0.2.10'))->toBeFalse()
        ->and($verifier->trustedIps())->toBe(['196.201.214.200', '10.0.0.0/8']);

    $signatureDisabled = KcbBuniIpnVerifier::make([
        'ipn_security' => [
            'verify_signature' => false,
            'enforce_ip_whitelist' => false,
        ],
    ]);

    expect($signatureDisabled->verify($payload, 'invalid-signature'))->toBeTrue();
});

it('exposes ipn security flags and handles empty ip allowlist inputs', function (): void {
    [, $publicKey] = kcbBuniKeyPair();

    $verifier = KcbBuniIpnVerifier::make([
        'ipn_security' => [
            'public_key' => $publicKey,
            'trusted_ips' => false,
            'enforce_ip_whitelist' => 'true',
            'verify_signature' => 'false',
        ],
    ]);

    expect($verifier->trustedIps())->toBe([])
        ->and($verifier->enforcesIpWhitelist())->toBeTrue()
        ->and($verifier->verifiesSignature())->toBeFalse()
        ->and($verifier->isTrustedIp(null))->toBeFalse()
        ->and($verifier->isTrustedIp(''))->toBeFalse()
        ->and($verifier->verify('{"requestId":"12345"}', 'invalid-signature', '192.0.2.10'))->toBeFalse();
});

it('rejects invalid ipn callbacks through the laravel middleware', function (): void {
    [$privateKey, $publicKey] = kcbBuniKeyPair();
    config()->set('payments.kcb_buni.ipn_security.public_key', $publicKey);

    Route::post('/kcb-buni/ipn', fn () => response('ok'))
        ->middleware(VerifyKcbBuniIpn::class);

    $payload = ['transactionReference' => 'FT000262556'];

    $this->postJson('/kcb-buni/ipn', $payload, [
        'Signature' => kcbBuniSignature(json_encode($payload), $privateKey),
    ])->assertOk();

    $this->postJson('/kcb-buni/ipn', $payload, [
        'Signature' => 'invalid',
    ])->assertForbidden();
});

it('requires a public key before verifying signatures', function (): void {
    $verifier = new KcbBuniIpnVerifier(null);

    expect(fn () => $verifier->verify('{}', base64_encode('signature')))
        ->toThrow(ConfigurationException::class, 'public key');
});

it('rejects an invalid configured public key', function (): void {
    $verifier = new KcbBuniIpnVerifier('not-a-public-key');

    expect(fn () => $verifier->verify('{}', base64_encode('signature')))
        ->toThrow(ConfigurationException::class, 'valid OpenSSL public key');
});

it('supports per-route middleware options for the unsigned validation endpoint', function (): void {
    [$privateKey, $publicKey] = kcbBuniKeyPair();
    config()->set('payments.kcb_buni.ipn_security.public_key', $publicKey);

    Route::post('/kcb-buni/ipn/account', fn () => response('ok'))
        ->middleware(VerifyKcbBuniIpn::class);
    Route::post('/kcb-buni/ipn/validation', fn () => response('ok'))
        ->middleware(VerifyKcbBuniIpn::class.':no-signature');

    $payload = ['requestId' => 'REQ1', 'customerReference' => 'ACC1', 'organizationReference' => 'ORG1'];

    $this->postJson('/kcb-buni/ipn/validation', $payload)->assertOk();
    $this->postJson('/kcb-buni/ipn/account', $payload)->assertForbidden();

    $signed = ['transactionReference' => 'FT1'];
    $this->postJson('/kcb-buni/ipn/account', $signed, [
        'Signature' => kcbBuniSignature(json_encode($signed), $privateKey),
    ])->assertOk();
});

it('registers middleware aliases and enforces ip allowlisting per route', function (): void {
    config()->set('payments.kcb_buni.ipn_security.trusted_ips', ['203.0.113.10']);
    config()->set('payments.kcb_buni.ipn_security.verify_signature', false);

    Route::post('/kcb-buni/ipn/aliased', fn () => response('ok'))
        ->middleware('kcb-buni.ipn:ip,no-signature');

    $this->postJson('/kcb-buni/ipn/aliased', ['transactionReference' => 'FT1'])->assertForbidden();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->postJson('/kcb-buni/ipn/aliased', ['transactionReference' => 'FT1'])
        ->assertOk();
});

it('rejects unknown middleware options instead of silently ignoring them', function (): void {
    config()->set('payments.kcb_buni.ipn_security.verify_signature', false);

    Route::post('/kcb-buni/ipn/bad-option', fn () => response('ok'))
        ->middleware(VerifyKcbBuniIpn::class.':no-signatures');

    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/kcb-buni/ipn/bad-option', []))
        ->toThrow(ConfigurationException::class, 'Unknown payment verification middleware option [no-signatures]');
});
