<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Services\BroadcastService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aba "Canais" — os broadcasts das performers que o membro SEGUE (roadmap social,
 * Onda 2). Grátis, direção performer→seguidor; sem resposta no canal (quem quer
 * falar usa o CTA que leva ao chat pago). A regra e a máscara vivem no
 * BroadcastService; o controller só delega.
 */
class ChannelController extends Controller
{
    public function __construct(private BroadcastService $broadcasts) {}

    public function index(Request $request): Response
    {
        $messages = $this->broadcasts->feedForMember($request->user())->all();

        // Abrir a aba ZERA o badge de não-vistos: assenta o watermark em now()
        // DEPOIS de montar a lista. A nav (nav_counts) é prop lazy do Inertia,
        // avaliado no render (depois deste método), então o badge já sai zerado
        // nesta resposta — mesma mecânica dos corações.
        $this->broadcasts->markSeenForMember($request->user());

        return Inertia::render('Consumer/Channels/Index', [
            'messages' => $messages,
        ]);
    }
}
