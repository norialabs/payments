<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Noria\Payments\Exceptions\AuthenticationException;
use Noria\Payments\Exceptions\NetworkException;
use Noria\Payments\Exceptions\TimeoutException;
use Noria\Payments\Support\AfterResponseContext;
use Noria\Payments\Support\ErrorContext;
use Noria\Payments\Support\Hooks;
use Noria\Payments\Support\Payload;
use Noria\Payments\Support\RequestOptions;
use Noria\Payments\Support\RetryPolicy;

it('builds AfterResponseContext and ErrorContext with expected fields', function (): void {
    $response = new Response(Factory::psr7Response(['ok' => true], 200));

    $after = new AfterResponseContext(
        url: 'https://example.test/after',
        path: '/after',
        method: 'GET',
        headers: ['X-Test' => '1'],
        body: null,
        attempt: 1,
        response: $response,
        responseBody: ['ok' => true],
    );

    $error = new ErrorContext(
        url: 'https://example.test/error',
        path: '/error',
        method: 'POST',
        headers: [],
        body: ['payload' => true],
        attempt: 2,
        error: new RuntimeException('failed'),
        response: $response,
        responseBody: ['failed' => true],
    );

    expect($after->responseBody)->toBe(['ok' => true])
        ->and($error->responseBody)->toBe(['failed' => true])
        ->and($error->attempt)->toBe(2);
});

it('normalizes single callables and arrays of callables in Hooks', function (): void {
    $callable = fn () => null;

    $hooks = new Hooks(
        beforeRequest: $callable,
        afterResponse: [$callable],
        onError: [$callable],
    );

    expect((new Hooks)->beforeRequestCallbacks())->toBe([])
        ->and($hooks->beforeRequestCallbacks())->toHaveCount(1)
        ->and($hooks->afterResponseCallbacks())->toHaveCount(1)
        ->and($hooks->onErrorCallbacks())->toHaveCount(1);
});

it('returns null from RetryPolicy::fromArray for null and false', function (): void {
    expect(RetryPolicy::fromArray(null))->toBeNull()
        ->and(RetryPolicy::fromArray(false))->toBeNull();
});

it('builds RequestOptions from arrays including amount normalization', function (): void {
    $options = RequestOptions::fromArray([
        'access_token' => 'token',
        'force_token_refresh' => true,
        'amount_normalization' => 'none',
    ]);

    expect($options->accessToken)->toBe('token')
        ->and($options->forceTokenRefresh)->toBeTrue()
        ->and($options->amountNormalization)->toBe('none');
});

it('returns the same RetryPolicy instance when fromArray receives one', function (): void {
    $policy = new RetryPolicy(maxAttempts: 3);

    expect(RetryPolicy::fromArray($policy))->toBe($policy);
});

it('builds a RetryPolicy from an array including new jitter and retry-after fields', function (): void {
    $callback = fn () => true;

    $policy = RetryPolicy::fromArray([
        'max_attempts' => 3,
        'retry_methods' => ['GET'],
        'retry_on_statuses' => [429],
        'retry_on_network_error' => true,
        'base_delay_seconds' => 0.01,
        'max_delay_seconds' => 0.02,
        'backoff_multiplier' => 1.5,
        'jitter_seconds' => 0.05,
        'respect_retry_after' => false,
        'should_retry' => $callback,
        'sleeper' => $callback,
    ]);

    expect($policy)->not->toBeNull()
        ->and($policy?->maxAttempts)->toBe(3)
        ->and($policy?->retryMethods)->toBe(['GET'])
        ->and($policy?->retryOnStatuses)->toBe([429])
        ->and($policy?->retryOnNetworkError)->toBeTrue()
        ->and($policy?->baseDelaySeconds)->toBe(0.01)
        ->and($policy?->maxDelaySeconds)->toBe(0.02)
        ->and($policy?->backoffMultiplier)->toBe(1.5)
        ->and($policy?->jitterSeconds)->toBe(0.05)
        ->and($policy?->respectRetryAfter)->toBeFalse();
});

it('exposes machine-readable codeName on every typed exception', function (): void {
    expect((new AuthenticationException('auth'))->codeName)->toBe('AUTHENTICATION_ERROR')
        ->and((new NetworkException('network'))->codeName)->toBe('NETWORK_ERROR')
        ->and((new TimeoutException('timeout'))->codeName)->toBe('TIMEOUT_ERROR');
});

it('stringifies amounts without leaking float rounding artefacts', function (): void {
    expect(Payload::amountToString(0.1 + 0.2))->toBe('0.3')
        ->and(Payload::amountToString(10.50))->toBe('10.5')
        ->and(Payload::amountToString(100.0))->toBe('100')
        ->and(Payload::amountToString(1500.75))->toBe('1500.75')
        ->and(Payload::amountToString(10))->toBe('10')
        ->and(Payload::amountToString('1500.00'))->toBe('1500.00')
        ->and(Payload::amountToString(true))->toBe('1');

    expect(Payload::stringifyAmount(['amount' => 0.1 + 0.2]))->toBe(['amount' => '0.3'])
        ->and(Payload::stringifyAmount(['Amount' => 10.50]))->toBe(['Amount' => '10.5']);
});

it('leaves non-scalar amount values untouched instead of erroring', function (): void {
    $payload = ['amount' => ['value' => 10, 'currency' => 'KES']];

    expect(Payload::stringifyAmount($payload))->toBe($payload);
});

it('normalizes Kenyan mobile numbers for provider payloads', function (string|int $input, string|int $expected): void {
    expect(Payload::normalizeKenyanPhoneNumber($input))->toBe($expected);
})->with([
    '07 mobile number' => ['0704444721', '254704444721'],
    '01 mobile number' => ['0110123456', '254110123456'],
    'without trunk prefix' => ['704444721', '254704444721'],
    'formatted international number' => ['+254 704 444 721', '254704444721'],
    'canonical number' => ['254704444721', '254704444721'],
    'unrecognized prefix' => ['0804444721', '0804444721'],
    'non-phone text' => ['customer-0704444721', 'customer-0704444721'],
]);

it('normalizes only named phone fields in a payload', function (): void {
    $payload = [
        'PhoneNumber' => '0704444721',
        'Reference' => '0704444721',
        'Metadata' => ['phone' => '0704444721'],
    ];

    expect(Payload::normalizeKenyanPhoneNumbers($payload, ['PhoneNumber', 'Metadata']))->toBe([
        'PhoneNumber' => '254704444721',
        'Reference' => '0704444721',
        'Metadata' => ['phone' => '0704444721'],
    ]);
});

it('carries the new validate and business-error request options', function (): void {
    $options = RequestOptions::fromArray([
        'validate' => false,
        'throw_on_business_error' => true,
    ]);

    expect($options->validate)->toBeFalse()
        ->and($options->throwOnBusinessError)->toBeTrue();

    $camel = RequestOptions::fromArray(['throwOnBusinessError' => 'false']);

    expect($camel->throwOnBusinessError)->toBeFalse()
        ->and($camel->validate)->toBeNull()
        ->and(RequestOptions::fromArray([])->throwOnBusinessError)->toBeNull();
});
