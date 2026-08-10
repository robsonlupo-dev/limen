<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Services\PerformerHeartService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Performers interessadas em você" — a lista de corações RECEBIDOS pelo membro.
 *
 * Grátis: o coração é o "interesse grátis" do catálogo, e o membro vê quem curtiu
 * sem pagar nada, COM a identidade da performer (ela não é anônima aqui). É a
 * assimetria oposta do painel de visitantes: lá o membro é exposto à performer sob
 * FanAlias; aqui é a performer que se expõe ao membro, com a identidade pública do
 * catálogo (nome artístico, avatar, slug). Nada de PII, nada de tier.
 *
 * A regra e a máscara vivem no PerformerHeartService; o controller só delega.
 */
class HeartsController extends Controller
{
    public function __construct(private PerformerHeartService $hearts) {}

    public function index(Request $request): Response
    {
        $performers = $this->hearts->listForMember($request->user())->all();

        // Abrir a tela ZERA o contador de não-vistos da nav: assenta o watermark
        // em now() DEPOIS de montar a lista. A nav (nav_counts) é um prop lazy do
        // Inertia, avaliado no render — ou seja, depois deste método —, então a
        // bolinha já sai zerada nesta mesma resposta. Mesma mecânica do read_at
        // marcado ao abrir uma conversa.
        $this->hearts->markSeenForMember($request->user());

        return Inertia::render('Consumer/Hearts/Index', [
            'performers' => $performers,
        ]);
    }
}
