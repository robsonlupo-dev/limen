<?php

namespace App\Http\Middleware;

use App\Services\DiscreteModeService;
use App\Services\PrivacyPerkService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        // Resolvido uma vez: activeCircleSlug() já consultava o Círculo ativo, e
        // a elegibilidade do Modo Discreto precisa do mesmo dado. Duas chamadas
        // seriam duas queries em TODA resposta Inertia.
        $circle = $user?->activeCircle();

        // Mesma resolução serve aos três perks de privacidade: elegibilidade é
        // rank de tier, e o Círculo já está em mãos.
        $perks = app(PrivacyPerkService::class);
        $perkEligible = $user?->role === 'consumer' && $perks->circleQualifies($circle);
        $perkState = $perks->stateFor($user, $perkEligible);

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                    'email_verified_at' => $user->email_verified_at,
                    // Slug do Círculo ativo (ou null). O front usa para gating de UI;
                    // a autoridade real continua sendo o middleware `circle`.
                    'circle' => $circle?->slug,
                    // Modo Discreto: estado atual e se o tier permite LIGAR. Só
                    // controla a UI — a decisão real é do DiscreteModeService.
                    'discrete_mode' => (bool) $user->discrete_mode,
                    'can_use_discrete_mode' => $user->role === 'consumer'
                        && app(DiscreteModeService::class)->circleQualifies($circle),
                    // Perks de privacidade Black/FC. Global (e não prop da tela
                    // de configurações) porque o gate é consultado fora dela:
                    // `is_invisible` precisa estar em mãos ANTES de qualquer
                    // código de presença decidir anunciar este usuário.
                    'privacy' => $perkState,
                    // Atalho legível para o ponto de aplicação do Status
                    // Invisível. Hoje não existe presença de membro no produto
                    // (nenhum presence channel, nenhum indicador de online do
                    // membro em tela) — este prop É o contrato: quem for
                    // implementar presença tem que checar isto antes de expor o
                    // membro, em vez de descobrir o perk depois do vazamento.
                    'is_invisible' => $perkState['invisible_status'],
                ] : null,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            // Destino da saída rápida (PanicButton). Global porque o botão vive
            // no layout, não numa página.
            'panicRedirectUrl' => config('app.panic_redirect_url'),
            'ageConfirmed' => (bool) $request->cookie('limen_age_confirmed'),
            'introSeen' => (bool) $request->cookie('limen_intro_seen'),
        ]);
    }
}
