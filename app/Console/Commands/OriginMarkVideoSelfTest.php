<?php

namespace App\Console\Commands;

use App\Services\VideoProcessingService;
use App\Services\WatermarkService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Self-test da marca de ORIGEM no VÍDEO (Onda B §4, peça 2c). Roda o caminho REAL de
 * ffmpeg (que a suíte automática NÃO cobre — dev==prod), SEM depender do interruptor
 * `origin_mark.video_enabled` (o flag só governa a publicação real). Serve para validar,
 * ANTES de ligar no go-live, que o overlay de rodapé entra no transcode sem derrubar o
 * servidor de 2 vCPU.
 *
 * Headless — não exige UI nem login:
 *   php artisan origin-mark:video-selftest
 *   php artisan origin-mark:video-selftest --text="Limen · @teste" --out=/tmp --size=720x1280
 *
 * Gera: um vídeo de teste, o overlay PNG de rodapé, e o MP4 final marcado. Abra o MP4;
 * se o rodapé "Limen · @nome" aparecer discreto na base, pode ligar ORIGIN_MARK_VIDEO=true
 * + config:cache.
 */
class OriginMarkVideoSelfTest extends Command
{
    protected $signature = 'origin-mark:video-selftest '.
        '{--text=Limen · @demo : texto da marca} '.
        '{--out=/tmp : pasta de saída} '.
        '{--size=720x1280 : LARGURAxALTURA do vídeo de teste (retrato por padrão)} '.
        '{--seconds=3 : duração do vídeo de teste}';

    protected $description = 'Gera um MP4 de teste com a marca de origem no rodapé (conferência visual do caminho ffmpeg).';

    public function handle(VideoProcessingService $video, WatermarkService $watermark): int
    {
        $out = rtrim((string) $this->option('out'), '/');
        $text = (string) $this->option('text');
        [$w, $h] = $this->parseSize((string) $this->option('size'));
        $seconds = max(1, (int) $this->option('seconds'));

        try {
            $video->assertAvailable();
        } catch (\Throwable $e) {
            $this->error('ffmpeg/ffprobe indisponível: '.$e->getMessage());

            return self::FAILURE;
        }

        $raw = "{$out}/origin-video-raw.mp4";
        $overlay = "{$out}/origin-video-overlay.png";
        $final = "{$out}/origin-video-marked.mp4";

        // 1) Vídeo de teste (testsrc2 — padrão colorido, bom para ver a marca em claro/escuro).
        $gen = new Process([
            (string) config('video.ffmpeg'), '-y',
            '-f', 'lavfi', '-i', "testsrc2=size={$w}x{$h}:rate=15:duration={$seconds}",
            '-pix_fmt', 'yuv420p', $raw,
        ]);
        $gen->setTimeout(120)->run();
        if (! $gen->isSuccessful() || ! is_file($raw)) {
            $this->error('Falha ao gerar o vídeo de teste: '.$gen->getErrorOutput());

            return self::FAILURE;
        }
        $this->info("✅ Vídeo de teste {$w}x{$h}: {$raw}");

        try {
            // 2) Overlay de rodapé no tamanho do vídeo de teste.
            $watermark->buildFooterOverlayPng($w, $h, $text, $overlay);
            $this->info("✅ Overlay de rodapé: {$overlay}");

            // 3) Transcode REAL com o overlay (mesmo caminho do ProcessVideoContent).
            $video->sanitize($raw, $final, $overlay);
            $this->info("✅ MP4 marcado: {$final}");

            // 4) Confere que o resultado é um vídeo legível de duração plausível.
            $dur = $video->probeDurationSeconds($final);
            [$fw, $fh] = $video->probeDimensions($final);
            $this->line("Resultado: {$fw}x{$fh}, ~".round($dur, 1).'s.');
            if ($dur <= 0) {
                $this->error('O MP4 final não tem duração válida.');

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('Falhou no overlay/transcode: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($overlay);
        }

        $this->newLine();
        $this->line("Texto usado: {$text}");
        $this->line("Abra {$final}. Se o rodapé estiver discreto e legível, ligue ORIGIN_MARK_VIDEO=true + php artisan config:cache.");

        return self::SUCCESS;
    }

    /** @return array{0:int,1:int} */
    private function parseSize(string $size): array
    {
        $parts = explode('x', strtolower(trim($size)));
        $w = isset($parts[0]) ? (int) $parts[0] : 0;
        $h = isset($parts[1]) ? (int) $parts[1] : 0;

        // Precisa ser par para yuv420p; cai num retrato padrão se vier inválido.
        $w = $w > 0 ? $w - ($w % 2) : 720;
        $h = $h > 0 ? $h - ($h % 2) : 1280;

        return [$w, $h];
    }
}
