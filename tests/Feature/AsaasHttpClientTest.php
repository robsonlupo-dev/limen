<?php

use App\Services\Asaas\AsaasHttpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'asaas.base_url' => 'https://sandbox.asaas.com/api/v3',
        'asaas.api_key' => 'sandbox-key',
    ]);
});

it('sends the api key and returns the decoded body on success', function () {
    Http::fake([
        'sandbox.asaas.com/api/v3/customers' => Http::response(['id' => 'cus_123'], 200),
    ]);

    $result = (new AsaasHttpClient())->createCustomer(['name' => 'Ana']);

    expect($result)->toBe(['id' => 'cus_123']);

    Http::assertSent(fn ($request) => $request->hasHeader('access_token', 'sandbox-key'));
});

it('surfaces the Asaas error description in the exception without logging the request payload', function () {
    Http::fake([
        'sandbox.asaas.com/api/v3/customers' => Http::response([
            'errors' => [
                ['code' => 'invalid_cpfCnpj', 'description' => 'CPF/CNPJ inválido.'],
            ],
        ], 400),
    ]);

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message->context;
    });

    expect(fn () => (new AsaasHttpClient())->createCustomer([
        'name' => 'Ana',
        'cpfCnpj' => '12345678900',
    ]))->toThrow(RuntimeException::class, 'CPF/CNPJ inválido.');

    // The error is logged for diagnosis, but the PII-bearing request payload is not.
    expect($logged)->not->toBeEmpty();
    $context = $logged[0];
    expect($context['errors'])->toContain('CPF/CNPJ inválido.');
    expect($context)->not->toHaveKey('cpfCnpj');
    expect($context['errors'])->not->toContain('12345678900');
});

it('lets a connection/timeout failure propagate instead of hanging', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timeout');
    });

    expect(fn () => (new AsaasHttpClient())->getPayment('pay_1'))
        ->toThrow(\Illuminate\Http\Client\ConnectionException::class);
});
