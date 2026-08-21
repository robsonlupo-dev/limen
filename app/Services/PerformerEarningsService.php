<?php

namespace App\Services;

use App\Models\ChatAccess;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Support\FanAlias;
use App\Support\LedgerEntryLabel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Extrato de GANHOS da performer (fix/chat-ux-mobile) — SOMENTE LEITURA do ledger.
 *
 * O membro sempre viu onde gastou; a performer só via o histórico de SAQUES, nunca
 * de onde veio cada crédito. Depois da reforma que fez o 80% valer de verdade (ela
 * recebe 1,6000 por uma abertura de 2), ela precisa CONFERIR que 1,6000 é 80% de 2.
 * Este serviço lê os créditos de ganho do ledger e devolve, por lançamento: o bruto
 * pago pelo membro, o percentual congelado (applied_rate) e o líquido dela — o
 * suficiente para a conta fechar na tela.
 *
 * NÃO cria, altera nem recalcula nada: o bruto é DERIVADO do próprio líquido e da
 * taxa gravada (bruto = líquido × 100 ÷ taxa, exato por construção), não uma segunda
 * fonte de verdade. O membro aparece SEMPRE por FanAlias, nunca por dado real
 * (M.13.10): a maioria dos créditos já carrega o alias na `description` (gravado no
 * ato); o chat, que não carrega, é resolvido pelo elo `chat_access.credit_ledger_id`.
 */
class PerformerEarningsService
{
    /**
     * entry_type → [label exibido, grupo do filtro]. O grupo agrega os tipos que a
     * performer pensa como "uma coisa só" (as duas linhas de chamada = "Chamada").
     */
    private const TYPE_MAP = [
        'tip_credit' => ['Gorjeta', 'tip'],
        'chat_access_credit' => ['Mensagem', 'chat'],
        'content_credit' => ['Conteúdo', 'content'],
        'gift_credit' => ['Presente', 'gift'],
        'call_credit' => ['Chamada', 'call'],
        'call_noshow_credit' => ['Chamada (no-show)', 'call'],
        'live_credit' => ['Live', 'live'],
    ];

    public function __construct(private TokenService $tokenService) {}

    /** Grupos de filtro oferecidos na UI, na ordem de exibição. */
    public function typeGroups(): array
    {
        return [
            ['key' => 'tip', 'label' => 'Gorjeta'],
            ['key' => 'chat', 'label' => 'Mensagem'],
            ['key' => 'content', 'label' => 'Conteúdo'],
            ['key' => 'gift', 'label' => 'Presente'],
            ['key' => 'call', 'label' => 'Chamada'],
            ['key' => 'live', 'label' => 'Live'],
        ];
    }

    /** Todos os entry_type de ganho conhecidos (allowlist de leitura). */
    private function earningTypes(): array
    {
        return array_keys(self::TYPE_MAP);
    }

    /**
     * Página do extrato de ganhos, já mascarado por FanAlias.
     *
     * @param  array{from?:?string,to?:?string,type?:?string}  $filters
     */
    public function paginate(User $performer, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $wallet = TokenWallet::where('user_id', $performer->id)->first();
        $performerProfileId = $performer->performerProfile?->id;

        $query = TokenLedger::query()
            ->when(
                $wallet,
                fn ($q) => $q->where('wallet_id', $wallet->id),
                fn ($q) => $q->whereRaw('1 = 0'),
            )
            ->whereIn('entry_type', $this->typesForFilter($filters['type'] ?? null))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('id');

        $page = $query->paginate($perPage);

        $aliases = $this->resolveMemberAliases(collect($page->items()), $performerProfileId);

        return $page->through(fn (TokenLedger $entry) => $this->present($entry, $aliases));
    }

    /**
     * Resumo do topo: saldo atual (com casas decimais) e a taxa de conversão do
     * saque, para a performer situar o número. Somente leitura.
     */
    public function summary(User $performer): array
    {
        $wallet = TokenWallet::where('user_id', $performer->id)->first();

        return [
            // Raw 4dp: a performer QUER ver as casas (o saldo dela é fracionário por
            // acumular splits). `TokenMath::readable` esconderia o ".0000" de um saldo
            // redondo; aqui o contrato é sempre 4 casas.
            'balance' => $wallet ? $wallet->getRawOriginal('balance') : '0.0000',
            'balance_readable' => $this->tokenService->balance($performer),
            'payout_rate_per_token' => (float) config('monetization.payout_rate_per_token'),
        ];
    }

    /** Grupo de filtro → entry_types. `null`/desconhecido → todos os ganhos. */
    private function typesForFilter(?string $group): array
    {
        if ($group === null || $group === '' || $group === 'all') {
            return $this->earningTypes();
        }

        $types = array_keys(array_filter(
            self::TYPE_MAP,
            fn (array $meta) => $meta[1] === $group,
        ));

        return $types !== [] ? $types : $this->earningTypes();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TokenLedger>  $entries
     * @return array<int, string>  ledger.id → FanAlias label
     */
    private function resolveMemberAliases($entries, ?int $performerProfileId): array
    {
        // O chat não grava o alias na description ("Acesso ao chat recebido") — o
        // membro vem pelo elo reverso chat_access.credit_ledger_id → member_id.
        $chatLedgerIds = $entries
            ->where('entry_type', 'chat_access_credit')
            ->pluck('id')
            ->all();

        $chatMemberByLedger = [];
        if ($chatLedgerIds !== [] && $performerProfileId !== null) {
            $chatMemberByLedger = ChatAccess::whereIn('credit_ledger_id', $chatLedgerIds)
                ->pluck('member_id', 'credit_ledger_id')
                ->all();
        }

        $aliases = [];
        foreach ($entries as $entry) {
            if ($entry->entry_type === 'chat_access_credit') {
                $memberId = $chatMemberByLedger[$entry->id] ?? null;
                $aliases[$entry->id] = ($memberId !== null && $performerProfileId !== null)
                    ? FanAlias::label($performerProfileId, (int) $memberId)
                    : 'Membro';

                continue;
            }

            // Os demais créditos já gravaram "… Fã #NNNN" na description (no ato, por
            // par). Extrair o alias pronto é seguro (é o FanAlias, nunca dado real) e
            // não depende de re-resolver a referência (que é null em tip/gift).
            $aliases[$entry->id] = $this->aliasFromDescription($entry->description) ?? 'Membro';
        }

        return $aliases;
    }

    /** Extrai o rótulo "Fã #NNNN" de uma description, ou null se não houver. */
    private function aliasFromDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        return preg_match('/Fã #\d{4}/u', $description, $m) === 1 ? $m[0] : null;
    }

    /**
     * @param  array<int, string>  $aliases
     * @return array<string, mixed>
     */
    private function present(TokenLedger $entry, array $aliases): array
    {
        // Fallback nunca vaza o nome cru de banco (LedgerEntryLabel, dona única): um
        // entry_type de ganho novo aparece traduzido mesmo antes de ganhar grupo aqui.
        [$label, $group] = self::TYPE_MAP[$entry->entry_type]
            ?? [LedgerEntryLabel::for($entry->entry_type), 'other'];

        // Sempre 4 casas: o líquido é o valor que a performer confere contra o bruto.
        $net = $entry->getRawOriginal('amount'); // "1.6000"
        $rate = (int) $entry->applied_rate;

        // Bruto = líquido × 100 ÷ taxa. bcmath (decimal exato, não float): 1,6000 →
        // 2. É DERIVADO, não uma 2ª fonte — e fecha por construção do split.
        $gross = $rate > 0
            ? (int) bcdiv(bcmul($net, '100', 4), (string) $rate, 0)
            : null;

        return [
            'id' => $entry->id,
            'type_key' => $group,
            'type_label' => $label,
            'member_alias' => $aliases[$entry->id] ?? 'Membro',
            'gross' => $gross,          // inteiro: tokens pagos pelo membro (2)
            'applied_rate' => $rate,    // 80
            'net' => $net,              // "1.6000" (4 casas, string)
            'created_at' => $entry->created_at->format('d/m/Y H:i'),
        ];
    }
}
