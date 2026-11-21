<?php

use Illuminate\Http\Request;
use Noria\Payments\Exceptions\ConfigurationException;
use Noria\Payments\NoriaPayWebhookVerifier;

it('verifies Pay-Signature against the raw body and the timestamp', function (): void {
    $verifier = new NoriaPayWebhookVerifier('whsec_example');
    $body = '{"id":"evt_1","type":"succeeded"}';
    $signature = 't=1763700000,v1='.hash_hmac('sha256', '1763700000.'.$body, 'whsec_example');

    $request = Request::create('/webhooks/pay', 'POST', server: ['HTTP_PAY_SIGNATURE' => $signature], content: $body);

    expect($verifier->verify($body, $signature, 1763700010))->toBeTrue()
        ->and($verifier->verifyRequest($request, 1763700010))->toBeTrue()
        ->and($verifier->sign($body, 1763700000))->toBe($signature);
});

it('rejects tampered bodies, wrong secrets, stale timestamps and malformed headers', function (): void {
    $verifier = new NoriaPayWebhookVerifier('whsec_example');
    $body = '{"id":"evt_1"}';
    $signature = 't=1763700000,v1='.hash_hmac('sha256', '1763700000.'.$body, 'whsec_example');

    expect($verifier->verify('{"id":"evt_2"}', $signature, 1763700000))->toBeFalse()
        ->and((new NoriaPayWebhookVerifier('whsec_other'))->verify($body, $signature, 1763700000))->toBeFalse()
        ->and($verifier->verify($body, $signature, 1763700301))->toBeFalse()
        ->and($verifier->verify($body, 'v1=abc', 1763700000))->toBeFalse()
        ->and($verifier->verify($body, null))->toBeFalse();
});

it('needs a secret', function (): void {
    expect(fn () => new NoriaPayWebhookVerifier(''))->toThrow(ConfigurationException::class);
});
