<?php

namespace App\Services;

use App\Models\ContentFlag;
use App\Models\User;

/**
 * Pipeline de sinalização automática de conteúdo (feat/flagged-content-queue,
 * Fase 4c). Dona única do REGISTRO de um flag e da DISPENSA em massa.
 *
 * `record()` NÃO deduplica: quem chama (o auditBlock do chat/live) já roda atrás
 * do próprio dedup por (usuário, regra) na janela — então uma linha de flag nasce
 * exatamente quando uma linha de audit nasce, na mesma cadência. O corpo nunca
 * chega aqui: recebemos o HMAC da regra pronto.
 *
 * Extensível: qualquer subsistema que bloqueie conduta chama `record()` com a sua
 * `source` (ver as constantes SOURCE_* do model).
 */
class ContentFlagService
{
    /**
     * Registra um flag de conduta. Só 'conduct' é sinalizado — risco legal é
     * barrado e contado no audit, mas não vira fila de reincidência.
     */
    public function record(User $user, string $source, string $ruleHash): void
    {
        $flag = new ContentFlag;
        $flag->forceFill([
            'user_id' => $user->id,
            'source' => $source,
            'category' => ContentFlag::CATEGORY_CONDUCT,
            'rule_hash' => $ruleHash,
            'status' => ContentFlag::STATUS_PENDING,
        ])->save();
    }

    /**
     * Dispensa (marca revisado) TODOS os flags pendentes de um usuário. É como o
     * caso "sai da fila" — o moderador olhou e decidiu não agir (ou já agiu por
     * advertência/suspensão à parte). Devolve quantos foram dispensados.
     */
    public function dismissAllFor(User $user, User $moderator): int
    {
        return ContentFlag::query()
            ->where('user_id', $user->id)
            ->where('status', ContentFlag::STATUS_PENDING)
            ->update([
                'status' => ContentFlag::STATUS_DISMISSED,
                'reviewed_at' => now(),
                'reviewed_by' => $moderator->id,
            ]);
    }
}
