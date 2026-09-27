<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\SetPerformerStatusRequest;
use App\Services\PerformerStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Status do dia da performer (roadmap social, Onda 1a). Definir/limpar. A tela é a
 * de edição de perfil; aqui só grava pela dona única (PerformerStatusService).
 */
class StatusController extends Controller
{
    public function __construct(private PerformerStatusService $statuses) {}

    public function save(SetPerformerStatusRequest $request): RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        if (! $profile) {
            return redirect()->route('performer.onboarding');
        }

        $this->statuses->set($profile, $request->validated());

        return back()->with('status', 'Status atualizado.');
    }

    public function clear(Request $request): RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        if ($profile) {
            $this->statuses->clear($profile);
        }

        return back()->with('status', 'Status removido.');
    }
}
