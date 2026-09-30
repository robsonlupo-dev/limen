<?php

namespace App\Services;

use App\Exceptions\VideoProcessingException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Sanitização de vídeo por ffmpeg (Sprint 16). Análoga ao ImageProcessingService,
 * mas o motor é ffmpeg (GD não faz vídeo). O arquivo servido deixa de ser o
 * arquivo enviado:
 *  - RE-ENCODE para H.264/AAC em MP4 a partir dos streams decodificados — mata
 *    payload embutido (polyglot) e streams estranhos (só mapeia 1 vídeo + 1 áudio).
 *  - STRIP de metadados (`-map_metadata -1`): GPS, device, timestamps somem.
 *  - THUMBNAIL do 1º segundo (poster), JPEG re-encodado.
 *
 * Não conhece disco nem banco: recebe/devolve CAMINHOS de arquivo. Quem persiste
 * é o ContentStore; quem orquestra o assíncrono é o ProcessVideoContent job.
 */
class VideoProcessingService
{
    /**
     * ffmpeg/ffprobe estão instalados? Falha de infra (não do usuário) → lança
     * ffmpegMissing. Chamado ANTES de qualquer processamento.
     *
     * @throws VideoProcessingException
     */
    public function assertAvailable(): void
    {
        foreach ([config('video.ffprobe'), config('video.ffmpeg')] as $binary) {
            try {
                $probe = new Process([$binary, '-version']);
                $probe->run();

                if (! $probe->isSuccessful()) {
                    throw VideoProcessingException::ffmpegMissing();
                }
            } catch (ProcessException) {
                throw VideoProcessingException::ffmpegMissing();
            }
        }
    }

    /**
     * Duração do vídeo em segundos, por ffprobe. Lida no UPLOAD para o gate de
     * duração (422). Arquivo que o ffprobe não reconhece como mídia com duração →
     * unreadable (renomeado/corrompido).
     *
     * @throws VideoProcessingException
     */
    public function probeDurationSeconds(string $path): float
    {
        $this->assertAvailable();

        // Timeout CURTO e próprio (probe_timeout): roda síncrono no request web e
        // não pode herdar os 30 min do re-encode.
        $process = $this->run([
            config('video.ffprobe'),
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ], (float) config('video.probe_timeout'));

        if (! $process->isSuccessful()) {
            throw VideoProcessingException::unreadable();
        }

        $duration = trim($process->getOutput());

        if ($duration === '' || ! is_numeric($duration)) {
            throw VideoProcessingException::unreadable();
        }

        return (float) $duration;
    }

    /**
     * Dimensões (largura, altura) do 1º stream de vídeo, por ffprobe. Usado para gerar
     * o overlay da marca d'água no tamanho exato do vídeo (Onda 4 §4.3).
     *
     * @return array{0:int,1:int}
     *
     * @throws VideoProcessingException
     */
    public function probeDimensions(string $path): array
    {
        $this->assertAvailable();

        $process = $this->run([
            config('video.ffprobe'),
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height',
            '-of', 'csv=s=x:p=0',
            $path,
        ], (float) config('video.probe_timeout'));

        if (! $process->isSuccessful()) {
            throw VideoProcessingException::unreadable();
        }

        $out = trim($process->getOutput());
        if (! preg_match('/^(\d+)x(\d+)$/', $out, $m)) {
            throw VideoProcessingException::unreadable();
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * Re-encoda para H.264/AAC MP4, sem metadados, mapeando só 1 vídeo + 1 áudio
     * (opcional). Escreve em $destPath (.mp4). Falha → encodeFailed.
     *
     * `$overlayPngPath` (Onda 4 §4.3): quando presente, um PNG transparente do TAMANHO do
     * vídeo é sobreposto (marca d'água) via filter_complex. Sem ele, o comando é idêntico
     * ao de sempre (o conteúdo do cofre/PPV não muda).
     *
     * @throws VideoProcessingException
     */
    public function sanitize(string $srcPath, string $destPath, ?string $overlayPngPath = null): void
    {
        $this->assertAvailable();

        $common = [
            '-dn', '-sn',
            '-map_metadata', '-1',
            '-c:v', config('video.video_codec'),
            '-preset', config('video.preset'),
            '-crf', (string) config('video.crf'),
            '-pix_fmt', 'yuv420p',
            '-c:a', config('video.audio_codec'),
            '-movflags', '+faststart',
            '-f', 'mp4',
            $destPath,
        ];

        if ($overlayPngPath !== null) {
            // Marca d'água: sobrepõe o PNG sobre o 1º vídeo. `scale2ref` ESTICA o overlay
            // para as dimensões REAIS do frame decodificado (`[0:v]`), então a cobertura é
            // sempre total mesmo quando o coded size difere do exibido — vídeo de celular
            // com matriz de rotação (retrato) é o caso comum e cairia num overlay parcial
            // se fixássemos 0:0 no tamanho probado. Com filter_complex o auto-map some,
            // então mapeamos a saída [vout] e o 1º áudio (opcional) explicitamente.
            $command = array_merge([
                config('video.ffmpeg'),
                '-y',
                '-i', $srcPath,
                '-i', $overlayPngPath,
                '-filter_complex', '[1:v][0:v]scale2ref=w=iw:h=ih[wm][base];[base][wm]overlay=0:0:format=auto[vout]',
                '-map', '[vout]',
                '-map', '0:a:0?',
            ], $common);
        } else {
            $command = array_merge([
                config('video.ffmpeg'),
                '-y',
                '-i', $srcPath,
                // Só o 1º vídeo e (se houver) o 1º áudio; nada de data/subtitle/anexos
                // (vetores de payload). `-dn`/`-sn` reforçam.
                '-map', '0:v:0',
                '-map', '0:a:0?',
            ], $common);
        }

        $process = $this->run($command);

        if (! $process->isSuccessful() || ! is_file($destPath) || filesize($destPath) === 0) {
            @unlink($destPath);

            throw VideoProcessingException::encodeFailed();
        }
    }

    /**
     * Poster: 1 frame no segundo configurado, escalado para a largura configurada,
     * JPEG re-encodado. Escreve em $destPath (.jpg). Falha → encodeFailed.
     *
     * @throws VideoProcessingException
     */
    public function thumbnail(string $srcPath, string $destPath): void
    {
        $this->assertAvailable();

        $width = (int) config('video.thumbnail_width');

        // 1ª tentativa no segundo configurado; se o vídeo for MAIS CURTO que isso,
        // o seek não produz frame e o arquivo sai vazio — cai para o frame 0 (todo
        // vídeo com ≥1 frame tem esse). Só falha se NENHUM frame sair.
        foreach ([(int) config('video.thumbnail_second'), 0] as $second) {
            $process = $this->run([
                config('video.ffmpeg'),
                '-y',
                '-ss', (string) $second,
                '-i', $srcPath,
                '-frames:v', '1',
                '-an', '-dn', '-sn',
                '-map_metadata', '-1',
                // Largura fixa, altura par mantendo proporção.
                '-vf', "scale={$width}:-2",
                '-q:v', '3',
                '-f', 'image2',
                $destPath,
            ]);

            if ($process->isSuccessful() && is_file($destPath) && filesize($destPath) > 0) {
                return;
            }

            @unlink($destPath);
        }

        throw VideoProcessingException::encodeFailed();
    }

    /**
     * Roda um Process, tolerando binário ausente. $timeout null = o do re-encode
     * (process_timeout); o probe passa o seu (curto).
     */
    private function run(array $command, ?float $timeout = null): Process
    {
        try {
            $process = new Process($command);
            $process->setTimeout($timeout ?? (float) config('video.process_timeout'));
            $process->run();

            return $process;
        } catch (ProcessException) {
            throw VideoProcessingException::ffmpegMissing();
        }
    }
}
