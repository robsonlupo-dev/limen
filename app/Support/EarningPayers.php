<?php

namespace App\Support;

use App\Models\CallReservation;
use App\Models\CallSession;
use App\Models\CallSessionParticipant;
use App\Models\ChatAccess;
use App\Models\ContentUnlock;
use App\Models\GiftSend;
use App\Models\Tip;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;

/**
 * Resolve QUEM PAGOU (o membro) por trás de cada crédito de ganho da performer,
 * pelos MESMOS elos reversos que o PerformerEarningsService usa para exibir o
 * FanAlias no extrato. Aqui a finalidade é anti-fraude: descobrir se a performer
 * indicada teve um 1º ganho vindo de um TERCEIRO pagador (não o indicador, não ela
 * mesma) — a trava central da conversão 3.B (docs/PROGRAMA_INDICACAO.md §3.B/§8).
 *
 * ⚠️ O mapa de elos reversos é o mesmo de App\Services\PerformerEarningsService
 * (a dona da EXIBIÇÃO do extrato). Mantido duplicado de propósito nesta primeira
 * entrega para não desestabilizar aquele caminho de leitura sensível; se um
 * terceiro consumidor aparecer, extrair o mapa para cá e fazer os dois lerem daqui.
 */
final class EarningPayers
{
    /** entry_type → [Model, FK p/ ledger.id, coluna do member]. */
    private const REVERSE_LINKS = [
        'tip_credit' => [Tip::class, 'performer_ledger_id', 'consumer_id'],
        'gift_credit' => [GiftSend::class, 'performer_ledger_id', 'sender_id'],
        'content_credit' => [ContentUnlock::class, 'credit_ledger_id', 'user_id'],
        'chat_access_credit' => [ChatAccess::class, 'credit_ledger_id', 'member_id'],
    ];

    /** entry_types de chamada, resolvidos pela referência do lançamento. */
    private const CALL_TYPES = ['call_credit', 'call_noshow_credit'];

    private const CALL_MODELS = [CallSession::class, CallSessionParticipant::class, CallReservation::class];

    /**
     * IDs distintos dos membros que pagaram os ganhos desta performer.
     *
     * @return array<int, int>  user_ids, sem repetição
     */
    public static function forPerformer(User $performer): array
    {
        $walletId = TokenWallet::where('user_id', $performer->id)->value('id');
        if ($walletId === null) {
            return [];
        }

        $entries = TokenLedger::query()
            ->where('wallet_id', $walletId)
            ->whereIn('entry_type', array_merge(array_keys(self::REVERSE_LINKS), self::CALL_TYPES))
            ->get(['id', 'entry_type', 'reference_type', 'reference_id']);

        if ($entries->isEmpty()) {
            return [];
        }

        $payerIds = [];

        foreach (self::REVERSE_LINKS as $type => [$model, $ledgerFk, $memberColumn]) {
            $ledgerIds = $entries->where('entry_type', $type)->pluck('id')->all();
            if ($ledgerIds === []) {
                continue;
            }

            foreach ($model::whereIn($ledgerFk, $ledgerIds)->pluck($memberColumn) as $memberId) {
                if ($memberId !== null) {
                    $payerIds[(int) $memberId] = true;
                }
            }
        }

        self::resolveCallPayers($entries->whereIn('entry_type', self::CALL_TYPES), $payerIds);

        return array_keys($payerIds);
    }

    /**
     * Preenche $payerIds (chave = user_id) para os créditos de chamada, agrupando
     * pela classe referenciada. A allowlist de reference_type torna seguro resolver
     * o modelo dinamicamente.
     *
     * @param  \Illuminate\Support\Collection<int, TokenLedger>  $callEntries
     * @param  array<int, bool>  $payerIds  preenchido in-place
     */
    private static function resolveCallPayers($callEntries, array &$payerIds): void
    {
        $idsByModel = [];
        foreach ($callEntries as $entry) {
            if ($entry->reference_id === null || ! in_array($entry->reference_type, self::CALL_MODELS, true)) {
                continue;
            }
            $idsByModel[$entry->reference_type][] = (int) $entry->reference_id;
        }

        foreach ($idsByModel as $model => $ids) {
            foreach ($model::whereIn('id', array_unique($ids))->pluck('member_id') as $memberId) {
                if ($memberId !== null) {
                    $payerIds[(int) $memberId] = true;
                }
            }
        }
    }
}
