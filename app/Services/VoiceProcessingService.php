<?php

namespace App\Services;

use App\Exceptions\VoiceProcessingException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Sanitização de áudio por ffmpeg (feat/voice-intro). SEPARADA do
 * VideoProcessingService de propósito: os tetos (20s/5MB) e o alvo (MP3 mono) são
 * de áudio; unir os dois faria uma mudança de vídeo mexer no áudio sem querer. O
 * arquivo servido deixa de ser o arquivo enviado:
 *  - RE-ENCODE para MP3 a partir do stream de áudio DECODIFICADO — mata payload
 *    embutido (polyglot) e mapeia SÓ o 1º áudio (`-map 0:a:0`), derrubando vídeo/
 *    capa/data/subtitle (`-vn -dn -sn`), que num .m4a são vetores de metadado.
 *  - STRIP de TODO metadado (`-map_metadata -1` + `-write_id3v2 0 -write_id3v1 0`):
 *    ID3, GPS, device, timestamps somem. Áudio de celular carrega tudo isso.
 *  - Normaliza volume (`loudnorm`) e força mono/44.1k — voz não precisa de estéreo.
 *
 * Não conhece disco nem banco: recebe/devolve CAMINHOS de arquivo. Quem persiste
 * é o VoiceIntroStore; quem orquestra o assíncrono é o ProcessVoiceIntro job.
 */
class VoiceProcessingService
{
    /**
     * ffmpeg/ffprobe estão instalados? Falha de infra (não do usuário) → lança
     * ffmpegMissing. Chamado ANTES de qualquer processamento (fail-closed: sem
     * sanitização, não se aceita o áudio).
     *
     * @throws VoiceProcessingException
     */
    public function assertAvailable(): void
    {
        foreach ([config('voice.ffprobe'), config('voice.ffmpeg')] as $binary) {
            try {
                $probe = new Process([$binary, '-version']);
                $probe->run();

                if (! $probe->isSuccessful()) {
                    throw VoiceProcessingException::ffmpegMissing();
                }
            } catch (ProcessException) {
                throw VoiceProcessingException::ffmpegMissing();
            }
        }
    }

    /**
     * Duração do áudio em segundos, por ffprobe. Lida no UPLOAD para o gate de
     * duração (422). Retorna:
     *  - float  → o container traz a duração (arquivo de verdade).
     *  - null   → é mídia válida mas SEM duração no header (webm de MediaRecorder
     *             que não fecha a duração no stop). O gate é reconferido no job,
     *             sobre o MP3 já processado, cuja duração é sempre confiável.
     *
     * Arquivo que o ffprobe NÃO reconhece como mídia (renomeado/corrompido) →
     * unreadable (422). Distinguir "áudio sem duração" de "não é áudio" é o ponto:
     * o primeiro é gravação de navegador legítima; o segundo é recusa.
     *
     * @throws VoiceProcessingException
     */
    public function probeDurationSeconds(string $path): ?float
    {
        $this->assertAvailable();

        // Timeout CURTO e próprio (probe_timeout): roda síncrono no request web e
        // não pode herdar o do re-encode.
        $process = $this->run([
            config('voice.ffprobe'),
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ], (float) config('voice.probe_timeout'));

        // ffprobe falha (exit != 0) em arquivo que não é mídia — renomeado/corrompido.
        if (! $process->isSuccessful()) {
            throw VoiceProcessingException::unreadable();
        }

        $duration = trim($process->getOutput());

        // Mídia válida, mas o container não traz duração (webm streaming) → defere
        // o gate para o job. NÃO é unreadable: o ffprobe leu o container.
        if ($duration === '' || ! is_numeric($duration)) {
            return null;
        }

        return (float) $duration;
    }

    /**
     * Re-encoda para MP3 mono normalizado, SEM metadado, mapeando só o 1º áudio.
     * Escreve em $destPath (.mp3). Falha (inclui "não há stream de áudio") →
     * encodeFailed.
     *
     * @throws VoiceProcessingException
     */
    public function sanitize(string $srcPath, string $destPath): void
    {
        $this->assertAvailable();

        $process = $this->run([
            config('voice.ffmpeg'),
            '-y',
            '-i', $srcPath,
            // Só o 1º áudio; nada de vídeo/capa/data/subtitle (vetores de payload
            // e metadado num container de áudio).
            '-map', '0:a:0',
            '-vn', '-dn', '-sn',
            // Strip de TODO metadado: o do container E o ID3 que o muxer de MP3
            // escreveria por conta própria.
            '-map_metadata', '-1',
            '-write_id3v2', '0',
            '-write_id3v1', '0',
            // bitexact: impede o ffmpeg de gravar a própria tag "encoder"/versão
            // do LAME — nenhuma string identificável sobra no arquivo.
            '-fflags', '+bitexact',
            '-flags:a', '+bitexact',
            // Normaliza o volume e força mono/44.1k.
            '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11',
            '-ar', (string) config('voice.sample_rate'),
            '-ac', (string) config('voice.channels'),
            '-c:a', config('voice.audio_codec'),
            '-b:a', config('voice.bitrate'),
            '-f', 'mp3',
            $destPath,
        ]);

        if (! $process->isSuccessful() || ! is_file($destPath) || filesize($destPath) === 0) {
            @unlink($destPath);

            throw VoiceProcessingException::encodeFailed();
        }
    }

    /**
     * Roda um Process, tolerando binário ausente. $timeout null = o do re-encode
     * (process_timeout); o probe passa o seu (curto).
     */
    private function run(array $command, ?float $timeout = null): Process
    {
        try {
            $process = new Process($command);
            $process->setTimeout($timeout ?? (float) config('voice.process_timeout'));
            $process->run();

            return $process;
        } catch (ProcessException) {
            throw VoiceProcessingException::ffmpegMissing();
        }
    }
}
