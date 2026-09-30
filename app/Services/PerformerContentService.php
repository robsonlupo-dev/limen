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
     * ENTREGA de uma encomenda sob medida (Onda 4 §4.3). Publica a peça (mesma
     * higienização/moderação de publish/publishVideo) mas marcada como PRIVADA da
     * encomenda (`custom_order_id`): fica FORA da vitrine pública e do painel de conteúdo
     * (galleryFor/forOwner filtram), e não é desbloqueável pela porta normal
     * (ContentVisibilityService::denialForUnlock nega). O acesso do membro que encomendou
     * vem de um content_unlocks criado na entrega (CustomOrderService). `access_level`
     * exclusivo (nunca "aberto"/grátis) e `price_tokens=0` (não é vendida na vitrine — o
     * preço é o do pedido). Foto nasce READY; vídeo nasce PROCESSING (job de ffmpeg).
     *
     * @throws ContentException|VideoProcessingException
     */
    public function deliverCustomPiece(PerformerProfile $profile, UploadedFile $file, int $customOrderId, ?string $watermarkText = null): PerformerContent
    {
        $isVideo = str_starts_with((string) $file->getMimeType(), 'video/');

        if ($isVideo) {
            $duration = $this->video->probeDurationSeconds($file->getRealPath());
            if ($duration > (int) config('video.max_duration_seconds')) {
                throw VideoProcessingException::tooLong((int) config('video.max_duration_seconds'));
            }

            $rawPath = $this->store->storeRawVideo($file, $profile->id);

            $content = new PerformerContent;
            $content->performer_profile_id = $profile->id;
            $content->custom_order_id = $customOrderId;
            $content->kind = PerformerContent::KIND_VIDEO;
            $content->status = PerformerContent::STATUS_PROCESSING;
            $content->access_level = PerformerContent::LEVEL_EXCLUSIVE;
            $content->price_tokens = 0;
            $content->path = '';
            $content->save();

            Audit::log('content.custom_delivered', $content, ['custom_order_id' => $customOrderId, 'kind' => PerformerContent::KIND_VIDEO]);

            ProcessVideoContent::dispatch($content->id, $rawPath);

            return $content;
        }

        // Foto: higieniza + grava (CSAM antes de escrever), pronta na hora. A marca
        // d'água (§4.3) é queimada no store, depois do scan (fail-closed quando ligada).
        $stored = $this->store->store($file, $profile->id, $profile->user, $watermarkText);

        try {
            $content = new PerformerContent;
            $content->performer_profile_id = $profile->id;
            $content->custom_order_id = $customOrderId;
            $content->kind = PerformerContent::KIND_PHOTO;
            $content->status = PerformerContent::STATUS_READY;
            $content->access_level = PerformerContent::LEVEL_EXCLUSIVE;
            $content->price_tokens = 0;
            $content->path = $stored['path'];
            $content->content_hash = $stored['hash'];
            $content->save();
        } catch (\Throwable $e) {
            try {
                $this->store->delete($stored['path']);
            } catch (\Throwable) {
            }

            throw $e;
        }

        Audit::log('content.custom_delivered', $content, ['custom_order_id' => $customOrderId, 'kind' => PerformerContent::KIND_PHOTO]);

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

    /**
     * Fixa/desafixa uma peça no topo da vitrine (roadmap social, Onda 3 — § 3.1).
     * `pinned_at` é a fonte única (fixado = não-null; a ordem entre fixados é o
     * próprio timestamp). Regras:
     *  - só a DONA fixa a própria peça (senão OFFLINE, a mesma máscara do resto);
     *  - só peça PRONTA vai ao topo (vídeo em processing/failed não é servível);
     *  - teto `config('content.max_pinned')` — atingido, recusa PIN_CAP.
     * Ações idempotentes: fixar o que já está fixado não renova a ordem nem conta
     * de novo no teto; desafixar o que não está é no-op. Denúncia aberta NÃO trava
     * o pin (é ordenação de exibição, não remoção nem conteúdo novo).
     *
     * Nota de concorrência: o teto é SOFT (conta-e-grava sem lock), como o teto
     * diário do canal de transmissão. É ação da própria performer sobre a própria
     * vitrine (uso sequencial), e estourar por 1 numa corrida rara não é invariante
     * de segurança — só cosmético do topo da vitrine.
     */
    public function setPinned(PerformerProfile $profile, PerformerContent $content, bool $on): PerformerContent
    {
        if ((int) $content->performer_profile_id !== (int) $profile->id) {
            throw ContentException::offline();
        }

        // Desafixar: sempre permitido, idempotente.
        if (! $on) {
            if ($content->isPinned()) {
                $content->forceFill(['pinned_at' => null])->save();
                Audit::log('content.unpinned', $content, ['id' => $content->id]);
            }

            return $content;
        }

        // Fixar. Já fixada → no-op (não renova a ordem nem reconta no teto).
        if ($content->isPinned()) {
            return $content;
        }

        // Sob denúncia aberta não se DÁ destaque a uma peça (moderação primeiro):
        // fixar no topo maximizaria a exposição de conteúdo em análise. Desafixar
        // segue liberado (reduzir prominência é sempre permitido) — o guard é só
        // no "ligar", como o congelamento de remoção do remove().
        if ($this->hasOpenReport($content)) {
            throw ContentException::underReview();
        }

        if (! $content->isReady()) {
            throw ContentException::pinNotReady();
        }

        $max = (int) config('content.max_pinned', 3);
        $pinnedCount = PerformerContent::query()
            ->where('performer_profile_id', $profile->id)
            ->whereNotNull('pinned_at')
            ->count();

        if ($pinnedCount >= $max) {
            throw ContentException::pinCapReached($max);
        }

        $content->forceFill(['pinned_at' => now()])->save();
        Audit::log('content.pinned', $content, ['id' => $content->id]);

        return $content;
    }

    /** Peças da performer + contagem de desbloqueios (receita). Sem membro. */
    public function forOwner(PerformerProfile $profile): Collection
    {
        return PerformerContent::query()
            ->where('performer_profile_id', $profile->id)
            ->whereNull('custom_order_id') // entregas de encomenda não entram no painel de conteúdo
            ->withCount('unlocks')
            ->orderedForShowcase() // mesma ordem da vitrine pública (§ 3.1)
            ->get()
            ->map(fn (PerformerContent $c) => [
                'id' => $c->id,
                'kind' => $c->kind,
                'status' => $c->status,
                'access_level' => $c->access_level,
                'price_tokens' => $c->price_tokens,
                'unlock_count' => (int) $c->unlocks_count,
                'duration_seconds' => $c->duration_seconds,
                // Estado do destaque (§ 3.1): a UI mostra o selo e alterna fixar/desafixar.
                'pinned' => $c->isPinned(),
                'pinned_at' => optional($c->pinned_at)->toIso8601String(),
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
