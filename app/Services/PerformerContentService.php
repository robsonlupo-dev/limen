<?php

namespace App\Services;

use App\Exceptions\ContentException;
use App\Exceptions\VideoProcessingException;
use App\Jobs\ProcessVideoContent;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\Report;
use App\Support\Audit;
use App\Support\FanAlias;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Ciclo de vida das peças de conteúdo permanente da performer (M.4): publicar,
 * remover, listar as próprias. A regra de ACESSO (quem alcança) é do
 * ContentVisibilityService; aqui é só a peça e o dono dela.
 */
class PerformerContentService
{
    public const OPEN_REPORT_STATUSES = Report::OPEN_STATUSES;

    public function __construct(
        private ContentStore $store,
        private TokenCreditPolicy $creditPolicy,
        private VideoProcessingService $video,
    ) {}

    /**
     * Publica uma peça. Nível e preço são server-validados AQUI (piso 5 + passo 5,
     * M.4) — não no unlock, onde o preço já está gravado. Higieniza e grava os
     * bytes ANTES de criar a linha; nível/preço/path/hash entram por atribuição
     * direta ($fillable vazio).
     */
    public function publish(PerformerProfile $profile, UploadedFile $file, string $level, int $price): PerformerContent
    {
        if (! in_array($level, PerformerContent::LEVELS, true)) {
            throw ContentException::forbidden();
        }

        if (! $this->creditPolicy->isValidContentPrice($price)) {
            throw ContentException::invalidPrice();
        }

        $stored = $this->store->store($file, $profile->id, $profile->user);

        try {
            $content = new PerformerContent;
            $content->performer_profile_id = $profile->id;
            $content->kind = PerformerContent::KIND_PHOTO;
            // Foto está pronta na hora (não passa por job). Explícito para o
            // objeto EM MEMÓRIA já refletir READY — o default do banco só existe
            // na linha, não no atributo recém-criado (senão canView negaria).
            $content->status = PerformerContent::STATUS_READY;
            $content->access_level = $level;
            $content->price_tokens = $price;
            $content->path = $stored['path'];
            $content->content_hash = $stored['hash'];
            $content->save();
        } catch (\Throwable $e) {
            // Linha falhou → não deixa bytes órfãos no disco (best-effort).
            try {
                $this->store->delete($stored['path']);
            } catch (\Throwable) {
            }

            throw $e;
        }

        // Audit: dado da performer sobre a própria publicação (id + nível + preço).
        // Sem membro, sem bytes, sem hash — mesma disciplina do story.published.
        Audit::log('content.published', $content, [
            'access_level' => $level,
            'price_tokens' => $price,
        ]);

        return $content;
    }

    /**
     * Publica um VÍDEO. Diferente da foto (síncrona): o arquivo é higienizado por
     * ffmpeg num JOB assíncrono (re-encode/strip/thumbnail), então a peça nasce
     * `status=processing` e sem bytes servíveis. O gate de DURAÇÃO roda AQUI, no
     * request (ffprobe no upload) → 422 imediato se exceder, antes de dispensar o
     * job caro. O gate de TAMANHO é do Form Request (max 500 MB).
     *
     * @throws ContentException|VideoProcessingException
     */
    public function publishVideo(PerformerProfile $profile, UploadedFile $file, string $level, int $price): PerformerContent
    {
        if (! in_array($level, PerformerContent::LEVELS, true)) {
            throw ContentException::forbidden();
        }

        if (! $this->creditPolicy->isValidContentPrice($price)) {
            throw ContentException::invalidPrice();
        }

        // Duração lida do arquivo do upload (ainda no temporário do PHP). ffprobe
        // também valida que É vídeo (renomeado/corrompido → unreadable → 422).
        $duration = $this->video->probeDurationSeconds($file->getRealPath());

        if ($duration > (int) config('video.max_duration_seconds')) {
            throw VideoProcessingException::tooLong((int) config('video.max_duration_seconds'));
        }

        // Persiste o CRU num tmp do disco (o temporário do PHP some no fim do
        // request); o job pega dali. Só depois cria a linha e despacha.
        $rawPath = $this->store->storeRawVideo($file, $profile->id);

        $content = new PerformerContent;
        $content->performer_profile_id = $profile->id;
        $content->kind = PerformerContent::KIND_VIDEO;
        $content->status = PerformerContent::STATUS_PROCESSING;
        $content->access_level = $level;
        $content->price_tokens = $price;
        $content->path = ''; // preenchido pelo job ao terminar
        $content->save();

        Audit::log('content.published', $content, [
            'access_level' => $level,
            'price_tokens' => $price,
            'kind' => PerformerContent::KIND_VIDEO,
        ]);

        ProcessVideoContent::dispatch($content->id, $rawPath);

        return $content;
    }

    /**
     * Remove uma peça (dona só). Bytes PRIMEIRO, depois a linha (hard delete). Uma
     * denúncia em aberto CONGELA a remoção (anti-destruição de prova) — mesma regra
     * do Story. O ledger dos desbloqueios PERMANECE (append-only).
     */
    public function remove(PerformerProfile $profile, PerformerContent $content): void
    {
        if ((int) $content->performer_profile_id !== (int) $profile->id) {
            throw ContentException::offline();
        }

        if ($this->hasOpenReport($content)) {
            throw ContentException::underReview();
        }

        // path vazio = vídeo ainda em processamento (job não gravou bytes);
        // thumbnail_path existe só para vídeo pronto. Apaga o que houver.
        if ($content->path !== '') {
            $this->store->delete($content->path);
        }

        if ($content->thumbnail_path) {
            $this->store->delete($content->thumbnail_path);
        }

        $content->delete(); // cascade leva content_unlocks; ledger fica.

        Audit::log('content.removed', $content, ['id' => $content->id]);
    }

    /** Peças da performer + contagem de desbloqueios (receita). Sem membro. */
    public function forOwner(PerformerProfile $profile): Collection
    {
        return PerformerContent::query()
            ->where('performer_profile_id', $profile->id)
            ->withCount('unlocks')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PerformerContent $c) => [
                'id' => $c->id,
                'kind' => $c->kind,
                'status' => $c->status,
                'access_level' => $c->access_level,
                'price_tokens' => $c->price_tokens,
                'unlock_count' => (int) $c->unlocks_count,
                'duration_seconds' => $c->duration_seconds,
                // Vídeo em processamento/falha não tem bytes servíveis → sem URL.
                // A tela mostra o status; o poster/preview só quando READY.
                'image_url' => $c->isReady() ? route('performer.content.image', $c->id) : null,
                'failure_reason' => $c->status === PerformerContent::STATUS_FAILED ? $c->failure_reason : null,
            ]);
    }

    /**
     * Quem desbloqueou esta peça — SÓ por FanAlias, nunca member_id (M.13.10). A
     * única revelação de tier permitida: fc_only → FC (`tier_revealed`), porque só
     * FC alcança o nível. Nos outros níveis `tier_revealed` é null.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unlockersFor(PerformerProfile $profile, PerformerContent $content): array
    {
        if ((int) $content->performer_profile_id !== (int) $profile->id) {
            throw ContentException::offline();
        }

        $revealsFc = $content->access_level === PerformerContent::LEVEL_FC_ONLY;

        return $content->unlocks()
            ->orderByDesc('id')
            ->get()
            ->map(fn ($unlock) => [
                'fan' => FanAlias::label($profile->id, (int) $unlock->user_id),
                'member_handle' => FanAlias::handle($profile->id, (int) $unlock->user_id),
                'tokens_paid' => (int) $unlock->tokens_paid,
                'tier_revealed' => $revealsFc ? 'founders_circle' : null,
            ])
            ->all();
    }

    private function hasOpenReport(PerformerContent $content): bool
    {
        return Report::query()
            ->where('reportable_type', $content->getMorphClass())
            ->where('reportable_id', $content->getKey())
            ->whereIn('status', self::OPEN_REPORT_STATUSES)
            ->exists();
    }
}
