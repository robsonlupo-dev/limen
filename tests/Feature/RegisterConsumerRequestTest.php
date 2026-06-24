<?php

use App\Http\Requests\RegisterConsumerRequest;
use Illuminate\Support\Facades\Validator;

function validateRegistration(array $overrides = []): \Illuminate\Validation\Validator
{
    $request = new RegisterConsumerRequest();

    $data = array_merge([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'birthdate' => now()->subYears(20)->format('Y-m-d'),
        'terms_accepted' => true,
        'lgpd_consent' => true,
    ], $overrides);

    return Validator::make($data, $request->rules(), $request->messages());
}

it('rejects registration for users under 18', function () {
    $validator = validateRegistration([
        'birthdate' => now()->subYears(17)->format('Y-m-d'),
    ]);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('birthdate'))->toBeTrue();
});

it('rejects registration with future birthdate', function () {
    $validator = validateRegistration([
        'birthdate' => now()->addYear()->format('Y-m-d'),
    ]);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('birthdate'))->toBeTrue();
});

it('rejects registration without accepting terms', function () {
    $validator = validateRegistration([
        'terms_accepted' => false,
    ]);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('terms_accepted'))->toBeTrue();
});

it('rejects registration without LGPD consent', function () {
    $validator = validateRegistration([
        'lgpd_consent' => false,
    ]);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('lgpd_consent'))->toBeTrue();
});

it('rejects weak passwords', function () {
    $validator = validateRegistration([
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('password'))->toBeTrue();
});

it('accepts valid registration data', function () {
    $validator = validateRegistration();

    expect($validator->fails())->toBeFalse();
});
