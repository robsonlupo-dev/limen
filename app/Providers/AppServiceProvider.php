<?php

namespace App\Providers;

use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaitlistReferral;
use App\Observers\WaitlistEntryObserver;
use App\Observers\WaitlistReferralObserver;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\AsaasHttpClient;
use App\Services\Asaas\FakeAsaasClient;
use App\Services\Kyc\DiditKycClient;
use App\Services\Kyc\FakeKycClient;
use App\Services\Kyc\KycClientInterface;
use App\Services\Kyc\KycHttpClient;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

            return $useFake ? new FakeAsaasClient : new AsaasHttpClient;
        });

        $this->app->singleton(KycClientInterface::class, function () {
            if ($this->app->environment('testing') || config('kyc.provider') === 'fake') {
                return new FakeKycClient;
            }

            return match (config('kyc.provider')) {
                'didit' => new DiditKycClient,
                default => new KycHttpClient,
            };
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

        // Limiters NOMEADOS por rota (fix do 429 ao publicar, UAT 23/10). O
        // `throttle:N,1` INLINE do Laravel chaveia o balde só por usuário (sem a
        // rota), então TODAS as rotas de performer dividiam UM contador e o limite
        // baixo do publish (10/min) estourava quando o painel recarregava cheio de
        // miniaturas (cada `content.image` é um hit no MESMO balde). Limiter nomeado
        // tem balde PRÓPRIO por (nome, usuário/IP) — publish e serving deixam de se
        // atropelar. `by` cai no IP para visitante (serving público).
        RateLimiter::for('performer-publish', fn ($request) => Limit::perMinute(30)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('media-serving', fn ($request) => Limit::perMinute(300)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        Gate::define('performer-active', function (User $user) {
            return $user->role === 'performer' && $user->status === 'active';
        });

        // circle-active: true when the user has any live Círculo. With an
        // optional $minTier ('circle-active', 'prestige') it means "that tier or
        // higher", matching the `circle` middleware.
        Gate::define('circle-active', function (User $user, ?string $minTier = null) {
            $circle = $user->activeCircle();

            if (! $circle) {
                return false;
            }

            if ($minTier === null) {
                return true;
            }

            // Comparação em Circle::tierAtLeast, que já é fail-closed nas duas
            // pontas (tier do usuário ou tier mínimo fora do TIER_ORDER ⇒ nega).
            return $circle->tierAtLeast($minTier);
        });

        WaitlistEntry::observe(WaitlistEntryObserver::class);
        WaitlistReferral::observe(WaitlistReferralObserver::class);
    }
}
