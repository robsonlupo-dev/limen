<?php

namespace App\Services;

use App\Models\LiveSession;
use Illuminate\Support\Facades\Storage;

/**
 * Dona única do frame de preview da live no catálogo (Sprint 15, PR #143). UM
 * JPEG de baixa qualidade por sessão de live (`{session_id}.jpg` no disco privado
 * `live_previews`), sobrescrito a cada ~10s pelo <LiveRoom> da performer. É prova
 * de vida barata (snapshot, não WebRTC — economia de conexões no free tier).
 *
 * ── Segurança leve, mesma disciplina do ServesPhotoBytes ─────────────────────
 * O frame vem de `canvas.toDataURL('image/jpeg')` — capturado do vídeo LOCAL da
 * performer, sem EXIF (canvas não carrega metadado). Validamos SEM decodificar
 * server-side (evita bomba de descompressão — nunca damos `imagecreatefromstring`
 * em bytes de tamanho fixo mas dimensão arbitrária): só o tamanho (≤ 50KB) e o
 * magic-byte JPEG (finfo, que lê o cabeçalho, não o bitmap). O serving re-sniffa
 * de novo + `nosniff` + `inline`, então um polyglot nunca é executado no browser.
 *
 * O arquivo é keyado por session_id (uma live nova = frame novo, sem herdar o
 * quadro de uma transmissão anterior). Nem o session_id nem o path saem em
 * resposta/URL — o serving resolve slug → live ativa → arquivo internamente.
 */
class LivePreviewService
{
    public const DISK = 'live_previews';

    /** Teto do frame gravado (M do plano: ~50KB, qualidade baixa). */
    public const MAX_BYTES = 51200; // 50 * 1024

    /** Órfão: frame sem novo quadro há mais que isto → a live morreu, apaga. */
    private const ORPHAN_TTL_SECONDS = 3600; // 1h

    /**
     * Grava (sobrescreve) o frame da sessão. PRESSUPÕE bytes já validados
     * (tamanho + JPEG) pelo chamador. Devolve false se a escrita falhar (disco
     * `throw => false`) — o chamador decide, mas um frame perdido é inócuo (o
     * próximo chega em 10s).
     */
    public function store(LiveSession $session, string $bytes): bool
    {
        return Storage::disk(self::DISK)->put($this->path($session->id), $bytes);
    }

    /** Bytes do último frame, ou null se não houver (live sem quadro ainda). */
    public function get(LiveSession $session): ?string
    {
        return Storage::disk(self::DISK)->get($this->path($session->id));
    }

    /** Apaga o frame da sessão (fim da live / ban). Idempotente. */
    public function delete(int $sessionId): void
    {
        Storage::disk(self::DISK)->delete($this->path($sessionId));
    }

    /**
     * Faxina de órfãos (command `live-previews:purge`): apaga frames sem novo
     * quadro há mais de 1h. Uma live viva sobrescreve a cada 10s, então um arquivo
     * "velho" é de uma live que encerrou sem passar pelo delete (ex.: reconciliada
     * na leitura quando a sala morreu no LiveKit, sem chamar stop). O mtime é o
     * sinal — não precisa cruzar com o banco. Devolve quantos apagou.
     */
    public function purgeOrphans(): int
    {
        $disk = Storage::disk(self::DISK);

        // Guard: o diretório do disco só passa a existir quando o PRIMEIRO frame é
        // gravado (nenhuma live transmitiu ainda = sem diretório). Listar um path
        // inexistente faz o Flysystem estourar, e o live-previews:purge roda de
        // tempos em tempos — logo, exceção recorrente no log sem nada a faxinar.
        // Sai cedo. directoryExists é LEITURA: não materializa o diretório (a
        // faxina não deve criar storage do nada).
        if (! $disk->directoryExists('')) {
            return 0;
        }

        $cutoff = now()->getTimestamp() - self::ORPHAN_TTL_SECONDS;
        $deleted = 0;

        foreach ($disk->files() as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function path(int $sessionId): string
    {
        return $sessionId.'.jpg';
    }
}
