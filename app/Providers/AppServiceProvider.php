<?php

namespace App\Providers;

use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\AsaasHttpClient;
use App\Services\Asaas\FakeAsaasClient;
use App\Services\Kyc\FakeKycClient;
use App\Services\Kyc\KycClientInterface;
use App\Services\Kyc\KycHttpClient;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AsaasClientInterface::class, function () {
            if ($this->app->environment('testing')) {
                return new FakeAsaasClient();
            }

            return new AsaasHttpClient();
        });

        $this->app->singleton(KycClientInterface::class, function () {
            if ($this->app->environment('testing') || config('kyc.provider') === 'fake') {
                return new FakeKycClient();
            }

            return new KycHttpClient();
        });
    }

    public function boot(): void
    {
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            return config('app.url') . '/reset-password?token=' . $token . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
