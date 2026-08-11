<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PerformerProfile;
use App\Models\PerformerVoiceIntro;
use App\Services\VoiceIntroStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serving PÚBLICO da intro de voz aprovada (feat/voice-intro). GRÁTIS para
 * qualquer um ouvir no perfil — membro OU visitante deslogado (a mesma rota
 * alimenta Catalog/Show e Performers/Show). É isca, não conteúdo pago: nenhum
 * paywall, nenhum token, nenhum ledger.
 *
 * DUAS invariantes:
 *  1. Só serve `approved` — processing/pending/rejected/failed dão 404. A
 *     moderação humana é o gate; áudio não-aprovado NUNCA sai por aqui.
 *  2. Só de performer DE PÉ (perfil verificado + conta ativa) — a intro de uma
 *     performer depois suspensa/em KYC some, como o resto do perfil dela.
 *
 * Sem URL assinada: autorização por request (o estado de aprovação/atividade pode
 * mudar), Content-Type FIXO no servidor (nós produzimos o MP3). Disco privado
 * `serve => false` — nunca URL de disco.
 */
class VoiceIntroPublicController extends Controller
{
    public function __construct(private VoiceIntroStore $store) {}

    public function audio(Request $request, PerformerProfile $profile): BinaryFileResponse
    {
        // Performer de pé: verificada, conta ativa, não soft-deletada. Mesmo
        // recorte de reachability do serving de conteúdo.
        $reachable = $profile->is_verified
            && $profile->deleted_at === null
            && $profile->user
            && $profile->user->status === 'active';

        abort_unless($reachable, 404);

        $intro = PerformerVoiceIntro::where('performer_profile_id', $profile->id)
            ->approved()
            ->where('path', '!=', '')
            ->first();

        abort_if($intro === null, 404);

        return response()->file($this->store->absolutePath($intro->path), [
            'Content-Type' => 'audio/mpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="apresentacao.mp3"',
            // no-store: a intro pode ser recusada/trocada; um cache compartilhado
            // serviria bytes que a moderação já derrubou.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
