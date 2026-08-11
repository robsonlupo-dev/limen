<?php

namespace App\Jobs;

use App\Exceptions\VoiceProcessingException;
use App\Models\PerformerVoiceIntro;
use App\Services\VoiceIntroStore;
use App\Services\VoiceProcessingService;
use App\Support\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sanitiza a intro de voz FORA do request: re-encode MP3 + strip de TODO metadado.
 * Enquanto não termina, a peça fica `status=processing` e NÃO é servível.
 *
 * Sucesso → status=PENDING (aguardando o moderador) + path/content_hash/
 * duration_seconds. O áudio NUNCA vai a `approved` sozinho — isso é decisão humana
 * na fila de moderação.
 * Falha  → status=FAILED + reject_reason (a performer regrava). Sem retry
 * automático (`$tries=1`): re-encode é barato e a entrada não vai "melhorar"
 * sozinha; o botão de reenviar é o retry.
 *
 * Reconferência de DURAÇÃO: o upload defere o gate quando o container não traz
 * duração no header (webm de MediaRecorder). Aqui, sobre o MP3 já processado (cuja
 * duração é sempre confiável), um clipe acima do teto vira FAILED com too_long —
 * REJEITA, não trunca (decisão de PO: "cap 20s → 422/recusa").
 */
class ProcessVoiceIntro implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $introId,
        public string $rawPath,
    ) {}

    public function handle(VoiceProcessingService $voice, VoiceIntroStore $store): void
    {
        $intro = PerformerVoiceIntro::find($this->introId);

        // Peça sumiu (removida/substituída) ou já resolvida — nada a fazer. Ainda
        // assim limpa o cru para não deixar lixo no disco.
        if ($intro === null || $intro->status !== PerformerVoiceIntro::STATUS_PROCESSING) {
            $this->cleanupRaw($store);

            return;
        }

        $rawAbs = $store->absolutePath($this->rawPath);
        $mp3Tmp = tempnam(sys_get_temp_dir(), 'limen_voice_');

        try {
            $voice->sanitize($rawAbs, $mp3Tmp);

            // Duração do resultado servível (sempre confiável). Reconfere o teto
            // para o caso deferido pelo upload (container sem duração no header).
            $duration = $voice->probeDurationSeconds($mp3Tmp);
            $max = (int) config('voice.max_duration_seconds');

            if ($duration !== null && $duration > $max) {
                throw VoiceProcessingException::tooLong($max);
            }

            $stored = $store->putSanitized($mp3Tmp, $intro->performer_profile_id);

            $intro->forceFill([
                'status' => PerformerVoiceIntro::STATUS_PENDING,
                'path' => $stored['path'],
                'content_hash' => $stored['hash'],
                'duration_seconds' => $duration !== null ? (int) round($duration) : null,
                'reject_reason' => null,
            ])->save();

            Audit::log('voice_intro.processed', $intro);
        } catch (Throwable $e) {
            $this->markFailed($intro, $e);
        } finally {
            @unlink($mp3Tmp);
            $this->cleanupRaw($store);
        }
    }

    /** O job morreu (timeout/OOM/erro fora do try) — marca a peça como failed. */
    public function failed(?Throwable $e): void
    {
        $intro = PerformerVoiceIntro::find($this->introId);

        if ($intro !== null && $intro->status === PerformerVoiceIntro::STATUS_PROCESSING) {
            $this->markFailed($intro, $e);
        }

        Storage::disk(VoiceIntroStore::DISK)->delete($this->rawPath);
    }

    private function markFailed(PerformerVoiceIntro $intro, ?Throwable $e): void
    {
        $reason = $e instanceof VoiceProcessingException
            ? $e->getMessage()
            : 'Não foi possível processar o áudio. Tente enviar novamente.';

        $intro->forceFill([
            'status' => PerformerVoiceIntro::STATUS_FAILED,
            'reject_reason' => $reason,
        ])->save();

        Audit::log('voice_intro.failed', $intro, [
            'reason' => $e instanceof VoiceProcessingException ? $e->reason : 'unknown',
        ]);

        // Avisa a performer da FALHA TÉCNICA (mensagem distinta da recusa de
        // conteúdo — só convida a reenviar). Guarda contra perfil/usuário ausente.
        $user = $intro->performerProfile?->user;

        if ($user !== null && $user->email !== null) {
            SendVoiceIntroFailedEmail::dispatch($user);
        }
    }

    private function cleanupRaw(VoiceIntroStore $store): void
    {
        try {
            Storage::disk(VoiceIntroStore::DISK)->delete($this->rawPath);
        } catch (Throwable) {
            // best-effort; a varredura de tmp é do GC voice:purge-orphan-raw.
        }
    }
}
