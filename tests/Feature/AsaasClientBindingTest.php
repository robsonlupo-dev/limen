<?php

use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\AsaasHttpClient;
use App\Services\Asaas\FakeAsaasClient;

function resolveAsaasClient(): AsaasClientInterface
{
    app()->forgetInstance(AsaasClientInterface::class);

    return app(AsaasClientInterface::class);
}

it('binds the fake client when the driver is fake', function () {
    config(['asaas.driver' => 'fake']);

    expect(resolveAsaasClient())->toBeInstanceOf(FakeAsaasClient::class);
});

it('binds the http client when the driver is http (non-testing env)', function () {
    // environment('testing') short-circuits to fake, so simulate a real env.
    app()->detectEnvironment(fn () => 'local');
    config(['asaas.driver' => 'http']);

    expect(resolveAsaasClient())->toBeInstanceOf(AsaasHttpClient::class);
});

it('refuses the fake client in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['asaas.driver' => 'fake']);

    expect(fn () => resolveAsaasClient())
        ->toThrow(RuntimeException::class, 'fake Asaas client in production');
});
