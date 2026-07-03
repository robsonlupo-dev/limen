<?php

namespace App\Providers;

use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\AsaasHttpClient;
use App\Services\Asaas\FakeAsaasClient;
use App\Services\Kyc\FakeKycClient;
use App\Services\Kyc\KycClientInterface;
use App\Services\Kyc\KycHttpClient;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AsaasClientInterface::class, function () {
            $useFake = $this->app->environment('testing') || config('asaas.driver') === 'fake';

            // Production must never use the fake gateway (would issue unpayable
            // charges). Fail loudly rather than silently if the flag leaks.
            if ($useFake && $this->app->environment('production')) {
                throw new \RuntimeException(
                    'Refusing to use the fake Asaas client in production. Set ASAAS_DRIVER=http with real credentials.'
                );
            }

            return $useFake ? new FakeAsaasClient() : new AsaasHttpClient();
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
            return route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });

        Gate::define('performer-active', function (User $user) {
            return $user->role === 'performer' && $user->status === 'active';
        });
    }
}
