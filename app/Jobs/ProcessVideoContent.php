<?php

namespace App\Jobs;

use App\Exceptions\VideoProcessingException;
use App\Models\PerformerContent;
use App\Services\ContentStore;
use App\Services\VideoProcessingService;
use App\Support\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sanitiza o vídeo de uma peça de conteúdo permanente (Sprint 16), FORA do
 * request: re-encode H.264/AAC MP4 + strip de metadados + thumbnail. Enquanto não
 * termina, a peça fica `status=processing` e NÃO é servível
 * (ContentVisibilityService::canView exige READY).
 *
 * Sucesso → status=ready + path/thumbnail_path/content_hash/duration_seconds.
 * Falha  → status=failed + failure_reason (a performer reenvia). Sem retry
 * automático (`$tries=1`): re-encode é caro e a entrada não vai "melhorar"
 * sozinha; o botão de reenviar é o retry.
 */
class ProcessVideoContent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 2000;

    public function __construct(
        public int $contentId,
        public string $rawPath,
    ) {}

    public function handle(VideoProcessingService $video, ContentStore $store): void
    {
        $content = PerformerContent::find($this->contentId);

        // Peça sumiu (removida) ou já resolvida — nada a fazer. Ainda assim limpa
        // o cru para não deixar lixo no disco.
        if ($content === null || $content->status !== PerformerContent::STATUS_PROCESSING) {
            $this->cleanupRaw($store);

            return;
        }

        $rawAbs = $store->absolutePath($this->rawPath);
        $mp4Tmp = tempnam(sys_get_temp_dir(), 'limen_vid_');
        $thumbTmp = tempnam(sys_get_temp_dir(), 'limen_thumb_');

        try {
            $video->sanitize($rawAbs, $mp4Tmp);
            // Thumbnail a partir do MP4 JÁ higienizado (não relê o cru hostil) e a
            // duração do resultado servível.
            $video->thumbnail($mp4Tmp, $thumbTmp);
            $duration = (int) round($video->probeDurationSeconds($mp4Tmp));

            $stored = $store->putSanitizedVideo($mp4Tmp, $thumbTmp, $content->performer_profile_id);

            $content->forceFill([
                'status' => PerformerContent::STATUS_READY,
                'path' => $stored['path'],
                'thumbnail_path' => $stored['thumbnail_path'],
                'content_hash' => $stored['hash'],
                'duration_seconds' => $duration,
                'failure_reason' => null,
            ])->save();

            Audit::log('content.video_processed', $content, ['duration_seconds' => $duration]);
        } catch (Throwable $e) {
            $this->markFailed($content, $e);
        } finally {
            @unlink($mp4Tmp);
            @unlink($thumbTmp);
            $this->cleanupRaw($store);
        }
    }

    /** O job morreu (timeout/OOM/erro fora do try) — marca a peça como failed. */
    public function failed(?Throwable $e): void
    {
        $content = PerformerContent::find($this->contentId);

        if ($content !== null && $content->status === PerformerContent::STATUS_PROCESSING) {
            $this->markFailed($content, $e);
        }

        Storage::disk(ContentStore::DISK)->delete($this->rawPath);
    }

    private function markFailed(PerformerContent $content, ?Throwable $e): void
    {
        $reason = $e instanceof VideoProcessingException
            ? $e->getMessage()
            : 'Não foi possível processar o vídeo. Tente enviar novamente.';

        $content->forceFill([
            'status' => PerformerContent::STATUS_FAILED,
            'failure_reason' => $reason,
        ])->save();

        Audit::log('content.video_failed', $content, ['reason' => $e instanceof VideoProcessingException ? $e->reason : 'unknown']);
    }

    private function cleanupRaw(ContentStore $store): void
    {
        try {
            Storage::disk(ContentStore::DISK)->delete($this->rawPath);
        } catch (Throwable) {
            // best-effort; a varredura de tmp é responsabilidade futura de GC.
        }
    }
}
