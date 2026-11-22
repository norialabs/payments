<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Noria\Payments\Exceptions\ConfigurationException;
use Noria\Payments\Exceptions\NoriaPayException;
use Noria\Payments\NoriaPayClient;
use Noria\Payments\PaymentsManager;

function noriaPay(): NoriaPayClient
{
    return NoriaPayClient::make(Http::getFacadeRoot(), ['base_url' => 'https://pay.example.com', 'api_key' => 'pay_live_platform']);
}

it('sends charges with the key, idempotency key and tenant project', function (): void {
    Http::fake(['pay.example.com/*' => Http::response(['object' => 'transaction', 'id' => 'txn_1', 'status' => 'processing'], 201)]);

    $charge = noriaPay()->charge(['amount_minor' => 250000, 'channel' => 'mpesa', 'payer_phone' => '254712345678', 'reference' => 'INV-1', 'description' => 'Invoice'], 'invoice-1:attempt-1', 'project_204');

    expect($charge['id'])->toBe('txn_1');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pay.example.com/v1/charges'
        && $request->method() === 'POST'
        && $request->header('Authorization')[0] === 'Bearer pay_live_platform'
        && $request->header('Idempotency-Key')[0] === 'invoice-1:attempt-1'
        && $request->header('X-Noria-Application')[0] === 'project_204'
        && $request['amount_minor'] === 250000);
});

it('never retries a money-moving request and exposes the earlier transaction', function (): void {
    Http::fake(['pay.example.com/*' => Http::response(['error' => ['code' => 'outcome_unknown', 'message' => 'The provider did not confirm the outcome', 'details' => ['transaction_id' => 'txn_9']]], 504)]);

    try {
        noriaPay()->payout(['amount_minor' => 1000], 'wages-1');
        $this->fail('expected an exception');
    } catch (NoriaPayException $exception) {
        expect($exception->statusCode)->toBe(504)
            ->and($exception->errorCode)->toBe('outcome_unknown')
            ->and($exception->transactionId)->toBe('txn_9')
            ->and($exception->outcomeUnknown())->toBeTrue();
    }

    Http::assertSentCount(1);
});

it('treats a definite refusal as known', function (): void {
    Http::fake(['pay.example.com/*' => Http::response(['error' => ['code' => 'idempotency_mismatch', 'message' => 'Idempotency-Key was used with a different request body']], 409)]);

    expect(fn () => noriaPay()->charge([], 'k'))->toThrow(function (NoriaPayException $exception): void {
        expect($exception->outcomeUnknown())->toBeFalse()->and($exception->errorCode)->toBe('idempotency_mismatch');
    });
});

it('encodes KYC files and drops empty query values', function (): void {
    Http::fake(['pay.example.com/*' => Http::response(['object' => 'onboarding_case', 'status' => 'kyc_submitted'])]);

    noriaPay()->submitOnboardingKyc('case_1', ['directorIdNumber' => '1234'], [['field' => 'kraPin', 'filename' => 'pin.pdf', 'content_type' => 'application/pdf', 'contents' => '%PDF-']], 'project_204');
    noriaPay()->balance('daraja', null, 'project_204');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/onboarding-cases/case_1/kyc')
        && $request['files'][0]['content_base64'] === base64_encode('%PDF-')
        && $request->data()['fields']->directorIdNumber === '1234');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pay.example.com/v1/payment-methods/daraja/balance');
});

it('reads the payment options and names a method by purpose', function (): void {
    Http::fake(['pay.example.com/*' => Http::response(['object' => 'payment_options', 'collections' => [], 'payouts' => []])]);

    expect(noriaPay()->paymentOptions('live', 'project_204')['object'])->toBe('payment_options');
    noriaPay()->setPaymentMethodEnabled('daraja', false, 'live', 'project_204', 'payouts');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pay.example.com/v1/payment-options?mode=live'
        && $request->header('X-Noria-Application')[0] === 'project_204');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pay.example.com/v1/payment-methods/daraja?mode=live&purpose=payouts'
        && $request->method() === 'PATCH');
});

it('needs a base URL and an API key', function (): void {
    expect(fn () => NoriaPayClient::make(Http::getFacadeRoot(), ['base_url' => 'https://pay.example.com']))->toThrow(ConfigurationException::class);
});

it('is built from the noria_pay config', function (): void {
    config()->set('payments.noria_pay', ['base_url' => 'https://pay.example.com', 'api_key' => 'pay_live_config']);
    Http::fake(['pay.example.com/*' => Http::response(['object' => 'list', 'data' => []])]);

    app(PaymentsManager::class)->noriaPay()->projects(['reference' => 'workspace-204']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pay.example.com/v1/applications?reference=workspace-204'
        && $request->header('Authorization')[0] === 'Bearer pay_live_config');
});
