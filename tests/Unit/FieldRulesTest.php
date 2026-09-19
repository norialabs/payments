<?php

use NoriaLabs\Payments\Exceptions\ValidationException;
use NoriaLabs\Payments\Support\FieldRules;

it('treats a present but blank value as satisfying required', function (): void {
    expect(FieldRules::validate(['orgPassKey' => ''], ['orgPassKey' => ['required' => true]]))->toBe([]);

    expect(FieldRules::validate([], ['orgPassKey' => ['required' => true]]))
        ->toBe(['[orgPassKey] is required.']);

    expect(FieldRules::validate(['orgPassKey' => null], ['orgPassKey' => ['required' => true]]))
        ->toBe(['[orgPassKey] is required and cannot be null.']);
});

it('applies notEmpty, max, numeric, boolean and pattern rules', function (): void {
    expect(FieldRules::validate(['ref' => '   '], ['ref' => ['notEmpty' => true]]))
        ->toBe(['[ref] cannot be empty.']);

    expect(FieldRules::validate(['ref' => 'abcdef'], ['ref' => ['max' => 3]]))
        ->toBe(['[ref] must not exceed 3 characters, got 6.']);

    expect(FieldRules::validate(['amount' => 'ten'], ['amount' => ['numeric' => true]]))
        ->toBe(['[amount] must be numeric.']);

    expect(FieldRules::validate(['shared' => 'true'], ['shared' => ['boolean' => true]]))
        ->toBe(['[shared] must be a boolean.']);

    expect(FieldRules::validate(['phone' => '0722000000'], [
        'phone' => ['pattern' => '/^254\d{9}$/', 'format' => '2547XXXXXXXX'],
    ]))->toBe(['[phone] is malformed. Expected format: 2547XXXXXXXX.']);

    expect(FieldRules::validate(['payload' => ['nested' => true]], ['payload' => ['max' => 5]]))
        ->toBe(['[payload] must be a scalar value.']);
});

it('counts multibyte characters rather than bytes', function (): void {
    expect(FieldRules::validate(['name' => 'Kĩharani'], ['name' => ['max' => 8]]))->toBe([]);
});

it('skips pattern checks on blank values so notEmpty owns that error', function (): void {
    expect(FieldRules::validate(['phone' => ''], ['phone' => ['pattern' => '/^254\d{9}$/']]))->toBe([]);
});

it('collects every failure into one exception', function (): void {
    $rules = [
        'ref' => ['required' => true, 'max' => 3],
        'amount' => ['required' => true, 'numeric' => true],
    ];

    expect(fn () => FieldRules::assert(['ref' => 'abcdef', 'amount' => 'ten'], $rules, 'Test'))
        ->toThrow(
            ValidationException::class,
            'Test payload is invalid: [ref] must not exceed 3 characters, got 6. [amount] must be numeric.'
        );

    try {
        FieldRules::assert([], $rules, 'Test');
    } catch (ValidationException $exception) {
        expect($exception->errors)->toBe(['[ref] is required.', '[amount] is required.'])
            ->and($exception->codeName)->toBe('VALIDATION_ERROR');
    }
});

it('passes a fully valid payload', function (): void {
    FieldRules::assert(['ref' => 'ab', 'amount' => 10], [
        'ref' => ['required' => true, 'notEmpty' => true, 'max' => 3],
        'amount' => ['required' => true, 'numeric' => true],
    ], 'Test');

    expect(true)->toBeTrue();
});
