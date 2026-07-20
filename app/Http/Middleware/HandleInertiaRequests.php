<?php

namespace App\Http\Middleware;

use App\Services\DiscreteModeService;
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
                ] : null,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            'ageConfirmed' => (bool) $request->cookie('limen_age_confirmed'),
            'introSeen' => (bool) $request->cookie('limen_intro_seen'),
        ]);
    }
}
