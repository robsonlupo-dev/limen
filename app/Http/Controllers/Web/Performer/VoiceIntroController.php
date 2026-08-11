<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\VoiceProcessingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreVoiceIntroRequest;
use App\Models\PerformerVoiceIntro;
use App\Services\PerformerVoiceIntroService;
use App\Services\VoiceIntroStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Gestão da intro de voz pela PRÓPRIA performer (feat/voice-intro). Só performer
 * ATIVA (`performer-active`). O áudio enviado é higienizado por ffmpeg num job e
 * DEPOIS moderado por um humano — a performer vê o status aqui, nunca publica
 * direto.
 */
class VoiceIntroController extends Controller
{
    public function __construct(
        private PerformerVoiceIntroService $intros,
        private VoiceIntroStore $store,
    ) {}

    /** Tela de gestão: grava/envia + status atual. */
    public function edit(Request $request): InertiaResponse
    {
        Gate::authorize('performer-active');

        return Inertia::render('Performer/VoiceIntro/Edit', [
            'intro' => $this->intros->forOwner($request->user()->performerProfile),
            'maxSeconds' => (int) config('voice.max_duration_seconds'),
            'maxBytes' => (int) config('voice.max_bytes'),
        ]);
    }

    public function store(StoreVoiceIntroRequest $request): JsonResponse
    {
        Gate::authorize('performer-active');

        try {
            $this->intros->upload($request->user()->performerProfile, $request->file('audio'));
        } catch (VoiceProcessingException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json([
            'message' => 'Áudio recebido. Ele passará por revisão antes de aparecer no seu perfil.',
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('performer-active');

        $this->intros->remove($request->user()->performerProfile);

        return response()->json(['message' => 'Apresentação de voz removida.']);
    }

    /**
     * Serving da PRÓPRIA intro para a performer (preview de gestão) — qualquer
     * status COM bytes (pendente/aprovada/recusada). A dona sempre ouve a própria;
     * não é o serving público (esse só serve approved). Content-Type FIXO: nós
     * produzimos o MP3.
     */
    public function audio(Request $request): BinaryFileResponse
    {
        Gate::authorize('performer-active');

        $intro = PerformerVoiceIntro::where('performer_profile_id', $request->user()->performerProfile->id)
            ->where('path', '!=', '')
            ->first();

        abort_if($intro === null, 404);

        return response()->file($this->store->absolutePath($intro->path), [
            'Content-Type' => 'audio/mpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="apresentacao.mp3"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
