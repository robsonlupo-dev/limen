<?php

namespace App\Http\Controllers\Web\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\ModerateVoiceIntroRequest;
use App\Models\PerformerVoiceIntro;
use App\Services\PerformerVoiceIntroService;
use App\Services\VoiceIntroStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fila de moderação das intros de voz (feat/voice-intro). Sob `/moderacao/*`
 * (`auth` + `moderator.access` — moderador OU admin), a mesma porta da fila de
 * denúncias e do evidence viewer.
 *
 * Ao contrário da fila de denúncias, aqui NÃO há PII de membro: a intro é conteúdo
 * da PERFORMER, uma identidade pública verificada. O moderador vê o nome artístico
 * dela e OUVE o áudio (endpoint dedicado, throttlado) antes de aprovar/recusar. A
 * moderação é PRÉ-publicação: o áudio só vai ao ar depois do `approved`, então
 * `pending` é a fila, e o serving do moderador toca qualquer status COM bytes.
 *
 * Motivo do controle (decisão de PO): o áudio dribla o filtro de texto do chat —
 * por voz a performer poderia negociar encontro/passar contato (risco art. 228).
 * Anti-CSAM não se aplica a áudio; o humano é o gate.
 */
class VoiceIntroModerationController extends Controller
{
    public function __construct(
        private PerformerVoiceIntroService $intros,
        private VoiceIntroStore $store,
    ) {}

    /** A fila de pendentes, na ordem de chegada, com o player. */
    public function index(): Response
    {
        $pending = PerformerVoiceIntro::query()
            ->pending()
            ->with('performerProfile:id,slug,stage_name')
            ->orderBy('id')
            ->paginate(50)
            ->through(fn (PerformerVoiceIntro $intro) => [
                'id' => $intro->id,
                'stage_name' => $intro->performerProfile?->stage_name,
                'slug' => $intro->performerProfile?->slug,
                'duration_seconds' => $intro->duration_seconds,
                'created_at' => $intro->created_at,
                // O moderador OUVE por este endpoint dedicado (throttlado); os
                // bytes nunca entram na prop da página.
                'audio_url' => route('moderacao.voice-intros.audio', $intro->id),
            ]);

        return Inertia::render('Moderacao/VoiceIntros/Index', [
            'intros' => $pending,
            'pendingCount' => PerformerVoiceIntro::pending()->count(),
        ]);
    }

    /** Aprova ou recusa (com motivo). A mutação passa SÓ pelo serviço. */
    public function update(ModerateVoiceIntroRequest $request, PerformerVoiceIntro $intro): RedirectResponse
    {
        $validated = $request->validated();

        if ($validated['status'] === PerformerVoiceIntro::STATUS_APPROVED) {
            $this->intros->approve($intro, $request->user());
            $flash = "Áudio #{$intro->id} aprovado.";
        } else {
            $this->intros->reject($intro, $request->user(), $validated['reject_reason'] ?? null);
            $flash = "Áudio #{$intro->id} recusado.";
        }

        return redirect()
            ->route('moderacao.voice-intros.index')
            ->with('success', $flash);
    }

    /**
     * Serve os bytes para o moderador OUVIR na fila. Qualquer status COM bytes
     * (a fila só lista pending, mas o serving não presume o status — evita
     * divergência com a página). Content-Type FIXO: nós produzimos o MP3.
     */
    public function audio(PerformerVoiceIntro $intro): BinaryFileResponse
    {
        abort_if($intro->path === '', 404);

        return response()->file($this->store->absolutePath($intro->path), [
            'Content-Type' => 'audio/mpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="apresentacao.mp3"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
