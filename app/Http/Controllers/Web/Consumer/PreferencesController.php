<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Http\Requests\ToggleDiscreteModeRequest;
use App\Models\User;
use App\Services\DiscreteModeService;
use Illuminate\Http\RedirectResponse;
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
    public function __construct(private DiscreteModeService $discreteMode) {}

    public function index(): Response
    {
        return Inertia::render('Consumer/Settings');
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
