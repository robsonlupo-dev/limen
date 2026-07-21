<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Http\Requests\ToggleDiscreteModeRequest;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\DiscreteModeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Porta web (sessão + CSRF) do Modo Discreto. Existe separada da API porque o
 * front-end do Limen fala com rotas web, não com api/* — não há statefulApi(),
 * então a rota Sanctum só responde a Bearer token. A regra em si é a mesma
 * (DiscreteModeService); aqui só muda a resposta: redirect com flash.
 */
class PreferencesController extends Controller
{
    public function __construct(
        private DiscreteModeService $discreteMode,
        private DeletionService $deletion,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Consumer/Settings', [
            // Estado da exclusão vai como prop DESTA página, não no share()
            // global: é uma consulta a payouts que só esta tela usa, e o share
            // roda em toda resposta Inertia da aplicação.
            'deletion' => [
                'requested_at' => $user->deletion_requested_at?->toIso8601String(),
                'scheduled_at' => $user->deletion_scheduled_at?->toIso8601String(),
                'confirmed' => $user->deletion_confirmed_at !== null,
                'blocking_payouts' => $this->deletion->blockingPayoutCount($user),
                'grace_days' => DeletionService::GRACE_DAYS,
            ],
        ]);
    }

    public function toggleDiscreteMode(ToggleDiscreteModeRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $desired = $request->desiredValue() ?? ! $user->discrete_mode;

        // 403 e não flash: quem chega aqui inelegível não veio pela UI (o toggle
        // só é renderizado para quem pode), então é requisição forjada, não erro
        // de usuário. Desligar, esse sim, é sempre permitido — ver mayApply().
        abort_unless(
            $this->discreteMode->mayApply($user, $desired),
            403,
            'Modo Discreto disponível apenas para membros Black e Founders Circle',
        );

        $newValue = $this->discreteMode->apply($user, $desired);

        return back()->with('success', 'Modo Discreto ' . ($newValue ? 'ativado' : 'desativado'));
    }
}
