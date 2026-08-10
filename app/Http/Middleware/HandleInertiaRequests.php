<?php

namespace App\Http\Middleware;

use App\Services\Captcha\CaptchaManager;
use App\Services\DiscreteModeService;
use App\Services\NavBadgeService;
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
                    // Visibilidade EFETIVA no catálogo de membros (Sprint 16).
                    // Só faz sentido para o membro (consumer); a tela de
                    // configurações usa para posicionar o toggle. `null` (nunca
                    // escolheu) já vem resolvido pelo padrão-por-tier.
                    'visible_to_performers' => $user->role === 'consumer'
                        ? $user->isVisibleToPerformers()
                        : null,
                    // Sons de notificação (Sprint 16). Global porque o gate de som
                    // é consultado FORA da tela de configurações: o composable de
                    // áudio precisa da preferência em mãos onde quer que o evento
                    // (mensagem/gorjeta/chamada) chegue. Já resolvido com o padrão
                    // "ausente = ON" — o front nunca reimplementa esse default.
                    'notification_preferences' => $user->notificationSoundPreferences(),
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
            // Tutorial de primeira entrada no catálogo (membro). Flag SEPARADA do
            // `introSeen`: aquele é a splash de marca do visitante, disparada por
            // GuestLayout na landing/login/cadastro — ou seja, já vem marcada
            // ANTES de o membro chegar ao catálogo. Reusá-la faria o tutorial
            // nunca aparecer para quem entrou pelo funil normal.
            'tutorialSeen' => (bool) $request->cookie('limen_tutorial_seen'),
            // Captcha (hCaptcha ou Turnstile, conforme CAPTCHA_PROVIDER). Só o
            // provider + a chave PÚBLICA (a secreta nunca sai do servidor), e só
            // quando ligado — com `none`, a tela não recebe sitekey e o
            // componente do widget nem monta, então nenhuma requisição sai para
            // terceiro. O CaptchaManager é a dona única da escolha do provedor.
            //
            // Compartilhado globalmente, mas isso NÃO significa carregado
            // globalmente: quem decide buscar o SDK é o componente Captcha.vue,
            // que só existe nas telas de login e cadastro. O script de terceiro
            // não pode entrar no app.blade.php — ver docs/PIXEL_AUDIT.md.
            'captcha' => app(CaptchaManager::class)->sharedProps(),
            // Feature flags de dark launch (Sprint 15). Compartilhadas para o front
            // decidir entre renderizar a feature (live/chamada) ou o placeholder "Em
            // breve" (PR #144). É só o BOOLEANO — a autoridade real continua sendo o
            // middleware `feature:*` (403) + o backstop do LiveKitService; um front
            // adulterado não liga nada, só muda o que a tela desenha.
            'features' => [
                'live_enabled' => (bool) config('features.live_enabled'),
                'call_enabled' => (bool) config('features.call_enabled'),
            ],
            // Contadores de "não vistos" da nav (feat/activity-badges): bolinhas ao
            // lado de "Mensagens" e "Interessadas". CLOSURE de propósito — o Inertia
            // avalia props-closure no RENDER, depois do controller, então uma tela
            // que zera o watermark ao abrir (HeartsController marca hearts_seen_at;
            // ChatController::show marca read_at) já devolve a bolinha zerada nesta
            // mesma resposta. Só para usuário logado; a autoridade de leitura segue
            // no backend (o número respeita o paywall do chat). Ver NavBadgeService.
            'nav_counts' => $user
                ? fn () => app(NavBadgeService::class)->for($request->user())
                : ['messages' => 0, 'hearts' => 0],
        ]);
    }
}
