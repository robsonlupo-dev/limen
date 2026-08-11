<?php

namespace App\Services;

use App\Exceptions\VoiceProcessingException;
use App\Jobs\ProcessVoiceIntro;
use App\Jobs\SendVoiceIntroApprovedEmail;
use App\Jobs\SendVoiceIntroRejectedEmail;
use App\Models\PerformerProfile;
use App\Models\PerformerVoiceIntro;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida da intro de voz da performer (feat/voice-intro): enviar, remover,
 * ler a própria, e a MODERAÇÃO (aprovar/recusar). UMA intro por performer —
 * regravar SUBSTITUI a anterior. A regra de "quem OUVE no perfil" é do serving
 * (só approved + performer de pé); aqui é a peça e o dono/moderador dela.
 */
class PerformerVoiceIntroService
{
    public function __construct(
        private VoiceIntroStore $store,
        private VoiceProcessingService $voice,
    ) {}

    /**
     * Recebe uma intro. O gate de DURAÇÃO roda AQUI (ffprobe no upload) → 422
     * imediato se exceder, antes de dispensar o job de re-encode. Gravação sem
     * duração no header (webm) é deferida ao job. O gate de TAMANHO é do Form
     * Request (max 5 MB).
     *
     * SUBSTITUI a intro anterior (qualquer status): a nova nasce `processing` e o
     * job a leva a `pending` (aguardando moderação) — nunca vai ao ar sem um humano.
     *
     * @throws VoiceProcessingException
     */
    public function upload(PerformerProfile $profile, UploadedFile $file): PerformerVoiceIntro
    {
        // Duração lida do arquivo do upload (ainda no temporário do PHP). ffprobe
        // também valida que É mídia (renomeado/corrompido → unreadable → 422).
        // null = container sem duração no header → reconferido no job.
        $duration = $this->voice->probeDurationSeconds($file->getRealPath());
        $max = (int) config('voice.max_duration_seconds');

        if ($duration !== null && $duration > $max) {
            throw VoiceProcessingException::tooLong($max);
        }

        // Persiste o CRU num tmp do disco (o temporário do PHP some no fim do
        // request); o job pega dali.
        $rawPath = $this->store->storeRaw($file, $profile->id);

        try {
            // Substitui a anterior sob lock do perfil: dois uploads concorrentes
            // serializam, e o UNIQUE(performer_profile_id) nunca é violado. Devolve
            // a nova linha + o path antigo (para apagar os bytes DEPOIS do commit).
            [$intro, $oldPath] = DB::transaction(function () use ($profile) {
                DB::table('performer_profiles')->where('id', $profile->id)->lockForUpdate()->first();

                $existing = PerformerVoiceIntro::where('performer_profile_id', $profile->id)->lockForUpdate()->first();
                $oldPath = $existing?->path;
                $existing?->delete();

                $intro = new PerformerVoiceIntro;
                $intro->performer_profile_id = $profile->id;
                $intro->status = PerformerVoiceIntro::STATUS_PROCESSING;
                $intro->path = '';
                $intro->save();

                return [$intro, $oldPath];
            });
        } catch (\Throwable $e) {
            // Falhou a linha → não deixa o cru órfão no disco (best-effort).
            try {
                $this->store->delete($rawPath);
            } catch (\Throwable) {
            }

            throw $e;
        }

        // Bytes antigos e dispatch DEPOIS do commit (precedente do CallService — não
        // afterCommit, que não roda sob RefreshDatabase).
        if ($oldPath) {
            try {
                $this->store->delete($oldPath);
            } catch (\Throwable) {
            }
        }

        Audit::log('voice_intro.uploaded', $intro);

        ProcessVoiceIntro::dispatch($intro->id, $rawPath);

        return $intro;
    }

    /**
     * Remove a intro da performer (bytes PRIMEIRO, depois a linha). Idempotente:
     * sem intro é no-op. Não há congelamento por denúncia — a moderação aqui é
     * PRÉ-publicação (o áudio só vai ao ar depois de aprovado), não uma fila de
     * denúncia sobre conteúdo já no ar.
     */
    public function remove(PerformerProfile $profile): void
    {
        $intro = PerformerVoiceIntro::where('performer_profile_id', $profile->id)->first();

        if ($intro === null) {
            return;
        }

        if ($intro->path !== '') {
            $this->store->delete($intro->path);
        }

        $intro->delete();

        Audit::log('voice_intro.removed', $intro, ['id' => $intro->id]);
    }

    /**
     * A intro da própria performer para a tela de gestão, ou null. Só o que a tela
     * precisa: status, duração, motivo de recusa/falha, e a URL de preview (só
     * quando há bytes — approved OU já processada; nunca em processing/failed).
     *
     * @return array<string, mixed>|null
     */
    public function forOwner(PerformerProfile $profile): ?array
    {
        $intro = PerformerVoiceIntro::where('performer_profile_id', $profile->id)->first();

        if ($intro === null) {
            return null;
        }

        $hasBytes = $intro->path !== '' && in_array($intro->status, [
            PerformerVoiceIntro::STATUS_PENDING,
            PerformerVoiceIntro::STATUS_APPROVED,
            PerformerVoiceIntro::STATUS_REJECTED,
        ], true);

        return [
            'status' => $intro->status,
            'duration_seconds' => $intro->duration_seconds,
            // Motivo aparece na recusa (moderador) E na falha técnica (job).
            'reject_reason' => in_array($intro->status, [
                PerformerVoiceIntro::STATUS_REJECTED,
                PerformerVoiceIntro::STATUS_FAILED,
            ], true) ? $intro->reject_reason : null,
            // Preview da própria performer (a dona sempre ouve a própria, inclusive
            // pendente/recusada). Serving autorizado por dono, não é o público.
            'audio_url' => $hasBytes ? route('performer.voice-intro.audio') : null,
        ];
    }

    /**
     * Moderação: aprova a intro. Só faz sentido sobre uma PENDING (a fila só mostra
     * pending); idempotente/no-op fora disso. moderated_by/moderated_at por
     * forceFill — autoridade do servidor, nunca mass assignment.
     */
    public function approve(PerformerVoiceIntro $intro, User $moderator): void
    {
        if (! $intro->isPending()) {
            return;
        }

        $intro->forceFill([
            'status' => PerformerVoiceIntro::STATUS_APPROVED,
            'moderated_by' => $moderator->id,
            'moderated_at' => now(),
            'reject_reason' => null,
        ])->save();

        // Audit da AÇÃO do moderador — id + decisão, nada do áudio.
        Audit::log('voice_intro.approved', $intro, ['moderator_id' => $moderator->id]);

        // Avisa a performer que a voz está no ar. Sem afterCommit de propósito:
        // approve() não roda em transação (o controller chama direto), e afterCommit
        // não dispara sob RefreshDatabase — mesma convenção do dispatch do upload.
        $this->notifyPerformer($intro, fn (User $u) => SendVoiceIntroApprovedEmail::dispatch($u));
    }

    /**
     * Moderação: recusa a intro (com motivo opcional). A performer vê o motivo e
     * regrava. Os bytes FICAM (a performer pode querer ouvir por que foi recusada,
     * e a próxima gravação os substitui); só não vão ao ar.
     */
    public function reject(PerformerVoiceIntro $intro, User $moderator, ?string $reason = null): void
    {
        if (! $intro->isPending()) {
            return;
        }

        $intro->forceFill([
            'status' => PerformerVoiceIntro::STATUS_REJECTED,
            'moderated_by' => $moderator->id,
            'moderated_at' => now(),
            'reject_reason' => $reason,
        ])->save();

        Audit::log('voice_intro.rejected', $intro, ['moderator_id' => $moderator->id]);

        // Avisa a performer da recusa (com o motivo) e a convida a regravar. A
        // mensagem ancora a recusa nos Termos — é recusa de CONTEÚDO, distinta da
        // falha técnica (que é notificada pelo job). Sem afterCommit (ver approve()).
        $this->notifyPerformer($intro, fn (User $u) => SendVoiceIntroRejectedEmail::dispatch($u, $reason));
    }

    /**
     * Despacha uma notificação à performer dona da intro, resolvendo o User pelo
     * perfil. Guarda contra perfil/usuário ausente (não deveria acontecer numa
     * intro pendente, mas não vale disparar e-mail sem destinatário).
     *
     * @param  callable(User): void  $dispatch
     */
    private function notifyPerformer(PerformerVoiceIntro $intro, callable $dispatch): void
    {
        $user = $intro->performerProfile?->user;

        if ($user !== null && $user->email !== null) {
            $dispatch($user);
        }
    }
}
