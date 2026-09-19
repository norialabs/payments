<?php

use NoriaLabs\Payments\Exceptions\BusinessException;
use NoriaLabs\Payments\Support\BusinessStatus;

it('reads kcb buni business outcomes from either documented envelope', function (): void {
    expect(BusinessStatus::succeeded(BusinessStatus::KCB_BUNI, ['header' => ['statusCode' => '0']]))->toBeTrue()
        ->and(BusinessStatus::succeeded(BusinessStatus::KCB_BUNI, ['header' => ['statusCode' => '1']]))->toBeFalse()
        ->and(BusinessStatus::succeeded(BusinessStatus::KCB_BUNI, ['response' => ['ResponseCode' => 0]]))->toBeTrue()
        ->and(BusinessStatus::succeeded(BusinessStatus::KCB_BUNI, ['response' => ['ResponseCode' => 1032]]))->toBeFalse()
        ->and(BusinessStatus::succeeded(BusinessStatus::KCB_BUNI, ['statusCode' => '0']))->toBeTrue()
        ->and(BusinessStatus::succeeded(BusinessStatus::KCB_BUNI, ['nothing' => 'useful']))->toBeNull()
        ->and(BusinessStatus::succeeded(BusinessStatus::KCB_BUNI, 'plain text'))->toBeNull();
});

it('reads daraja business outcomes including error envelopes', function (): void {
    expect(BusinessStatus::succeeded(BusinessStatus::MPESA, ['ResponseCode' => '0']))->toBeTrue()
        ->and(BusinessStatus::succeeded(BusinessStatus::MPESA, ['ResponseCode' => '1']))->toBeFalse()
        ->and(BusinessStatus::succeeded(BusinessStatus::MPESA, ['ResultCode' => 0]))->toBeTrue()
        ->and(BusinessStatus::succeeded(BusinessStatus::MPESA, [
            'errorCode' => '400.002.02',
            'errorMessage' => 'Bad Request - Invalid BusinessShortCode',
        ]))->toBeFalse()
        ->and(BusinessStatus::succeeded(BusinessStatus::MPESA, [
            'Body' => ['stkCallback' => ['ResultCode' => 1032]],
        ]))->toBeFalse()
        ->and(BusinessStatus::succeeded(BusinessStatus::MPESA, ['OriginatorConversationID' => 'x']))->toBeNull();

    expect(BusinessStatus::statusMessage(BusinessStatus::MPESA, [
        'errorCode' => '400.002.02',
        'errorMessage' => 'Bad Request - Invalid BusinessShortCode',
    ]))->toBe('Bad Request - Invalid BusinessShortCode');
});

it('reads sasapay and paystack boolean status fields', function (): void {
    expect(BusinessStatus::succeeded(BusinessStatus::SASAPAY, ['status' => true]))->toBeTrue()
        ->and(BusinessStatus::succeeded(BusinessStatus::SASAPAY, ['status' => false]))->toBeFalse()
        ->and(BusinessStatus::succeeded(BusinessStatus::SASAPAY, ['status' => 'true']))->toBeTrue()
        ->and(BusinessStatus::succeeded(BusinessStatus::SASAPAY, ['statusCode' => '0']))->toBeTrue()
        ->and(BusinessStatus::succeeded(BusinessStatus::SASAPAY, []))->toBeNull()
        ->and(BusinessStatus::succeeded(BusinessStatus::PAYSTACK, ['status' => true]))->toBeTrue()
        ->and(BusinessStatus::succeeded(BusinessStatus::PAYSTACK, ['status' => false]))->toBeFalse()
        ->and(BusinessStatus::succeeded(BusinessStatus::PAYSTACK, ['data' => []]))->toBeNull()
        ->and(BusinessStatus::succeeded('unknown-provider', ['status' => false]))->toBeNull();
});

it('never throws for a success or an undeterminable shape', function (): void {
    BusinessStatus::assert(BusinessStatus::KCB_BUNI, ['header' => ['statusCode' => '0']], 'Transfer');
    BusinessStatus::assert(BusinessStatus::KCB_BUNI, ['unknown' => 'shape'], 'Transfer');
    BusinessStatus::assert(BusinessStatus::PAYSTACK, null, 'Charge');

    expect(true)->toBeTrue();
});

it('carries the provider status code and body on the thrown exception', function (): void {
    $body = ['status' => false, 'detail' => 'Invalid merchant code', 'statusCode' => '1'];

    try {
        BusinessStatus::assert(BusinessStatus::SASAPAY, $body, 'SasaPay C2B');
        expect(false)->toBeTrue();
    } catch (BusinessException $exception) {
        expect($exception->getMessage())->toBe('SasaPay C2B failed: Invalid merchant code (status 1)')
            ->and($exception->provider)->toBe(BusinessStatus::SASAPAY)
            ->and($exception->statusCode)->toBe('1')
            ->and($exception->responseBody)->toBe($body)
            ->and($exception->codeName)->toBe('BUSINESS_ERROR');
    }
});

it('falls back to a generic message when the provider sends none', function (): void {
    expect(fn () => BusinessStatus::assert(BusinessStatus::PAYSTACK, ['status' => false], 'Paystack charge'))
        ->toThrow(BusinessException::class, 'Paystack charge failed: The provider reported a business failure.');
});

it('fails a buni stk reply when the gateway succeeds but safaricom rejects', function (): void {
    expect(BusinessStatus::succeeded(BusinessStatus::KCB_BUNI, [
        'header' => ['statusCode' => '0'],
        'response' => ['ResponseCode' => 1032, 'ResponseDescription' => 'Request cancelled by user'],
    ]))->toBeFalse();

    expect(BusinessStatus::succeeded(BusinessStatus::KCB_BUNI, [
        'header' => ['statusCode' => '0'],
        'response' => ['ResponseCode' => 0],
    ]))->toBeTrue();

    expect(BusinessStatus::statusMessage(BusinessStatus::KCB_BUNI, [
        'header' => ['statusCode' => '0'],
        'response' => ['ResponseCode' => 1032, 'ResponseDescription' => 'Request cancelled by user'],
    ]))->toBe('Request cancelled by user');
});
