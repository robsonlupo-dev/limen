<?php

namespace App\Services;

use App\Models\ContentFlag;
use App\Models\User;
use App\Support\ChatContentFilter;
use Illuminate\Support\Facades\Cache;

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
     * Sinaliza um TEXTO qualquer (feat/flagged-content-more-sources, Fase 4c-b):
     * bio do membro, apelido — fontes fora do chat. Só a categoria REAL de CONDUTA
     * vira flag (o `ProfileTextGuard`/`MemberNicknameService` rotulam legal e
     * conduta juntos, mas risco legal não entra na fila de reincidência, como no
     * chat). Deduplicado por janela, porque essas fontes não têm audit próprio.
     */
    public function recordFromText(User $user, string $source, string $text): void
    {
        $match = ChatContentFilter::match($text);

        if ($match === null || $match['category'] !== ChatContentFilter::CONDUCT) {
            return;
        }

        $this->recordOnce($user, $source, ChatContentFilter::digest($match['rule']));
    }

    /**
     * `record()` com deduplicação atômica por (usuário, fonte, regra) na janela —
     * para fontes que NÃO carregam o próprio dedup (ao contrário do chat, que já
     * roda atrás do dedup do audit). Mesmo cache/janela do filtro do chat.
     */
    public function recordOnce(User $user, string $source, string $ruleHash): void
    {
        $minutes = max(1, (int) config('chat_filters.audit_dedup_minutes'));
        $key = 'content-flag:'.$user->id.':'.$source.':'.substr($ruleHash, 0, 32);

        // add() é atômico: dois submits simultâneos não viram duas linhas.
        if (! Cache::add($key, true, now()->addMinutes($minutes))) {
            return;
        }

        $this->record($user, $source, $ruleHash);
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
