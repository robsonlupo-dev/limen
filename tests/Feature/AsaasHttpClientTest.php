<?php

use App\Services\Asaas\AsaasHttpClient;
use App\Services\Asaas\AsaasRequestException;
use App\Services\Asaas\AsaasUnavailableException;
use Illuminate\Http\Client\ConnectionException;
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

    $result = (new AsaasHttpClient)->createCustomer(['name' => 'Ana']);

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

    expect(fn () => (new AsaasHttpClient)->createCustomer([
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

it('maps a random PIX key to Asaas EVP (not RANDOM) on a transfer', function () {
    Http::fake([
        'sandbox.asaas.com/api/v3/transfers' => Http::response(['id' => 'tr_1', 'status' => 'PENDING'], 200),
    ]);

    (new AsaasHttpClient)->createTransfer([
        'pix_key' => 'a1b2c3d4-0000-0000-0000-000000000000',
        'pix_key_type' => 'random',
        'value' => 32.18,
        'description' => 'Limen payout #1',
        'external_reference' => 'payout_1',
    ]);

    Http::assertSent(fn ($request) => $request['pixAddressKeyType'] === 'EVP'
        && $request['pixAddressKey'] === 'a1b2c3d4-0000-0000-0000-000000000000');
});

it('maps cpf/email/phone key types to the Asaas enum', function () {
    Http::fake([
        'sandbox.asaas.com/api/v3/transfers' => Http::response(['id' => 'tr_1'], 200),
    ]);

    $client = new AsaasHttpClient;

    foreach (['cpf' => 'CPF', 'email' => 'EMAIL', 'phone' => 'PHONE'] as $internal => $asaas) {
        $client->createTransfer([
            'pix_key' => 'k',
            'pix_key_type' => $internal,
            'value' => 10.0,
        ]);
    }

    Http::assertSent(fn ($r) => ($r['pixAddressKeyType'] ?? null) === 'CPF');
    Http::assertSent(fn ($r) => ($r['pixAddressKeyType'] ?? null) === 'EMAIL');
    Http::assertSent(fn ($r) => ($r['pixAddressKeyType'] ?? null) === 'PHONE');
});

it('rejects an unknown PIX key type before calling Asaas', function () {
    Http::fake();

    expect(fn () => (new AsaasHttpClient)->createTransfer([
        'pix_key' => 'k',
        'pix_key_type' => 'iban',
        'value' => 10.0,
    ]))->toThrow(RuntimeException::class, 'Unsupported PIX key type');

    Http::assertNothingSent();
});

it('wraps a connection/timeout failure as an ambiguous AsaasUnavailableException', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: timeout');
    });

    expect(fn () => (new AsaasHttpClient)->getPayment('pay_1'))
        ->toThrow(AsaasUnavailableException::class);
});

it('classifies a 4xx as a definitive AsaasRequestException', function () {
    Http::fake([
        'sandbox.asaas.com/api/v3/payments/pay_1' => Http::response(['errors' => [['description' => 'bad']]], 400),
    ]);

    expect(fn () => (new AsaasHttpClient)->getPayment('pay_1'))
        ->toThrow(AsaasRequestException::class);
});

it('classifies a 5xx as an ambiguous AsaasUnavailableException', function () {
    Http::fake([
        'sandbox.asaas.com/api/v3/transfers' => Http::response('gateway error', 503),
    ]);

    expect(fn () => (new AsaasHttpClient)->createTransfer([
        'pix_key' => 'k@e.com',
        'pix_key_type' => 'email',
        'value' => 10.0,
    ]))->toThrow(AsaasUnavailableException::class);
});

/**
 * 408 e 429 são 4xx, mas não querem dizer "não processado" — um proxy pode
 * devolvê-los depois de o Asaas já ter aceitado a transferência. Classificá-los
 * como definitivos fazia o PayoutService estornar a reserva de um payout que
 * talvez já estivesse pagando: pagamento em dobro.
 */
it('classifies a 429 as ambiguous, not a definitive rejection', function () {
    Http::fake([
        'sandbox.asaas.com/api/v3/transfers' => Http::response(['errors' => [['description' => 'rate limited']]], 429),
    ]);

    expect(fn () => (new AsaasHttpClient)->createTransfer([
        'pix_key' => 'k@e.com',
        'pix_key_type' => 'email',
        'value' => 10.0,
    ]))->toThrow(AsaasUnavailableException::class);
});

it('classifies a 408 as ambiguous, not a definitive rejection', function () {
    Http::fake([
        'sandbox.asaas.com/api/v3/transfers' => Http::response('request timeout', 408),
    ]);

    expect(fn () => (new AsaasHttpClient)->createTransfer([
        'pix_key' => 'k@e.com',
        'pix_key_type' => 'email',
        'value' => 10.0,
    ]))->toThrow(AsaasUnavailableException::class);
});

it('keeps a 400 definitive — only 408/429 are the 4xx exceptions', function () {
    Http::fake([
        'sandbox.asaas.com/api/v3/transfers' => Http::response(['errors' => [['description' => 'invalid pix key']]], 400),
    ]);

    expect(fn () => (new AsaasHttpClient)->createTransfer([
        'pix_key' => 'k@e.com',
        'pix_key_type' => 'email',
        'value' => 10.0,
    ]))->toThrow(AsaasRequestException::class);
});
