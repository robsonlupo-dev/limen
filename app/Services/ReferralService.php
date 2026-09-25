<?php

namespace App\Services;

use App\Exceptions\CapExceededException;
use App\Jobs\SendReferralRewardEmail;
use App\Jobs\SendReferralSignupEmail;
use App\Models\AgeVerification;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\TokenLedger;
use App\Models\User;
use App\Support\EarningPayers;
use App\Support\TokenMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Programa de indicação (feat/referral-program). Desenho canônico:
 * `docs/PROGRAMA_INDICACAO.md`.
 *
 * Regras estruturais (não afrouxar sem o PO):
 *  - Bônus é NÃO-SACÁVEL: creditado via TokenCreditPolicy como `referral_bonus`,
 *    que está FORA do allowlist de payout. Nunca vira R$0,60/token.
 *  - Duas conversões qualificadas, nunca o cadastro em si: 1ª compra confirmada do
 *    membro (3.A) OU KYC aprovado + 1º ganho de um TERCEIRO pagador da performer
 *    (3.B). A exigência de terceiro é a trava contra o indicador fechar o ciclo
 *    sozinho com uma 2ª conta.
 *  - Vínculo (`users.referred_by_user_id`) é gravado UMA vez no cadastro e imutável.
 *  - Hold anti-estorno antes de creditar; re-verificação da base no crédito.
 */
class ReferralService
{
    public function __construct(private TokenCreditPolicy $creditPolicy, private TokenService $tokenService) {}

    public function enabled(): bool
    {
        return (bool) config('referral.enabled', false);
    }

    // ── Código do usuário ─────────────────────────────────────────────────────

    /**
     * Garante que o usuário tenha um código único, gerando sob demanda. `referral_code`
     * fica fora do $fillable — escrita por forceFill (autoridade do servidor).
     */
    public function ensureCode(User $user): string
    {
        if (filled($user->referral_code)) {
            return $user->referral_code;
        }

        $code = $this->generateUniqueCode();
        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    private function generateUniqueCode(): string
    {
        $prefix = (string) config('referral.code_prefix', 'LM-');

        // Alfabeto sem caracteres ambíguos (0/O, 1/I/L) — o código é digitado à mão.
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $body = '';
            for ($i = 0; $i < 5; $i++) {
                $body .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = $prefix.$body;

            if (! User::where('referral_code', $code)->exists()) {
                return $code;
            }
        }

        // Colisão improvável 10× seguidas: cai num sufixo aleatório mais longo.
        return $prefix.Str::upper(Str::random(8));
    }

    // ── Atribuição no cadastro ────────────────────────────────────────────────

    /**
     * Vincula o novo usuário a quem o indicou, a partir do código. Chamado DENTRO
     * da transação de cadastro (AuthService). NUNCA derruba o cadastro: qualquer
     * problema (código inválido, auto-indicação, contas ligadas, programa desligado)
     * apenas NÃO cria o vínculo.
     *
     * @param  string  $via  'link' (veio de ?ref=/cookie) ou 'code' (digitou o campo)
     */
    public function attributeAtSignup(User $newUser, ?string $code, string $via = 'code'): void
    {
        try {
            if (! $this->enabled() || blank($code)) {
                return;
            }

            // Imutabilidade: o vínculo é gravado UMA vez. Se já existe (ou já há uma
            // indicação para este usuário), nunca sobrescreve — nem reprocessa.
            if (filled($newUser->referred_by_user_id)
                || Referral::where('referred_user_id', $newUser->id)->exists()) {
                return;
            }

            $code = trim($code);
            $referrer = User::where('referral_code', $code)->first();

            if (! $referrer || $referrer->id === $newUser->id) {
                return; // código inexistente ou o próprio código (impossível no cadastro, mas defensivo)
            }

            // Auto-indicação: bloqueia só por MESMO CPF (mesma pessoa, definitivo).
            // Mesmo IP NÃO bloqueia — indicar gente da mesma casa é legítimo (o IP é
            // sinal fraco). A 2ª camada é a exigência de terceiro pagador na conversão
            // da performer, que aí sim usa o sinal de IP.
            if ($this->sharesCpf($referrer, $newUser)) {
                Log::info('referral.attribution_blocked_linked', [
                    'referrer_id' => $referrer->id,
                    'referred_id' => $newUser->id,
                ]);

                return;
            }

            $role = $newUser->role === 'performer' ? 'performer' : 'member';

            $newUser->forceFill([
                'referred_by_user_id' => $referrer->id,
                'referred_via' => in_array($via, ['link', 'code'], true) ? $via : 'code',
            ])->save();

            // UNIQUE(referred_user_id) — firstOrCreate é a trava de idempotência.
            $referral = Referral::firstOrCreate(
                ['referred_user_id' => $newUser->id],
                [
                    'referrer_user_id' => $referrer->id,
                    'referred_role_at_signup' => $role,
                    'status' => 'pending',
                ],
            );

            if ($referral->wasRecentlyCreated) {
                SendReferralSignupEmail::dispatch($referrer->id, $this->displayLabelFor($newUser))->afterCommit();
            }
        } catch (\Throwable $e) {
            // Cadastro é sagrado: engole e loga, nunca propaga.
            Log::warning('referral.attribution_failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Gatilhos de conversão ─────────────────────────────────────────────────

    /**
     * Conversão 3.A: o membro indicado fez a 1ª compra confirmada. Chamado do
     * PaymentService::confirmPayment (após a transação de crédito). Idempotente:
     * só age numa indicação `pending` de papel `member`. Nunca lança.
     */
    public function onReferredMemberPurchase(User $member): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            $referral = Referral::where('referred_user_id', $member->id)
                ->where('referred_role_at_signup', 'member')
                ->where('status', 'pending')
                ->first();

            if (! $referral) {
                return;
            }

            $referrer = $referral->referrer;
            if (! $referrer || $this->sharesCpf($referrer, $member)) {
                $referral->update(['status' => 'clawed_back', 'rejection_reason' => 'linked_accounts']);

                return;
            }

            $this->qualify($referral, 'member_purchase');
        } catch (\Throwable $e) {
            // Confirmação de pagamento nunca pode quebrar por causa da indicação.
            Log::warning('referral.member_purchase_hook_failed', [
                'member_id' => $member->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A compra que qualificou uma indicação de membro foi ESTORNADA/CONTESTADA.
     * Chamado do PaymentService::handleReversal. Se, depois do estorno, o membro
     * NÃO tem mais nenhuma compra confirmada, retém o bônus (antes do crédito) ou o
     * estorna (depois). Se ainda resta uma compra confirmada, a base se sustenta e
     * nada muda. Idempotente e nunca lança.
     */
    public function onMemberPurchaseReversed(User $member): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            $referral = Referral::where('referred_user_id', $member->id)
                ->where('referred_role_at_signup', 'member')
                ->whereIn('status', ['qualified', 'rewarded'])
                ->first();

            if (! $referral) {
                return;
            }

            // A base ainda se sustenta se sobrou QUALQUER compra confirmada.
            if ($member->payments()->where('status', 'confirmed')->exists()) {
                return;
            }

            if ($referral->status === 'rewarded') {
                $this->clawback($referral, 'base_reversed'); // estorna o já creditado
            } else {
                // Ainda em hold (não creditado): retém.
                $referral->update(['status' => 'clawed_back', 'rejection_reason' => 'base_reversed']);
                $referral->rewards()->where('status', 'pending')->update(['status' => 'skipped']);
            }
        } catch (\Throwable $e) {
            Log::warning('referral.member_reversal_hook_failed', [
                'member_id' => $member->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parte 1 da conversão 3.B: a performer indicada teve o KYC aprovado. Chamado do
     * KycService::approve. Só REGISTRA — a conversão só fecha quando houver, além
     * disto, o 1º ganho de um terceiro (detectado no sweep). Nunca lança.
     */
    public function onPerformerKycApproved(User $performer): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            $referral = Referral::where('referred_user_id', $performer->id)
                ->where('referred_role_at_signup', 'performer')
                ->where('status', 'pending')
                ->whereNull('kyc_approved_at')
                ->first();

            $referral?->update(['kyc_approved_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('referral.kyc_hook_failed', [
                'performer_id' => $performer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Marca a indicação como qualificada e abre o hold, criando as duas linhas de
     * recompensa pendentes (indicador + indicado). Idempotente pela UNIQUE
     * (referral_id, role) + guarda de status.
     */
    private function qualify(Referral $referral, string $trigger): void
    {
        DB::transaction(function () use ($referral, $trigger) {
            $referral = Referral::where('id', $referral->id)->lockForUpdate()->first();
            if (! $referral || $referral->status !== 'pending') {
                return;
            }

            $rewards = config("referral.rewards.{$trigger}");
            $holdDays = (int) config('referral.hold_days', 14);

            $referral->update([
                'status' => 'qualified',
                'qualified_at' => now(),
                'hold_until' => now()->addDays($holdDays),
            ]);

            foreach (['referrer' => $referral->referrer_user_id, 'referred' => $referral->referred_user_id] as $role => $userId) {
                ReferralReward::firstOrCreate(
                    ['referral_id' => $referral->id, 'role' => $role],
                    [
                        'beneficiary_user_id' => $userId,
                        'trigger' => $trigger,
                        'amount' => (int) ($rewards[$role] ?? 0),
                        'status' => 'pending',
                    ],
                );
            }
        });
    }

    // ── Sweep (referral:process-holds) ────────────────────────────────────────

    /**
     * Motor do command agendado: (1) detecta a conversão da performer (KYC + 1º
     * ganho de terceiro), (2) credita as indicações cujo hold venceu, re-verificando
     * a base. Devolve contadores para o command reportar.
     *
     * @return array{qualified:int, rewarded:int, clawed_back:int, deferred:int}
     */
    public function processHolds(): array
    {
        $counts = ['qualified' => 0, 'rewarded' => 0, 'clawed_back' => 0, 'deferred' => 0];

        if (! $this->enabled()) {
            return $counts;
        }

        // (1) Detecta a conversão da performer.
        $pendingPerformers = Referral::where('referred_role_at_signup', 'performer')
            ->where('status', 'pending')
            ->whereNotNull('kyc_approved_at')
            ->get();

        foreach ($pendingPerformers as $referral) {
            if ($this->performerHasThirdPartyEarning($referral)) {
                $this->qualify($referral, 'performer_first_earning');
                $counts['qualified']++;
            }
        }

        // (2) Credita o que passou do hold.
        $due = Referral::where('status', 'qualified')
            ->whereNotNull('hold_until')
            ->where('hold_until', '<=', now())
            ->get();

        foreach ($due as $referral) {
            $outcome = $this->settle($referral);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Credita os dois lados de uma indicação em hold vencido, re-verificando a base
     * (o hold de 14 dias cobre a janela de contestação; a re-verificação é o
     * clawback antes do crédito). Devolve 'rewarded' | 'clawed_back' | 'deferred'.
     */
    private function settle(Referral $referral): string
    {
        // Re-verifica a base ainda de pé (fecha a janela de estorno sem depender de
        // um webhook de refund): se a base caiu no hold, nunca credita.
        if (! $this->baseStillValid($referral)) {
            $referral->update(['status' => 'clawed_back', 'rejection_reason' => 'base_reversed']);
            $referral->rewards()->where('status', 'pending')->update(['status' => 'skipped']);

            return 'clawed_back';
        }

        $allCredited = true;

        foreach ($referral->rewards()->where('status', 'pending')->get() as $reward) {
            $beneficiary = $reward->beneficiary;
            if (! $beneficiary || $reward->amount <= 0) {
                $reward->update(['status' => 'skipped']);

                continue;
            }

            // Idempotência a crash: o crédito (policy) commita numa transação e o
            // update do status abaixo é outra escrita. Se um crash cair entre as
            // duas, a linha de ledger já existe e o reward fica `pending` — sem esta
            // guarda o próximo sweep recreditaria. Mesma disciplina do
            // PaymentService::confirmPayment (dedup por referência ANTES de creditar).
            $existing = TokenLedger::where('entry_type', 'referral_bonus')
                ->where('reference_type', 'referral_reward')
                ->where('reference_id', $reward->id)
                ->first();

            if ($existing) {
                $reward->update([
                    'status' => 'rewarded',
                    'ledger_id' => $existing->id,
                    'rewarded_at' => $reward->rewarded_at ?? now(),
                ]);

                continue; // já creditado numa rodada anterior; não reenvia e-mail
            }

            try {
                $ledger = $this->creditPolicy->credit(
                    $beneficiary,
                    $reward->amount,
                    'referral_bonus',
                    'referral_reward',
                    $reward->id,
                    'Bônus de indicação',
                );

                $reward->update([
                    'status' => 'rewarded',
                    'ledger_id' => $ledger?->id,
                    'rewarded_at' => now(),
                ]);

                SendReferralRewardEmail::dispatch(
                    $beneficiary->id,
                    $reward->role,
                    $reward->amount,
                    $this->counterpartyLabelFor($referral, $reward->role),
                )->afterCommit();
            } catch (CapExceededException $e) {
                // O beneficiário está no teto de acúmulo. Adia: fica pending e o
                // próximo sweep tenta de novo (ele pode gastar e abrir espaço).
                $allCredited = false;
                Log::info('referral.reward_deferred_cap', [
                    'reward_id' => $reward->id,
                    'beneficiary_id' => $beneficiary->id,
                ]);
            }
        }

        if (! $allCredited) {
            return 'deferred'; // mantém a indicação em 'qualified' para o próximo sweep
        }

        $referral->update(['status' => 'rewarded']);

        return 'rewarded';
    }

    /**
     * A base da conversão ainda vale? Membro: continua com ≥1 pagamento confirmado.
     * Performer: continua verificada E com ganho de terceiro. Se a base caiu durante
     * o hold, a indicação é estornada antes de creditar.
     */
    private function baseStillValid(Referral $referral): bool
    {
        $referred = $referral->referred;
        if (! $referred) {
            return false;
        }

        if ($referral->referred_role_at_signup === 'member') {
            return $referred->payments()->where('status', 'confirmed')->exists();
        }

        return (bool) $referred->performerProfile?->is_verified
            && $this->performerHasThirdPartyEarning($referral);
    }

    /**
     * A performer indicada tem ao menos um ganho vindo de um TERCEIRO pagador — um
     * membro que NÃO é o indicador, NÃO é ela mesma, e não está ligado a nenhum dos
     * dois (mesmo CPF / mesmo IP de cadastro)? Trava central da conversão 3.B.
     */
    private function performerHasThirdPartyEarning(Referral $referral): bool
    {
        $performer = $referral->referred;
        $referrer = $referral->referrer;
        if (! $performer || ! $referrer) {
            return false;
        }

        $payerIds = EarningPayers::forPerformer($performer);

        foreach ($payerIds as $payerId) {
            if ($payerId === $performer->id || $payerId === $referrer->id) {
                continue;
            }

            $payer = User::find($payerId);
            if (! $payer) {
                continue;
            }

            if ($this->areLinkedAccounts($payer, $referrer) || $this->areLinkedAccounts($payer, $performer)) {
                continue;
            }

            return true; // terceiro genuíno
        }

        return false;
    }

    // ── Clawback pós-crédito ──────────────────────────────────────────────────

    /**
     * Estorna um bônus JÁ CREDITADO quando a base é revertida DEPOIS do crédito
     * (chargeback/refund fora da janela do hold). Debita `referral_bonus_reversal`
     * até o saldo disponível (o beneficiário pode já ter gastado parte — recupera o
     * que restou; ledger append-only, saldo nunca fica negativo).
     *
     * Acionado por `onMemberPurchaseReversed` (webhook de refund/chargeback do
     * Asaas → PaymentService::handleReversal) e disponível para ação administrativa.
     * O hold de 14 dias + a re-verificação da base no crédito (baseStillValid) são a
     * proteção em camadas. Ver docs/PROGRAMA_INDICACAO.md §4.1.
     */
    public function clawback(Referral $referral, string $reason): void
    {
        DB::transaction(function () use ($referral, $reason) {
            $referral = Referral::where('id', $referral->id)->lockForUpdate()->first();
            if (! $referral || $referral->status === 'clawed_back') {
                return;
            }

            foreach ($referral->rewards()->where('status', 'rewarded')->get() as $reward) {
                $beneficiary = $reward->beneficiary;
                if ($beneficiary) {
                    // Debita no máximo o que ainda há de saldo (inteiro): se já gastou,
                    // recupera o resto; nunca deixa o saldo negativo.
                    $available = TokenMath::intFloor($this->tokenService->balance($beneficiary));
                    $take = min($reward->amount, max(0, $available));

                    if ($take > 0) {
                        $this->tokenService->debit(
                            $beneficiary,
                            $take,
                            'referral_bonus_reversal',
                            'referral_reward',
                            $reward->id,
                            'Estorno de bônus de indicação',
                        );
                    }
                }

                $reward->update(['status' => 'reversed']);
            }

            $referral->update(['status' => 'clawed_back', 'rejection_reason' => $reason]);
        });
    }

    // ── Anti-fraude: contas ligadas ───────────────────────────────────────────

    /**
     * Mesma PESSOA por identidade DEFINITIVA: mesmo id, ou mesmo CPF (digest HMAC em
     * age_verifications). CPF é um-por-pessoa, então isto é bloqueio duro legítimo —
     * é o que barra a ATRIBUIÇÃO (uma pessoa não indica a si mesma com 2ª conta).
     */
    public function sharesCpf(User $a, User $b): bool
    {
        if ($a->id === $b->id) {
            return true;
        }

        $cpfsA = $this->cpfHashesFor($a);

        return $cpfsA !== [] && array_intersect($cpfsA, $this->cpfHashesFor($b)) !== [];
    }

    /**
     * Sinal MAIS AMPLO de contas ligadas: mesma pessoa (sharesCpf) OU mesmo IP de
     * cadastro. O IP é um sinal fraco (uma casa/rede inteira compartilha), então NÃO
     * bloqueia a atribuição — indicar alguém da mesma casa é legítimo. É usado só na
     * trava do "terceiro pagador" da performer, onde recusar um pagador suspeito é
     * conservador e a performer ainda qualifica por outro pagador genuíno.
     */
    public function areLinkedAccounts(User $a, User $b): bool
    {
        if ($this->sharesCpf($a, $b)) {
            return true;
        }

        return filled($a->registration_ip_hash)
            && $a->registration_ip_hash === $b->registration_ip_hash;
    }

    /** @return array<int, string> digests de CPF conhecidos do usuário (membro). */
    private function cpfHashesFor(User $user): array
    {
        return AgeVerification::where('user_id', $user->id)
            ->whereNotNull('cpf_hmac')
            ->pluck('cpf_hmac')
            ->all();
    }

    // ── Rótulos com privacidade (§7.3) ────────────────────────────────────────

    /**
     * Como um usuário aparece para o OUTRO lado, sem vazar dado real: performer pelo
     * stage_name (já público); membro pelo apelido quando existe; senão, frase
     * genérica. NUNCA nome real, e-mail ou CPF.
     */
    public function displayLabelFor(User $user): string
    {
        if ($user->role === 'performer') {
            $stage = $user->performerProfile?->stage_name;
            if (filled($stage)) {
                return $stage;
            }
        }

        if (filled($user->nickname)) {
            return $user->nickname;
        }

        return $user->role === 'performer' ? 'uma performer que você indicou' : 'um membro que você indicou';
    }

    /** Rótulo da contraparte para o e-mail de recompensa, do ponto de vista do papel. */
    private function counterpartyLabelFor(Referral $referral, string $role): string
    {
        // O indicador vê quem ele indicou; o indicado é premiado por si mesmo (sem
        // contraparte a nomear).
        return $role === 'referrer'
            ? $this->displayLabelFor($referral->referred)
            : '';
    }
}
