<?php

namespace App\Services;

use App\Models\PerformerProfile;
use App\Models\PerformerStatus;

/**
 * Status do dia da performer (roadmap social, Onda 1a). Dona única: definir, limpar
 * e ler o status ativo passam só por aqui.
 *
 * Um status por performer (UNIQUE em performer_profile_id) — definir é UPSERT.
 * `expires_at` é DERIVADO de `config('stories.status.ttl_hours')`, nunca de payload.
 * A validação do texto (anti-contato) é do Form Request (SafeProfileText); aqui só
 * grava o que já veio validado.
 */
class PerformerStatusService
{
    /**
     * Define (ou substitui) o status ativo da performer.
     *
     * @param  array{body:string, countdown_at?:?string, countdown_label?:?string}  $data
     */
    public function set(PerformerProfile $profile, array $data): PerformerStatus
    {
        $ttl = (int) config('stories.status.ttl_hours', 24);

        // forceFill (não updateOrCreate): `performer_profile_id` e `expires_at` são
        // autoridade do servidor, ficam FORA do $fillable — mass-assignment os
        // descartaria. O texto já veio validado do Form Request.
        $status = PerformerStatus::firstOrNew(['performer_profile_id' => $profile->id]);

        $status->forceFill([
            'performer_profile_id' => $profile->id,
            'body' => trim($data['body']),
            // Contagem só entra se houver um alvo futuro; rótulo sem alvo é ruído.
            'countdown_at' => $data['countdown_at'] ?? null,
            'countdown_label' => ! empty($data['countdown_at'])
                ? ($data['countdown_label'] ?? null)
                : null,
            'expires_at' => now()->addHours($ttl),
        ])->save();

        return $status;
    }

    /** Remove o status da performer (se houver). */
    public function clear(PerformerProfile $profile): void
    {
        PerformerStatus::where('performer_profile_id', $profile->id)->delete();
    }

    /** Status ativo (não expirado) da performer, ou null. */
    public function currentFor(PerformerProfile $profile): ?PerformerStatus
    {
        return PerformerStatus::where('performer_profile_id', $profile->id)
            ->active()
            ->first();
    }
}
