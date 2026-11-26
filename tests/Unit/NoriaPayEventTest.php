<?php

use Noria\Payments\Support\NoriaPayEvent;

function sasapaySuccess(array $data = []): array
{
    return [
        'id' => 'evt_1',
        'type' => 'payment.succeeded',
        'sequence' => 4,
        'created_at' => '2026-11-26T08:20:58Z',
        'data' => array_replace([
            'transaction_id' => 'txn_1',
            'reference' => 'KAABQK9SNUC',
            'source' => 'academy',
            'application_id' => 'app_1',
            'application_reference' => 'workspace-uuid',
            'direction' => 'collection',
            'mode' => 'live',
            'status' => 'succeeded',
            'previous_status' => 'processing',
            'final' => true,
            'amount' => ['currency' => 'KES', 'requested_minor' => 1000, 'settled_minor' => 1000, 'fee_minor' => null, 'underpaid' => false],
            'rail' => ['channel' => 'mpesa', 'network' => 'safaricom', 'provider' => 'sasapay'],
            'receipts' => ['network' => 'UKQ9S3NU3B', 'provider' => 'SPEJ7XF2H3I8NBI', 'provider_ref' => 'ck-1'],
            'payer' => ['name' => 'Jane Doe', 'phone' => '254728656735', 'email' => null],
            'failure' => null,
            'times' => ['created_at' => '2026-11-26T08:20:40Z', 'settled_at' => '2026-11-26T08:20:56Z', 'expires_at' => null, 'event_at' => '2026-11-26T08:20:58Z'],
            'description' => 'Pay live test',
            'metadata' => ['invoice_id' => '42'],
            'links' => ['parent_transaction_id' => null, 'payment_link_id' => null],
            'callback_id' => 'cb_1',
            'detail' => ['previous_status' => 'processing'],
        ], $data),
    ];
}

it('reads a settled payment from the unified webhook', function (): void {
    $event = NoriaPayEvent::fromWebhook(sasapaySuccess());

    expect($event->id())->toBe('evt_1')
        ->and($event->type())->toBe('payment.succeeded')
        ->and($event->subject())->toBe('payment')
        ->and($event->outcome())->toBe('succeeded')
        ->and($event->sequence())->toBe(4)
        ->and($event->isSucceeded())->toBeTrue()
        ->and($event->isFinal())->toBeTrue()
        ->and($event->isCollection())->toBeTrue()
        ->and($event->settledMinor())->toBe(1000)
        ->and($event->requestedMinor())->toBe(1000)
        ->and($event->currency())->toBe('KES')
        ->and($event->provider())->toBe('sasapay')
        ->and($event->network())->toBe('safaricom')
        ->and($event->networkReceipt())->toBe('UKQ9S3NU3B')
        ->and($event->providerReceipt())->toBe('SPEJ7XF2H3I8NBI')
        ->and($event->receipt())->toBe('UKQ9S3NU3B')
        ->and($event->payer()['phone'])->toBe('254728656735')
        ->and($event->meta('invoice_id'))->toBe('42')
        ->and($event->applicationReference())->toBe('workspace-uuid')
        ->and($event->settledAt())->toBe('2026-11-26T08:20:56Z')
        ->and($event->callbackId())->toBe('cb_1')
        ->and($event->failureCode())->toBeNull();
});

it('reads a failure the same way for every provider', function (): void {
    $event = NoriaPayEvent::fromWebhook(['type' => 'payment.failed'] + sasapaySuccess([
        'status' => 'failed',
        'amount' => ['currency' => 'KES', 'requested_minor' => 1000, 'settled_minor' => null, 'fee_minor' => null, 'underpaid' => false],
        'receipts' => ['network' => null, 'provider' => null, 'provider_ref' => 'ck-1'],
        'failure' => ['code' => 'cancelled_by_payer', 'message' => 'The payment was cancelled on the phone. You can try again.', 'retryable' => true, 'provider_code' => '1032', 'provider_message' => 'Request cancelled by user'],
    ]));

    expect($event->type())->toBe('payment.failed')
        ->and($event->outcome())->toBe('failed')
        ->and($event->isFailed())->toBeTrue()
        ->and($event->isSucceeded())->toBeFalse()
        ->and($event->settledMinor())->toBeNull()
        ->and($event->receipt())->toBeNull()
        ->and($event->failureCode())->toBe('cancelled_by_payer')
        ->and($event->failureMessage())->toBe('The payment was cancelled on the phone. You can try again.')
        ->and($event->isRetryable())->toBeTrue()
        ->and($event->providerFailureCode())->toBe('1032');
});

it('tells a late receipt apart from a second payment', function (): void {
    $event = NoriaPayEvent::fromWebhook(['type' => 'payment.receipt_updated'] + sasapaySuccess());

    expect($event->isReceiptUpdate())->toBeTrue()
        ->and($event->isSucceeded())->toBeTrue();
});

it('reads a payout with the name the provider confirmed', function (): void {
    $event = NoriaPayEvent::fromWebhook(['type' => 'payout.succeeded'] + sasapaySuccess([
        'direction' => 'payout',
        'payer' => null,
        'recipient' => ['name' => 'Jane Doe', 'phone' => '254728656735', 'account' => null, 'confirmed_name' => 'JANE DOE'],
    ]));

    expect($event->subject())->toBe('payout')
        ->and($event->isPayout())->toBeTrue()
        ->and($event->recipient()['confirmed_name'])->toBe('JANE DOE')
        ->and($event->payer()['name'])->toBeNull();
});

it('reads a fetched transaction like an event', function (): void {
    $event = NoriaPayEvent::fromTransaction([
        'id' => 'txn_2',
        'object' => 'transaction',
        'direction' => 'collection',
        'status' => 'succeeded',
        'channel' => 'mpesa',
        'provider' => 'daraja',
        'currency' => 'KES',
        'amount_minor' => 1000,
        'settled_minor' => 900,
        'reference' => 'KAABQCVNBXZ',
        'provider_receipt' => 'UKQ9S3NWXS',
        'network_receipt' => 'UKQ9S3NWXS',
        'failure' => ['code' => 'underpaid', 'message' => 'Only part of the payment came through.', 'retryable' => false, 'provider_code' => null, 'provider_message' => null],
        'payer_name' => 'Jane Doe',
        'settled_at' => '2026-11-26T08:20:10Z',
        'metadata' => ['site' => 'academy'],
    ]);

    expect($event->type())->toBe('payment.succeeded')
        ->and($event->transactionId())->toBe('txn_2')
        ->and($event->isFinal())->toBeTrue()
        ->and($event->isUnderpaid())->toBeTrue()
        ->and($event->network())->toBe('safaricom')
        ->and($event->receipt())->toBe('UKQ9S3NWXS')
        ->and($event->failureCode())->toBe('underpaid')
        ->and($event->payer()['name'])->toBe('Jane Doe')
        ->and($event->meta('site'))->toBe('academy')
        ->and($event->sequence())->toBe(0);
});

it('names an open payment by its outcome', function (): void {
    expect(NoriaPayEvent::fromTransaction(['status' => 'unknown', 'direction' => 'payout'])->type())->toBe('payout.outcome_unknown')
        ->and(NoriaPayEvent::fromTransaction(['status' => 'processing'])->isFinal())->toBeFalse();
});
