<?php

namespace App\Jobs;

use App\Exceptions\VideoProcessingException;
use App\Models\CustomOrder;
use App\Models\PerformerContent;
use App\Services\ContentStore;
use App\Services\VideoProcessingService;
use App\Services\WatermarkService;
use App\Support\Audit;
use App\Support\FanAlias;
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

    public function handle(VideoProcessingService $video, ContentStore $store, ?WatermarkService $watermark = null): void
    {
        // Nullable + fallback pelo container: a fila injeta os três args, mas testes que
        // chamam handle($video, $store) direto (VideoContentTest) continuam válidos.
        $watermark ??= app(WatermarkService::class);

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
        $overlayPng = null;

        try {
            // Marca d'água (Onda 4 §4.3): só para entrega de encomenda e com a feature
            // ligada. Gera um PNG transparente do tamanho do vídeo e passa ao sanitize.
            // Fail-closed: se a marca falhar, o job cai no catch → peça FAILED (o membro
            // é estornado no auto-release) — nunca entrega vídeo sem marca.
            if ($content->custom_order_id !== null && (bool) config('custom_order.watermark.enabled')) {
                $order = CustomOrder::find($content->custom_order_id);
                // Fail-closed: com a marca LIGADA, uma encomenda sem linha (não deveria
                // acontecer — não há hard-delete) NÃO pode entregar vídeo sem marca.
                if ($order === null) {
                    throw new \RuntimeException('Encomenda da peça não encontrada para a marca d\'água.');
                }

                // Dimensões do overlay LIMITADAS (evita alocar um canvas GD gigante na
                // fila de 2 vCPU): mantém o aspecto e o maior lado ≤ cap. O scale2ref no
                // ffmpeg estica o overlay menor para o frame real, então a cobertura segue
                // total. Dims inválidas → cai num 16:9 padrão (o scale2ref cobre).
                [$w, $h] = $video->probeDimensions($rawAbs);
                [$ow, $oh] = $this->overlayDimensions($w, $h);
                // tempnam devolve um path SEM extensão e cria o arquivo; gravamos o PNG
                // nesse mesmo path (o ffmpeg lê PNG por conteúdo, não pela extensão), então
                // o finally limpa exatamente ele — sem órfão .png (achado da revisão).
                $overlayPng = tempnam(sys_get_temp_dir(), 'limen_wm_');
                $watermark->buildOverlayPng(
                    $ow,
                    $oh,
                    WatermarkService::labelFor(FanAlias::label($order->performer_profile_id, (int) $order->member_id)),
                    $overlayPng,
                );
            }

            $video->sanitize($rawAbs, $mp4Tmp, $overlayPng);
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
            if ($overlayPng !== null) {
                @unlink($overlayPng);
            }
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

    /**
     * Dimensões do overlay da marca d'água, LIMITADAS para não estourar memória do
     * worker. Mantém o aspecto e o maior lado em até OVERLAY_MAX_SIDE; dims inválidas
     * (0/negativas) caem num 16:9 padrão. O scale2ref no ffmpeg cobre o frame real.
     *
     * @return array{0:int,1:int}
     */
    private function overlayDimensions(int $w, int $h): array
    {
        $cap = 1920;
        if ($w < 1 || $h < 1) {
            return [1280, 720];
        }
        $longest = max($w, $h);
        if ($longest <= $cap) {
            return [$w, $h];
        }
        $scale = $cap / $longest;

        return [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
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
