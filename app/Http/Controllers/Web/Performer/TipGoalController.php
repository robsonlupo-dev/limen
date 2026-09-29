<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\SetTipGoalRequest;
use App\Services\TipGoalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Metas de gorjeta da performer (roadmap social, Onda 4 — §4.2). Definir/encerrar. A
 * tela é a de edição de perfil; aqui só grava pela dona única (TipGoalService).
 */
class TipGoalController extends Controller
{
    public function __construct(private TipGoalService $goals) {}

    public function save(SetTipGoalRequest $request): RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        if (! $profile) {
            return redirect()->route('performer.onboarding');
        }

        $this->goals->set($profile, $request->validated());

        return back()->with('status', 'Meta atualizada.');
    }

    public function end(Request $request): RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        if ($profile) {
            $this->goals->end($profile);
        }

        return back()->with('status', 'Meta encerrada.');
    }
}
