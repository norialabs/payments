<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Noria\Payments\Exceptions\ConfigurationException;
use Noria\Payments\Http\Middleware\VerifyPaystackWebhook;
use Noria\Payments\PaystackWebhookVerifier;

function paystackWebhookPayload(array $overrides = []): array
{
    return array_replace([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_123',
            'amount' => 10000,
            'status' => 'success',
        ],
    ], $overrides);
}

function paystackWebhookBody(array $payload): string
{
    return json_encode($payload, JSON_THROW_ON_ERROR);
}

function paystackWebhookSignature(string $body, string $secretKey = 'sk_test_secret'): string
{
    return hash_hmac('sha512', $body, $secretKey);
}

it('verifies Paystack webhook signatures against the raw request body', function (): void {
    $body = paystackWebhookBody(paystackWebhookPayload());
    $signature = paystackWebhookSignature($body);
    $verifier = new PaystackWebhookVerifier('sk_test_secret');

    $request = Request::create(
        uri: '/paystack/webhook',
        method: 'POST',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
            'REMOTE_ADDR' => '52.31.139.75',
        ],
        content: $body,
    );

    expect($verifier->expectedSignature($body))->toBe($signature)
        ->and($verifier->verify($body, $signature))->toBeTrue()
        ->and($verifier->verifyRequest($request))->toBeTrue();
});

it('rejects missing, invalid, or body-mismatched Paystack webhook signatures', function (): void {
    $body = paystackWebhookBody(paystackWebhookPayload());
    $verifier = new PaystackWebhookVerifier('sk_test_secret');

    expect($verifier->verify($body, null))->toBeFalse()
        ->and($verifier->verify($body, ''))->toBeFalse()
        ->and($verifier->verify($body, 'invalid-signature'))->toBeFalse()
        ->and($verifier->verify(paystackWebhookBody(paystackWebhookPayload(['event' => 'transfer.success'])), paystackWebhookSignature($body)))
        ->toBeFalse();
});

it('can enforce and override the documented Paystack webhook source IP allowlist', function (): void {
    $body = paystackWebhookBody(paystackWebhookPayload());
    $signature = paystackWebhookSignature($body);
    $verifier = PaystackWebhookVerifier::make([
        'secret_key' => 'sk_test_secret',
        'webhook_security' => [
            'enforce_ip_whitelist' => true,
        ],
    ]);

    expect($verifier->trustedIps())->toBe(PaystackWebhookVerifier::TRUSTED_WEBHOOK_IPS)
        ->and($verifier->isTrustedIp('52.31.139.75'))->toBeTrue()
        ->and($verifier->isTrustedIp('8.8.8.8'))->toBeFalse()
        ->and($verifier->isTrustedIp(null))->toBeFalse()
        ->and($verifier->verify($body, $signature, '52.49.173.169'))->toBeTrue()
        ->and($verifier->verify($body, $signature, '8.8.8.8'))->toBeFalse();

    $custom = PaystackWebhookVerifier::make([
        'secret_key' => 'sk_test_secret',
        'webhook_security' => [
            'trusted_ips' => '203.0.113.10, 198.51.100.25',
            'enforce_ip_whitelist' => true,
        ],
    ]);

    expect($custom->trustedIps())->toBe(['203.0.113.10', '198.51.100.25'])
        ->and($custom->verify($body, $signature, '203.0.113.10'))->toBeTrue()
        ->and($custom->verify($body, $signature, '52.31.139.75'))->toBeFalse();
});

it('coerces Paystack webhook security config and falls back to documented IPs', function (): void {
    $verifier = PaystackWebhookVerifier::make([
        'secret_key' => 'sk_test_secret',
        'webhook_security' => [
            'trusted_ips' => 123,
            'enforce_ip_whitelist' => 'true',
            'verify_signature' => 'false',
        ],
    ]);

    expect($verifier->trustedIps())->toBe(PaystackWebhookVerifier::TRUSTED_WEBHOOK_IPS)
        ->and($verifier->enforcesIpWhitelist())->toBeTrue()
        ->and($verifier->verifiesSignature())->toBeFalse()
        ->and($verifier->verify('not-signed', signature: null, ip: '52.31.139.75'))->toBeTrue()
        ->and($verifier->verify('not-signed', signature: null, ip: '8.8.8.8'))->toBeFalse();
});

it('throws ConfigurationException when signature verification has no secret key', function (): void {
    $verifier = new PaystackWebhookVerifier(null);

    expect(fn () => $verifier->expectedSignature('{}'))
        ->toThrow(ConfigurationException::class, 'Paystack webhook verification requires a secret key');

    expect(fn () => $verifier->verify('{}', paystackWebhookSignature('{}')))
        ->toThrow(ConfigurationException::class, 'Paystack webhook verification requires a secret key');

    expect(PaystackWebhookVerifier::make()->verifiesSignature())->toBeTrue();
});

it('accepts or rejects Laravel requests through the Paystack middleware', function (): void {
    config()->set('payments.paystack.secret_key', 'sk_test_secret');
    config()->set('payments.paystack.webhook_security.secret_key', 'sk_test_secret');

    Route::post('/paystack/webhook', fn () => response('ok'))
        ->middleware(VerifyPaystackWebhook::class);

    $body = paystackWebhookBody(paystackWebhookPayload());
    $signature = paystackWebhookSignature($body);

    $this->call('POST', '/paystack/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $this->call('POST', '/paystack/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => 'invalid',
    ], $body)->assertForbidden();
});
