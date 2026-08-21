<?php

namespace App\Services;

use App\Exceptions\CapExceededException;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Support\TokenMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A dona ÚNICA das invariantes da economia de tokens (emenda M.13 do CLAUDE.md).
 * Nenhuma outra classe decide teto, split, crédito de chat ou piso. Lê os números
 * de `config/monetization.php` (a fonte canônica). `TokenService` continua sendo o
 * escritor bruto do ledger append-only; esta policy decide QUANTO e SE.
 *
 * Mapa dos itens M.13 implementados aqui:
 *  - M.13.9  teto é do MOVIMENTO (entry_type), nunca da pessoa (role): respectsCap.
 *  - M.13.8  teto escalonado (capFor) + fila de pendência (grant/releaseAfterDebit)
 *            + gatilho de aviso (approachingCap).
 *  - M.13.7  (SUPERADO 19/08/2026) split agora é DECIMAL EXATO (applyRate via
 *            bcmath), não mais round-half-up inteiro — a taxa segue congelada.
 *  - M.13.1  (SUPERADO 19/08/2026) chat NÃO é mais crédito fixo de 1: a abertura
 *            entra no split 80/20 como todo evento (2 → 1,60). Ver ChatAccessService.
 *  - M.13.5  payout R$0,60/token (payoutRatePerToken).
 *  - M.13.6  presente múltiplo de 4 (isValidGiftPrice).
 */
class TokenCreditPolicy
{
    public function __construct(private TokenService $tokenService) {}

    // ── Teto (M.13.8 / M.13.9) ───────────────────────────────────────────────

    /**
     * Um crédito deste tipo respeita o teto? A chave é o entry_type, NUNCA o role
     * do dono da carteira (uma performer pode assinar Círculo — M.13.9).
     */
    public function respectsCap(string $entryType): bool
    {
        return in_array($entryType, config('monetization.cap_respecting_entry_types'), true);
    }

    /** Franquia mensal do tier ativo do usuário, ou 0 se não assina. */
    public function franchiseFor(User $user): int
    {
        return $this->franchiseForSlug($user->activeCircle()?->slug);
    }

    /**
     * Franquia mensal de um tier por slug (M.13.4). A concessão do ciclo deve ser
     * ancorada no Círculo da ASSINATURA sendo concedida — não em activeCircle(),
     * que faz latest('id') e resolveria outra linha se o usuário tivesse duas.
     */
    public function franchiseForSlug(?string $slug): int
    {
        return (int) config("monetization.franchises_by_tier.{$slug}", 0);
    }

    /**
     * Teto de acúmulo do usuário: max(default, multiplier × franquia), com o
     * override do PO para FC (8000). Para todo tier não-FC, 4×franquia < 5000,
     * então o teto é 5000; só FC sobe. saldo > teto é LEGÍTIMO (sem constraint).
     */
    public function capFor(User $user): int
    {
        $slug = $user->activeCircle()?->slug;

        $override = $slug ? config("monetization.cap.overrides.{$slug}") : null;
        if ($override !== null) {
            return (int) $override;
        }

        return max(
            (int) config('monetization.cap.default'),
            (int) config('monetization.cap.multiplier') * $this->franchiseFor($user),
        );
    }

    /** Espaço restante sob o teto, string decimal (o saldo da performer pode fracionar). */
    public function capRemaining(User $user): string
    {
        return TokenMath::sub($this->capFor($user), $this->tokenService->balance($user));
    }

    /**
     * Desconto (%) do tier ativo sobre a COMPRA de pacote avulso (M.13.3), da
     * config — a fonte canônica e a AUTORIDADE de cobrança. 0 se não assina.
     * `circles.discount_pct` no banco é espelho de exibição, mantido em sincronia
     * por migração + teste; quem cobra lê daqui, não do banco.
     */
    public function purchaseDiscountPct(User $user): int
    {
        $slug = $user->activeCircle()?->slug;

        return (int) config("monetization.discounts_by_tier.{$slug}", 0);
    }

    // ── Entrada única de crédito ──────────────────────────────────────────────

    /**
     * Crédito consciente das invariantes:
     *  - subscription_grant → credita o que couber sob o teto, PENDURA o excedente
     *    (fila de pendência, M.13.8); pode devolver null se nada coube.
     *  - purchase / bonus → BARRA (CapExceededException) se estouraria o teto. O
     *    caminho pago (webhook) NÃO usa isto — usa creditPaidPurchase.
     *  - qualquer outro (tip_credit, chat_access_credit, refund, payout_reversal,
     *    adjustment, todo *_credit) → nunca respeita teto, credita cheio.
     */
    public function credit(
        User $user,
        int $amount,
        string $entryType,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): ?TokenLedger {
        if ($entryType === 'subscription_grant') {
            return $this->grantWithPending($user, $amount, $referenceType, $referenceId, $description)['ledger'];
        }

        if ($this->respectsCap($entryType)) {
            // purchase / bonus: barra sob lock (leitura+decisão+crédito atômicos).
            return DB::transaction(function () use ($user, $amount, $entryType, $referenceType, $referenceId, $description) {
                TokenWallet::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
                $wallet = TokenWallet::where('user_id', $user->id)->lockForUpdate()->first();
                $cap = $this->capFor($user);

                if (TokenMath::cmp(TokenMath::add($wallet->balance, $amount), $cap) > 0) {
                    throw new CapExceededException($amount, $wallet->balance, $cap);
                }

                return $this->tokenService->credit($user, $amount, $entryType, $referenceType, $referenceId, $description);
            });
        }

        // Nunca respeita teto — credita cheio.
        return $this->tokenService->credit($user, $amount, $entryType, $referenceType, $referenceId, $description);
    }

    /**
     * Concede a franquia mensal de um ciclo, consciente do teto e da pendência
     * (M.13.8). É o ponto de entrada do command de reconciliação e do webhook de
     * cobrança; devolve credited/pended CAPTURADOS DENTRO da transação travada,
     * para o chamador decidir o aviso de teto (M.13.8) sem um reload que corra com
     * um releaseAfterDebit concorrente.
     *
     * @return array{ledger:?TokenLedger, credited:int, pended:int}
     */
    public function grantFranchise(
        User $user,
        int $amount,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): array {
        return $this->grantWithPending($user, $amount, $referenceType, $referenceId, $description);
    }

    /**
     * subscription_grant sob o teto escalonado, com fila de pendência (M.13.8).
     * Tudo sob o lock do wallet: lê saldo, credita o que couber, e SUBSTITUI a
     * pendência (não empilha) por min(excedente, 1 franquia).
     *
     * @return array{ledger:?TokenLedger, credited:int, pended:int}
     */
    private function grantWithPending(
        User $user,
        int $amount,
        ?string $referenceType,
        ?int $referenceId,
        ?string $description,
    ): array {
        return DB::transaction(function () use ($user, $amount, $referenceType, $referenceId, $description) {
            TokenWallet::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
            $wallet = TokenWallet::where('user_id', $user->id)->lockForUpdate()->first();

            $cap = $this->capFor($user);
            // Grant é movimento INTEIRO: `room` é o espaço em tokens INTEIROS
            // (intFloor) sob o teto — a "poeira" fracionária da carteira da performer
            // (ex.: 0,20 de um split) nunca vira grant nem pendência fracionária
            // (pending_grant_tokens é unsignedInteger). saldo > teto segue legítimo.
            $room = TokenMath::intFloor(TokenMath::max(0, TokenMath::sub($cap, $wallet->balance)));
            $credited = min($amount, $room);
            $remainder = $amount - $credited;

            // Pendência: máximo de 1 franquia; ciclo novo SUBSTITUI, não empilha
            // (M.13.8). Não expira — só o gasto a consome (releaseAfterDebit).
            $newPending = min($remainder, $this->franchiseFor($user));
            $wallet->forceFill(['pending_grant_tokens' => $newPending])->save();

            $ledger = $credited > 0
                ? $this->tokenService->credit($user, $credited, 'subscription_grant', $referenceType, $referenceId, $description)
                : null;

            return ['ledger' => $ledger, 'credited' => $credited, 'pended' => $newPending];
        });
    }

    /**
     * Libera a franquia pendente que o gasto acabou de destravar (M.13.8), na
     * MESMA transação do débito e sobre o wallet JÁ TRAVADO que `TokenService::debit`
     * passa — sem re-consultar, para dois gastos concorrentes nunca liberarem a
     * mesma pendência (serializam no lock). No-op quando não há pendência.
     *
     * Escreve direto (não via TokenService::credit) porque o wallet já está
     * travado e o balance já reflete o débito: a linha subscription_grant + o
     * novo balance + o decremento da pendência são um read-modify-write atômico.
     */
    public function releaseAfterDebit(User $user, TokenWallet $lockedWallet): void
    {
        $pending = (int) $lockedWallet->pending_grant_tokens;
        if ($pending <= 0) {
            return;
        }

        // Espaço em tokens INTEIROS (a pendência liberada é sempre inteira). intFloor
        // ignora a poeira fracionária da carteira da performer (não é grant).
        $room = TokenMath::intFloor(TokenMath::sub($this->capFor($user), $lockedWallet->balance));
        if ($room <= 0) {
            return;
        }

        $release = min($pending, $room);
        $newBalance = TokenMath::add($lockedWallet->balance, $release);

        $lockedWallet->forceFill([
            'balance' => $newBalance,
            'pending_grant_tokens' => $pending - $release,
        ])->save();

        TokenLedger::create([
            'wallet_id' => $lockedWallet->id,
            'entry_type' => 'subscription_grant',
            'amount' => $release,
            'balance_after' => $newBalance,
            'reference_type' => 'pending_release',
            'reference_id' => null,
            'description' => 'Franquia pendente liberada',
        ]);
    }

    // ── Gate de compra vs. webhook (M.13.9) ──────────────────────────────────

    /** O saldo comporta esta compra sem passar do teto? (gate advisory de checkout) */
    public function canPurchase(User $user, int $amount): bool
    {
        return TokenMath::cmp(
            TokenMath::add($this->tokenService->balance($user), $amount),
            $this->capFor($user),
        ) <= 0;
    }

    /**
     * Barra a compra ANTES de criar a cobrança PIX. É best-effort: duas compras
     * concorrentes podem ambas passar o gate e o par de webhooks creditar acima do
     * teto — estado legítimo (M.13.9), igual ao webhook-over-cap. Não é garantia
     * dura de teto sobre compras; o teto duro é só para grant.
     */
    public function assertCanPurchase(User $user, int $amount): void
    {
        if (! $this->canPurchase($user, $amount)) {
            throw new CapExceededException($amount, $this->tokenService->balance($user), $this->capFor($user));
        }
    }

    /**
     * Crédito de compra PAGA (webhook): SEMPRE credita cheio, mesmo acima do teto —
     * dinheiro pago nunca é retido (M.13.9). Loga o excesso para observabilidade,
     * com IDs escalares apenas — NUNCA PII (CPF/e-mail/nome).
     */
    public function creditPaidPurchase(
        User $user,
        int $amount,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): TokenLedger {
        $cap = $this->capFor($user);
        $balanceBefore = $this->tokenService->balance($user);

        $ledger = $this->tokenService->credit($user, $amount, 'purchase', $referenceType, $referenceId, $description);

        if (TokenMath::cmp(TokenMath::add($balanceBefore, $amount), $cap) > 0) {
            Log::warning('token.purchase_over_cap', [
                'user_id' => $user->id,
                'amount' => $amount,
                'balance_after' => $ledger->balance_after,
                'cap' => $cap,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        }

        return $ledger;
    }

    // ── Split percentual (M.13.6 / M.13.7) ───────────────────────────────────

    /** Taxa inteira do tipo de evento vigente (70/80/100), da config. (75 sobrevive só congelado em presentes antigos.) */
    public function rateFor(string $rateKey): int
    {
        return (int) config("monetization.split_rates.{$rateKey}.rate");
    }

    /**
     * Split DECIMAL EXATO (bcmath, escala 4) — substitui o round-half-up inteiro de
     * M.13.7 (emenda 19/08/2026). O bruto `$amount` é sempre inteiro (o membro gasta
     * inteiro); como `amount × rate` é inteiro e a divisão é por 100, o crédito tem
     * no máximo 2 casas: é EXATO, sem arredondamento. "Nunca float" continua valendo
     * — bcmath é decimal exato, não ponto flutuante.
     *
     *   credited = amount × rate ÷ 100  (ex.: 2 × 80 ÷ 100 = "1.6000")
     *   retained = amount − credited     (o complemento, nunca recalculado)
     * credited + retained == amount SEMPRE. Retornos são STRINGS de escala 4.
     *
     * @return array{credited:string, retained:string, rate:int}
     */
    public function applyRate(int $amount, string $rateKey): array
    {
        $rate = $this->rateFor($rateKey);
        // bcmul escala 0 → produto inteiro exato; bcdiv escala 4 → sem perda.
        $credited = bcdiv(bcmul((string) $amount, (string) $rate, 0), '100', TokenMath::SCALE);

        return [
            'credited' => $credited,
            'retained' => TokenMath::sub($amount, $credited),
            'rate' => $rate,
        ];
    }

    /**
     * Credita a fatia da performer no split de um evento e CONGELA a taxa na linha
     * (applied_rate). Nunca respeita teto (crédito de performer). O valor gravado é
     * o `amount` calculado — a leitura nunca recalcula a partir da taxa.
     */
    // Nota da revisão: Tip/Gift chamam applyRate() para o espelho e depois
    // creditWithSplit() recalcula applyRate() internamente. É DETERMINÍSTICO (mesmo
    // input → mesmo output), então espelho e ledger não divergem; mantido assim de
    // propósito — passar o split pré-computado por 6 call sites de dinheiro adicionaria
    // superfície de erro maior que a divergência (inexistente) que evitaria.
    public function creditWithSplit(
        User $performer,
        int $gross,
        string $rateKey,
        string $entryType,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): TokenLedger {
        $split = $this->applyRate($gross, $rateKey);

        return $this->tokenService->credit(
            $performer,
            $split['credited'],
            $entryType,
            $referenceType,
            $referenceId,
            $description,
            appliedRate: $split['rate'],
        );
    }

    public function contentFloor(): int
    {
        return (int) config('monetization.content_floor');
    }

    /** Passo do preço de conteúdo permanente (M.4): múltiplo de N tokens. */
    public function contentPriceStep(): int
    {
        return (int) config('monetization.content_price_step');
    }

    /** Preço de conteúdo válido: ≥ piso E múltiplo do passo (M.4). */
    public function isValidContentPrice(int $price): bool
    {
        $step = $this->contentPriceStep();

        return $price >= $this->contentFloor() && $step > 0 && $price % $step === 0;
    }

    // ── Chat / economia de mensagem (M.13.1 SUPERADO 19/08/2026) ─────────────

    /** Custo em tokens do membro para abrir chat, por tier (2, ou 1 em Black/FC). */
    public function chatCost(User $member): int
    {
        $slug = $member->activeCircle()?->slug ?? 'none';

        return (int) config(
            "monetization.chat.cost_by_tier.{$slug}",
            config('monetization.chat.cost_by_tier.none'),
        );
    }

    // A abertura de chat NÃO credita mais um token fixo (o modelo antigo, M.13.1). A
    // performer tem contrato de 80%, e 80% de 2 = 1,60 — o `chatOpenPerformerCredit()`
    // fixo entregava 1 (= 50%), quebrando esse contrato. Agora o crédito passa pelo
    // split 80/20 como todo evento: `creditWithSplit($performer, $chatCost, 'chat',
    // 'chat_access_credit', …)` no ChatAccessService. A taxa vive em
    // `monetization.split_rates.chat`.

    // ── Aviso de aproximação do teto (M.13.8) ────────────────────────────────

    /**
     * Espaço_restante ≤ 2×franquia (assinante) ou ≤ 4500 (não-assinante). Só o
     * sinal; a UI/e-mail é follow-up.
     */
    public function approachingCap(User $user): bool
    {
        $franchise = $this->franchiseFor($user);
        $threshold = $user->activeCircle() !== null
            ? 2 * $franchise
            : (int) config('monetization.cap.warning_remaining_no_tier');

        return $this->capRemaining($user) <= $threshold;
    }

    // ── Presentes (M.13.6) e Payout (M.13.5) — constantes/validação ───────────

    /** Preço de presente DEVE ser positivo e múltiplo de 4 tokens. */
    public function isValidGiftPrice(int $price): bool
    {
        $multiple = (int) config('monetization.gift_price_multiple');

        return $price > 0 && $price % $multiple === 0;
    }

    /** Valor em R$ de cada token no saque (M.13.5): 0,60 fixo. */
    public function payoutRatePerToken(): float
    {
        return (float) config('monetization.payout_rate_per_token');
    }

    /**
     * Valor de cada token no saque em CENTAVOS inteiros (M.13.5). O caminho
     * autoritativo (token→R$) é inteiro puro — nunca float — para o valor mandado
     * ao Asaas: centavos = tokens × payoutCentavosPerToken. bcmul (não `round()`
     * sobre float) fecha o 🟢#4 da revisão: R$0,60 → 60 centavos, exato.
     */
    public function payoutCentavosPerToken(): int
    {
        return (int) bcmul((string) config('monetization.payout_rate_per_token'), '100', 0);
    }
}
