<?php

namespace App\Services;

use App\Exceptions\CsamDetectedException;
use App\Models\ContentHashCheck;
use App\Models\CsamHash;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Anti-CSAM MVP (Sprint 16) — dona única da verificação. Escaneia os bytes de uma
 * imagem JÁ HIGIENIZADA no upload (avatar/capa/conteúdo): calcula o phash e o
 * confere contra a lista local (csam_hashes). TODA chamada grava uma linha de
 * trilha (content_hash_checks).
 *
 * ── Duas saídas ──────────────────────────────────────────────────────────────
 *  - MATCH: bloqueia o upload (CsamDetectedException), loga CRITICAL, sinaliza a
 *    conta (csam_flagged_at) para review imediato. O bloqueio acontece ANTES de
 *    qualquer gravação/serving — o chamador scaneia antes do put.
 *  - SERVIÇO INDISPONÍVEL (não decodifica): FAIL-OPEN — NÃO bloqueia, loga WARNING
 *    e registra `verified=false`. Decisão do MVP: não travar upload legítimo por
 *    uma falha do hasher (a lista está vazia; o risco de falso-bloqueio > o de
 *    passar um não-verificado, que fica registrado para varredura posterior).
 *
 * MVP: match EXATO do phash (indexado). Near-match por Hamming + PhotoDNA real são
 * follow-up (a lista real depende de parceria NCMEC/IWF + CNPJ).
 */
class CsamScanService
{
    public function __construct(private PerceptualHashService $hasher) {}

    /**
     * @throws CsamDetectedException quando bate na lista (bloqueia o upload)
     */
    public function scanBytes(string $imageBytes, string $context, ?User $uploader = null, ?int $contentId = null): void
    {
        if (! config('csam.enabled')) {
            return;
        }

        try {
            $hash = $this->hasher->hash($imageBytes);
        } catch (Throwable $e) {
            // Fail-open: registra "não verificado" e segue (não bloqueia).
            $this->record($context, $contentId, $uploader, null, matched: false, verified: false);
            Log::warning('csam.unverified', [
                'context' => $context,
                'user_id' => $uploader?->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $matched = CsamHash::where('hash', $hash)->exists();

        $this->record($context, $contentId, $uploader, $hash, matched: $matched, verified: true);

        if ($matched) {
            $this->onMatch($context, $contentId, $uploader, $hash);

            throw new CsamDetectedException;
        }
    }

    private function onMatch(string $context, ?int $contentId, ?User $uploader, string $hash): void
    {
        // CRITICAL: é o canal de alerta ao admin no MVP (ops monitora CRITICAL).
        // Notificação por e-mail dedicada é follow-up.
        Log::critical('csam.match', [
            'context' => $context,
            'content_id' => $contentId,
            'user_id' => $uploader?->id,
            'hash' => $hash,
        ]);

        if ($uploader !== null) {
            // Marca a conta para review IMEDIATO. csam_flagged_at fica fora do
            // $fillable (autoridade), como `status` — forceFill.
            $uploader->forceFill(['csam_flagged_at' => now()])->save();

            // Audit sem o hash no metadata do usuário: o hash é da LISTA, o que
            // importa aqui é o carimbo + o contexto. O hash detalhado fica no log.
            Audit::log('csam.match', $uploader, ['context' => $context, 'content_id' => $contentId]);
        }
    }

    private function record(string $context, ?int $contentId, ?User $uploader, ?string $hash, bool $matched, bool $verified): void
    {
        ContentHashCheck::create([
            'context' => $context,
            'content_id' => $contentId,
            'user_id' => $uploader?->id,
            'hash' => $hash,
            'matched' => $matched,
            'verified' => $verified,
            'checked_at' => now(),
        ]);
    }
}
