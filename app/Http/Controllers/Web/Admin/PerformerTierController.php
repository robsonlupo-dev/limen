<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\PerformerProfile;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PerformerTierController extends Controller
{
    /**
     * Concede um tier ao perfil da performer. Protegido por auth + role:admin
     * (ver routes/web.php).
     *
     * forceFill: `tier`/`tier_granted_at`/`tier_granted_by` estão fora do
     * $fillable de propósito — quem concedeu é autoridade do servidor, nunca
     * payload. Mesmo padrão do `reviewed_by` das denúncias.
     */
    public function store(Request $request, PerformerProfile $profile): RedirectResponse
    {
        $validated = $request->validate([
            'tier' => ['required', Rule::in(PerformerProfile::TIERS)],
        ]);

        // Re-grant do mesmo tier é no-op: preserva o tier_granted_at original
        // (a data do grant de verdade) e não polui o audit com linha vazia.
        if ($profile->tier === $validated['tier']) {
            return back()->with('info', "Performer já possui o tier {$validated['tier']}.");
        }

        $previous = $profile->tier;

        // Atômico: se o audit falhar, o grant faz rollback — mudança de
        // privilégio sem trilha não pode existir.
        DB::transaction(function () use ($request, $profile, $validated, $previous): void {
            $profile->forceFill([
                'tier' => $validated['tier'],
                'tier_granted_at' => now(),
                'tier_granted_by' => $request->user()->id,
            ])->save();

            Audit::log('performer.tier_granted', $profile, [
                'tier' => $validated['tier'],
                'previous_tier' => $previous,
            ]);
        });

        return back()->with('success', "Tier {$validated['tier']} concedido a {$profile->stage_name}.");
    }
}
