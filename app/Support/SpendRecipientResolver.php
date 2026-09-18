<?php

namespace App\Support;

use App\Models\PerformerContent;
use App\Models\PerformerInterest;
use App\Models\TokenLedger;

/**
 * Resolve a performer que RECEBEU cada gasto do membro (ledger.id → nome
 * artístico público). Conteúdo e interesse referenciam a peça por id → performer
 * (batched); gorjeta, presente e chat gravam o nome na própria descrição
 * ("… para X" / "… de X"), extraído no fallback. Nunca vaza id de banco — só o
 * nome público da performer.
 *
 * A performer é pública, então o nome dela aparece para o membro. NÃO confundir
 * com o lado inverso (a performer só vê FanAlias/apelido do membro).
 *
 * Fonte única compartilhada pelo painel "Últimos gastos" (Dashboard) e pelo
 * extrato completo (Wallet/History) — antes o extrato só mostrava o tipo.
 */
class SpendRecipientResolver
{
    /** Tipos que gravam o nome da performer na descrição (sem reference_id). */
    private const DESCRIPTION_TYPES = ['spend_tip', 'spend_gift', 'spend_chat_access'];

    /**
     * @param  iterable<TokenLedger>  $entries
     * @return array<int, ?string>  ledger.id → nome público da performer (ou null)
     */
    public function resolve(iterable $entries): array
    {
        $refMap = [PerformerContent::class => [], PerformerInterest::class => []];
        foreach ($entries as $e) {
            if (array_key_exists($e->reference_type, $refMap) && $e->reference_id) {
                $refMap[$e->reference_type][$e->id] = $e->reference_id;
            }
        }

        $byLedger = [];
        foreach ($refMap as $model => $map) {
            if ($map === []) {
                continue;
            }
            $rows = $model::whereIn('id', array_values($map))
                ->with('performerProfile:id,stage_name')
                ->get()
                ->keyBy('id');
            foreach ($map as $ledgerId => $refId) {
                $byLedger[$ledgerId] = $rows[$refId]?->performerProfile?->stage_name;
            }
        }

        $out = [];
        foreach ($entries as $e) {
            $out[$e->id] = $byLedger[$e->id]
                ?? (in_array($e->entry_type, self::DESCRIPTION_TYPES, true)
                    ? $this->recipientFromDescription($e->description)
                    : null);
        }

        return $out;
    }

    /** Extrai "… para X" / "… de X" da descrição (nome já gravado no débito). */
    private function recipientFromDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        return preg_match('/ (?:para|de) (.+)$/u', $description, $m) === 1 ? trim($m[1]) : null;
    }
}
