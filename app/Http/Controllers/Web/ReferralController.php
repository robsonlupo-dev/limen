<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\ReferralService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tela "meu link" do programa de indicação (feat/referral-program, §2.1 do desenho).
 * MVP: só o link + o código + copiar. Sem lista de indicados, sem contador, sem
 * status — isso é Fase 2. Serve membro e performer igualmente.
 */
class ReferralController extends Controller
{
    public function __construct(private ReferralService $referrals) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        // Gera o código sob demanda (backfill para contas anteriores ao programa).
        $code = $this->referrals->ensureCode($user);

        return Inertia::render('Referral/Index', [
            'enabled' => $this->referrals->enabled(),
            'code' => $code,
            'link' => route('register', ['ref' => $code]),
        ]);
    }
}
