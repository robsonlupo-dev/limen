<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Support\TokenMath;
use App\Exceptions\PayoutNotAllowedException;
use App\Mail\PayoutNeedsReviewMail;
use App\Models\PaymentEvent;
use App\Models\Payout;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\AsaasUnavailableException;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PayoutService
{
    // Only reconcile payouts that have been in flight for a while, so a normal
    // in-progress transfer and its webhook have had time to arrive first.
    private const RECONCILE_MIN_AGE_MINUTES = 15;

    // How long a payout may stay unresolvable before the reconcile stops retrying it
    // and hands it to a human. The lookup search is not read-after-write, so an empty
    // result right after createTransfer proves nothing — but hours later it is no
    // longer indexing lag, and retrying forever is what stranded the tokens.
    //
    // Counted from unresolved_since (the start of the current streak of failed
    // lookups), never from requested_at: while the gateway is unreachable we defer
    // without spending a lookup at all, and a requested_at deadline would burn away
    // during an outage and then park a whole batch on its first clean lookup.
    private const RECONCILE_REVIEW_AFTER_HOURS = 2;

    public function __construct(
        private AsaasClientInterface $asaas,
        private TokenService $tokenService,
        private TokenCreditPolicy $creditPolicy,
    ) {}

    public function minTokens(): int
    {
        return (int) config('monetization.payout.min_tokens');
    }

    public function maxTokens(): int
    {
        return (int) config('monetization.payout.max_tokens');
    }

    /**
     * Saque on-demand: a performer escolhe o valor. Valida KYC + faixa e delega o
     * núcleo (reserva + transferência) ao createAndSendPayout, que também impõe o
     * teto de GANHOS sacáveis (M.13.5): nunca deixa sacar mais do que ganhou, então
     * subscription_grant/purchase/bonus/refund não viram dinheiro (leak).
     */
    public function requestPayout(User $performer, int $tokens, string $pixKey, string $pixKeyType): Payout
    {
        $profile = $performer->performerProfile;

        if (! $profile || ! $profile->is_verified) {
            throw new PayoutNotAllowedException('Complete a verificação de identidade para sacar.');
        }

        if ($tokens < $this->minTokens() || $tokens > $this->maxTokens()) {
            throw new \InvalidArgumentException('Token amount out of allowed range.');
        }

        return $this->createAndSendPayout($performer, $tokens, $pixKey, $pixKeyType);
    }

    /**
     * Valor do saque em CENTAVOS (M.13.5): R$0,60/token = 60 centavos/token FIXO. Este
     * é o ÚNICO ponto de arredondamento da economia inteira (Regra R2): a conversão
     * token→R$ no payout, sempre **FLOOR** (nunca half-up, nunca para cima). bcmath,
     * nunca float. `$tokens` pode ser fracionário (o ganho da performer é decimal).
     */
    public function calculatePayoutCentavos(int|string $tokens): int
    {
        // tokens × 60 em escala 4, depois floor ao centavo inteiro.
        return TokenMath::intFloor(TokenMath::mul($tokens, $this->creditPolicy->payoutCentavosPerToken()));
    }

    /**
     * Decomposição do saque (Regra R1–R3). Converte `$withdrawable` tokens em:
     *  - `centavos`: R$ a pagar, FLOOR ao centavo (R2) — nunca overpaga real.
     *  - `tokens_consumed`: os tokens que esses centavos representam (centavos ÷ 60),
     *    o que se DEBITA da carteira. Truncado em escala 4 → tokens_consumed × 60 ≤
     *    centavos, então a Limen paga no máximo uma sub-fração de centavo A MAIS do que
     *    debita — nunca a menos (o arredondamento nunca favorece a Limen).
     *  - `remainder`: `withdrawable − tokens_consumed`, a SOBRA que CONTINUA no saldo da
     *    performer (R3) — não é debitada, não some, não vira da Limen.
     *
     * Ex. (R3): saldo 4,8733 → 4,8733×60 = 292,398 → floor 292 centavos (R$2,92);
     * tokens_consumed = 292÷60 = 4,8666; remainder 0,0067 fica no saldo.
     *
     * @return array{centavos:int, tokens_consumed:string, remainder:string}
     */
    public function payoutBreakdown(int|string $withdrawable): array
    {
        $centavos = $this->calculatePayoutCentavos($withdrawable);
        $perToken = (string) $this->creditPolicy->payoutCentavosPerToken(); // "60"
        $consumed = bcdiv((string) $centavos, $perToken, TokenMath::SCALE);

        return [
            'centavos' => $centavos,
            'tokens_consumed' => $consumed,
            'remainder' => TokenMath::sub($withdrawable, $consumed),
        ];
    }

    /**
     * Tokens de GANHO ainda devidos à performer (M.13.5, decisão do PO 04/08):
     *   owed = SUM(créditos de ganho) − SUM(reservados) + SUM(estornados)
     * Somando SÓ o allowlist de entry_types de ganho da config (tip_credit,
     * chat_access_credit, …), NUNCA por sinal do amount — senão bonus/refund/
     * purchase/subscription_grant vazariam a R$0,60. `payout_reserve` (negativo) e
     * `payout_reversal` (positivo) se cancelam num saque falho (volta a ser devido)
     * e permanecem como débito permanente num saque PAGO (nunca re-paga). Nunca
     * negativo.
     */
    public function earningsOwed(User $performer): int|string
    {
        $walletId = TokenWallet::where('user_id', $performer->id)->value('id');

        if (! $walletId) {
            return 0;
        }

        // Os ganhos são FRACIONÁRIOS desde a economia de mensagem (chat_access_credit
        // = 1,60, etc.). Somar por bcmath — o antigo `(int) sum` TRUNCAVA a fração e
        // subtraía do que a performer pode sacar (leak contra a performer).
        $earned = TokenMath::of(TokenLedger::where('wallet_id', $walletId)
            ->whereIn('entry_type', config('monetization.payout.earning_entry_types'))
            ->sum('amount'));

        // reserved: amounts de payout_reserve são negativos; a soma é ≤ 0.
        // reversed: payout_reversal são positivos. earned + reserved(≤0) + reversed.
        $reserved = TokenMath::of(TokenLedger::where('wallet_id', $walletId)
            ->where('entry_type', 'payout_reserve')
            ->sum('amount'));

        $reversed = TokenMath::of(TokenLedger::where('wallet_id', $walletId)
            ->where('entry_type', 'payout_reversal')
            ->sum('amount'));

        // int quando inteiro (o caso comum — o saque é inteiro), string decimal
        // quando há "poeira" fracionária de ganho ainda devida. Mesmo contrato de
        // TokenService::balance().
        return TokenMath::readable(TokenMath::max(0, TokenMath::add(TokenMath::add($earned, $reserved), $reversed)));
    }

    /**
     * Núcleo compartilhado por on-demand e sweep: cria a linha do payout, reserva os
     * tokens e dispara a transferência PIX. Impõe o teto de ganhos e mantém INTACTA
     * a disciplina de resultado ambíguo (timeout/5xx NUNCA estorna — poderia pagar
     * em dobro um PIX que saiu).
     *
     * A linha do Payout + o débito da reserva nascem juntos numa transação; o
     * createTransfer fica FORA dela. Assim, sob corrida, o índice único
     * (performer, período) aborta o run perdedor ANTES de qualquer débito ou
     * transferência (o QueryException sobe do create).
     */
    public function createAndSendPayout(
        User $performer,
        int|string $withdrawable,
        string $pixKey,
        string $pixKeyType,
        ?int $periodYear = null,
        ?int $periodMonth = null,
    ): Payout {
        // Teto de ganhos sacáveis (M.13.5): nunca saca mais do que ganhou. Best-
        // effort fora do lock (o débito é o guard duro do saldo); fecha o leak de
        // sacar tokens não-ganhos (subscription_grant/purchase/bonus) a R$0,60.
        if (TokenMath::cmp($withdrawable, $this->earningsOwed($performer)) > 0) {
            throw new InsufficientBalanceException($withdrawable, $this->earningsOwed($performer));
        }

        // R1–R3: o ÚNICO arredondamento da economia é aqui — floor ao centavo. Debita
        // só os tokens que os centavos pagos representam (`tokens_consumed`); a SOBRA
        // (`remainder`) NÃO é debitada e fica no saldo da performer para o próximo saque.
        $breakdown = $this->payoutBreakdown($withdrawable);
        $centavos = $breakdown['centavos'];
        $tokensConsumed = $breakdown['tokens_consumed'];
        $tokensLabel = TokenMath::display($tokensConsumed);
        $amountBrl = sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);

        $payout = DB::transaction(function () use ($performer, $tokensConsumed, $tokensLabel, $pixKey, $pixKeyType, $amountBrl, $periodYear, $periodMonth) {
            $payout = Payout::create([
                'performer_id' => $performer->id,
                'tokens' => $tokensConsumed,
                'amount_brl' => $amountBrl,
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'status' => 'pending',
                'period_year' => $periodYear,
                'period_month' => $periodMonth,
                'requested_at' => now(),
            ]);

            $this->tokenService->debit(
                $performer,
                $tokensConsumed,
                'payout_reserve',
                'payout',
                $payout->id,
                "Saque: {$tokensLabel} tokens",
            );

            return $payout;
        });

        Audit::log('payout.requested', $payout, [
            'tokens' => $tokensLabel,
            'amount_brl' => $amountBrl,
        ]);

        try {
            $transfer = $this->asaas->createTransfer([
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'value' => (float) $amountBrl,
                'description' => "Limen payout #{$payout->id}",
                'external_reference' => "payout_{$payout->id}",
            ]);

            $payout->update([
                'asaas_transfer_id' => $transfer['id'] ?? null,
                'status' => 'processing',
            ]);

            Audit::log('payout.processing', $payout, [
                'asaas_transfer_id' => $payout->asaas_transfer_id,
            ]);
        } catch (AsaasUnavailableException $e) {
            // Ambiguous outcome (timeout / 5xx): Asaas may have created the transfer
            // and be paying the PIX. Reversing here could return tokens for money
            // that actually went out. Leave the payout 'processing' with no transfer
            // id; the webhook (resolves by externalReference) or payouts:reconcile
            // settles it against Asaas.
            Log::error('Payout transfer result unknown; deferring to reconcile', [
                'payout_id' => $payout->id,
                'error_class' => get_class($e),
            ]);

            $payout->update(['status' => 'processing']);
            Audit::log('payout.unconfirmed', $payout);
        } catch (\Throwable $e) {
            // Definitive failure (4xx / invalid request): the transfer was not
            // created, so it is safe to fail and return the reserved tokens.
            Log::error('Payout transfer creation failed', [
                'payout_id' => $payout->id,
                'error_class' => get_class($e),
            ]);

            $this->markFailedAndReverse($payout, 'Falha ao criar transferência com o provedor de pagamento.');
        }

        return $payout->fresh();
    }

    /**
     * Sweep mensal do dia 1 (M.10): paga automaticamente os ganhos devidos do mês
     * que fechou. Idempotente por (performer, ano, mês) — índice único + checagem.
     * Reusa a chave PIX do último saque bem-sucedido; performer sem saque anterior
     * é pulada (faz o primeiro on-demand). Só paga conta ATIVA e verificada.
     *
     * @return array{created:int, tokens:int, skipped_no_key:int, skipped_below_min:int, skipped_ineligible:int, skipped_duplicate:int, failed:int}
     */
    public function sweepMonthlyPayouts(): array
    {
        $target = now()->subMonthNoOverflow();
        $year = (int) $target->year;
        $month = (int) $target->month;

        $stats = ['created' => 0, 'tokens' => 0, 'skipped_no_key' => 0, 'skipped_below_min' => 0, 'skipped_ineligible' => 0, 'skipped_duplicate' => 0, 'failed' => 0];

        // Candidatas: quem tem QUALQUER crédito de ganho no ledger. Consumer não
        // recebe tip_credit/chat_access_credit, então na prática só performers
        // aparecem; a elegibilidade real (ativa + verificada) é reconferida abaixo.
        $performerIds = TokenLedger::query()
            ->join('token_wallets', 'token_wallets.id', '=', 'token_ledger.wallet_id')
            ->whereIn('token_ledger.entry_type', config('monetization.payout.earning_entry_types'))
            ->distinct()
            ->pluck('token_wallets.user_id');

        foreach ($performerIds as $performerId) {
            try {
                $result = $this->sweepOne((int) $performerId, $year, $month);
                if ($result === null) {
                    continue;
                }
                $stats[$result['bucket']]++;
                if ($result['bucket'] === 'created') {
                    $stats['tokens'] += $result['tokens'];
                }
            } catch (UniqueConstraintViolationException) {
                // Run concorrente criou o payout do mês primeiro: idempotência OK.
                // Bucket próprio (não 'ineligible') para não mascarar um agendamento
                // duplicado real na telemetria de dinheiro — SF-1 da revisão.
                $stats['skipped_duplicate']++;
                Log::info('Monthly payout sweep hit unique index (concurrent run)', [
                    'performer_id' => $performerId,
                    'period_year' => $year,
                    'period_month' => $month,
                ]);
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::error('Monthly payout sweep error', [
                    'performer_id' => $performerId,
                    'error_class' => get_class($e),
                ]);
            }
        }

        return $stats;
    }

    /**
     * @return array{bucket:string, tokens:int}|null null quando não é performer.
     */
    private function sweepOne(int $performerId, int $year, int $month): ?array
    {
        $performer = User::find($performerId);

        if (! $performer || $performer->role !== 'performer') {
            return null;
        }

        // Conta ATIVA + KYC. O sweep roda sem sessão, então o corte de banned/
        // suspended que a sessão morta faz no on-demand precisa ser EXPLÍCITO aqui —
        // senão pagaria PIX real a uma conta banida por conteúdo ilegal.
        $profile = $performer->performerProfile;
        if ($performer->status !== 'active' || ! $profile || ! $profile->is_verified) {
            return ['bucket' => 'skipped_ineligible', 'tokens' => 0];
        }

        // Já pago este mês? (índice único é o backstop sob corrida.)
        $already = Payout::where('performer_id', $performerId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->exists();
        if ($already) {
            return ['bucket' => 'skipped_duplicate', 'tokens' => 0];
        }

        // Nunca saca acima do devido, do saldo real, nem do teto. O saque FRACIONA
        // (o floor é só na conversão p/ R$ — R2 —, e a sobra fica no saldo — R3), então
        // NÃO se arredonda o token aqui.
        $payable = TokenMath::min(
            $this->earningsOwed($performer),
            $this->tokenService->balance($performer),
            $this->maxTokens(),
        );

        // Mínimo de saque em TOKENS (M.10): abaixo de 100 não varre.
        if (TokenMath::cmp($payable, $this->minTokens()) < 0) {
            return ['bucket' => 'skipped_below_min', 'tokens' => 0];
        }

        // Chave PIX do último saque BEM-SUCEDIDO (paid/processing) — nunca de um
        // failed/cancelled (chave que pode ter sido recusada). Sem saque anterior,
        // não há chave: pula, a performer faz o primeiro on-demand.
        $lastPayout = Payout::where('performer_id', $performerId)
            ->whereIn('status', ['paid', 'processing'])
            ->whereNotNull('pix_key')
            ->orderByDesc('id')
            ->first();

        if (! $lastPayout) {
            return ['bucket' => 'skipped_no_key', 'tokens' => 0];
        }

        $payout = $this->createAndSendPayout(
            $performer,
            $payable,
            $lastPayout->pix_key,
            $lastPayout->pix_key_type,
            $year,
            $month,
        );

        // Reporta os tokens EFETIVAMENTE consumidos (o que foi pago); a sobra do floor
        // fica no saldo para o próximo ciclo (R3). Contrato readable: int quando inteiro.
        return ['bucket' => 'created', 'tokens' => $payout->tokens];
    }

    public function handleWebhook(array $payload): void
    {
        $eventType = $payload['event'] ?? null;
        $transferId = $payload['transfer']['id'] ?? null;

        if (! $eventType || ! $transferId) {
            return;
        }

        $eventId = $payload['id'] ?? "{$eventType}_{$transferId}";

        if (PaymentEvent::where('provider_event_id', $eventId)->exists()) {
            return;
        }

        $payout = $this->resolvePayoutForTransfer($transferId, $payload);

        try {
            PaymentEvent::create([
                'provider' => 'asaas',
                'provider_event_id' => $eventId,
                'payout_id' => $payout?->id,
                'payload' => $this->redactPayload($payload),
            ]);
        } catch (QueryException) {
            // Another request already recorded this event (unique constraint) — idempotent no-op.
            return;
        }

        if ($payout) {
            // Asaas signals a completed transfer with TRANSFER_DONE (status DONE) —
            // NOT "TRANSFER_PAID". Accept the alias too for safety. A cancelled
            // transfer, like a failed one, must reverse the reservation.
            if (in_array($eventType, ['TRANSFER_DONE', 'TRANSFER_PAID'], true)) {
                $this->markPaid($payout);
            } elseif (in_array($eventType, ['TRANSFER_FAILED', 'TRANSFER_CANCELLED'], true)) {
                $reason = $payload['transfer']['failReason'] ?? ($payload['reason'] ?? 'Transfer failed');
                $this->markFailedAndReverse($payout, $reason);
            }
        } else {
            Log::warning('Transfer webhook for unknown transfer', [
                'transfer_id' => $transferId,
                'event' => $eventId,
            ]);
        }

        PaymentEvent::where('provider_event_id', $eventId)->update(['processed_at' => now()]);
    }

    /**
     * Settle payouts left in flight — the safety net for a lost webhook or an
     * ambiguous createTransfer (where we intentionally did NOT reverse). Resolves
     * each against Asaas so tokens are never stranded and money is never double-paid.
     */
    public function reconcile(): void
    {
        $inFlight = Payout::whereIn('status', ['pending', 'processing'])
            ->where('requested_at', '<=', now()->subMinutes(self::RECONCILE_MIN_AGE_MINUTES))
            ->get();

        foreach ($inFlight as $payout) {
            try {
                $this->reconcileOne($payout);
            } catch (AsaasUnavailableException) {
                // Still can't reach Asaas — leave it exactly as is and retry next run.
                Log::warning('Payout reconcile deferred (gateway unavailable)', ['payout_id' => $payout->id]);
            } catch (\Throwable $e) {
                Log::error('Payout reconcile error', [
                    'payout_id' => $payout->id,
                    'error_class' => get_class($e),
                ]);
            }
        }
    }

    private function reconcileOne(Payout $payout): void
    {
        $transfer = $this->locateTransfer($payout);

        if ($transfer === null) {
            // We could NOT positively confirm a transfer. This is not proof one
            // doesn't exist — Asaas search is not read-after-write, and a 4xx like
            // 429/401 on the lookup is an operational hiccup, not "gone". Reversing
            // here could return tokens for a PIX that already went out (double pay),
            // so never auto-reverse.
            //
            // Retrying is right while the result may still be indexing lag, but past
            // RECONCILE_REVIEW_AFTER_HOURS it is not lag anymore and the retry is
            // pure cost: the payout is re-queried every run forever, the performer's
            // tokens sit reserved with only a log line as the signal, and the batch
            // grows monotonically (more requests → more rate limiting → more of
            // these). Escalate to 'needs_review': a terminal state for automation
            // only, which moves no money and still lets a webhook settle it.
            $unresolvedSince = $payout->unresolved_since;

            if ($unresolvedSince === null) {
                $payout->update(['unresolved_since' => now()]);
                $unresolvedSince = $payout->unresolved_since;
            }

            if ($unresolvedSince->gt(now()->subHours(self::RECONCILE_REVIEW_AFTER_HOURS))) {
                Log::warning('Payout unresolved by reconcile — will retry', ['payout_id' => $payout->id]);
                Audit::log('payout.reconcile_unresolved', $payout);

                return;
            }

            $this->markNeedsReview($payout);

            return;
        }

        // Located: any earlier streak is over, so the next one starts from scratch.
        if ($payout->unresolved_since !== null) {
            $payout->update(['unresolved_since' => null]);
        }

        if (! $payout->asaas_transfer_id && ! empty($transfer['id'])) {
            $payout->update(['asaas_transfer_id' => $transfer['id']]);
        }

        $status = $transfer['status'] ?? '';

        // Only an EXPLICIT terminal status from Asaas moves money: DONE credits the
        // performer's payout as paid; FAILED/CANCELLED returns the reserved tokens.
        if ($status === 'DONE') {
            $this->markPaid($payout);
        } elseif (in_array($status, ['FAILED', 'CANCELLED'], true)) {
            $this->markFailedAndReverse($payout, $transfer['failReason'] ?? 'Transferência falhou no provedor.');
        }
        // PENDING / BANK_PROCESSING: still moving — leave for a later run.
    }

    private function locateTransfer(Payout $payout): ?array
    {
        if ($payout->asaas_transfer_id) {
            // A recorded id means the transfer WAS created. Never swallow a lookup
            // failure into "not found" here — let it propagate so reconcile() defers
            // (a 404/429/401 must not turn into a reversal of a possibly-paid PIX).
            return $this->asaas->getTransfer($payout->asaas_transfer_id);
        }

        // Ambiguous payout: we never recorded an id. Find it by the external
        // reference we sent. Filter client-side so an unfiltered list response
        // can never make us act on someone else's transfer.
        $result = $this->asaas->findTransfersByExternalReference("payout_{$payout->id}");

        $matches = array_values(array_filter(
            $result['data'] ?? [],
            fn ($transfer) => ($transfer['externalReference'] ?? null) === "payout_{$payout->id}",
        ));

        return $matches[0] ?? null;
    }

    private function resolvePayoutForTransfer(string $transferId, array $payload): ?Payout
    {
        $payout = Payout::where('asaas_transfer_id', $transferId)->first();

        if ($payout) {
            return $payout;
        }

        // The webhook can race ahead of our own asaas_transfer_id update; fall back to the
        // external reference we sent when creating the transfer so the event isn't stranded.
        $externalReference = $payload['transfer']['externalReference'] ?? null;

        if ($externalReference && str_starts_with($externalReference, 'payout_')) {
            $payoutId = (int) substr($externalReference, strlen('payout_'));
            $payout = Payout::find($payoutId);

            if ($payout && ! $payout->asaas_transfer_id) {
                $payout->update(['asaas_transfer_id' => $transferId]);
            }
        }

        return $payout;
    }

    private function redactPayload(array $payload): array
    {
        if (isset($payload['transfer']['pixAddressKey'])) {
            $payload['transfer']['pixAddressKey'] = '[redacted]';
        }

        return $payload;
    }

    /**
     * Park a payout the reconcile cannot resolve. This moves NO money: the tokens
     * stay reserved exactly as they were, because we still cannot tell "transfer
     * never created" from "created and paying". All it does is stop the endless
     * re-querying and give the row a status that is queryable instead of buried in
     * a log line. It alerts the admin by email (PayoutNeedsReviewMail) and the row
     * can be reprocessed via the admin requeue endpoint, which flips it back to
     * 'processing' so the next reconcile run picks it up again.
     */
    private function markNeedsReview(Payout $payout): void
    {
        DB::transaction(function () use ($payout) {
            $locked = Payout::where('id', $payout->id)->lockForUpdate()->first();

            // A webhook may have settled it between the lookup and this write.
            if (! in_array($locked->status, ['processing', 'pending'], true)) {
                return;
            }

            // Same window, quieter: a non-terminal webhook (TRANSFER_CREATED) resolves
            // by externalReference and writes the id without touching the status. Only
            // a payout we could never pin an id to belongs here — with an id, the next
            // run resolves it with getTransfer, so parking would strand it instead.
            if ($locked->asaas_transfer_id !== null) {
                return;
            }

            // Drop the streak on the way out: an operator who requeues this payout to
            // 'processing' must get a full retry budget, not re-park on the next run.
            $locked->update(['status' => 'needs_review', 'unresolved_since' => null]);

            Log::warning('Payout parked for manual review', ['payout_id' => $locked->id]);
            Audit::log('payout.needs_review', $locked);

            // Alerta o admin fora de banda: sem isto, o único sinal é o audit log.
            // queue (não send) para não bloquear a transação na entrega do email, e
            // afterCommit para o job só existir se o parking realmente persistir —
            // enfileirar aqui dentro deixaria um alerta órfão se a transação abortasse.
            if ($adminAddress = config('mail.admin_address')) {
                DB::afterCommit(function () use ($locked, $adminAddress) {
                    Mail::to($adminAddress)->queue(new PayoutNeedsReviewMail($locked));
                });
            }
        });
    }

    private function markPaid(Payout $payout): void
    {
        DB::transaction(function () use ($payout) {
            $locked = Payout::where('id', $payout->id)->lockForUpdate()->first();

            // Accept 'pending' too: a TRANSFER_PAID webhook can race ahead of our
            // own update to 'processing' (or the process may die right after
            // createTransfer). A paid transfer must not get stranded as unpaid.
            // 'needs_review' is accepted for the same reason: parking a payout only
            // ends the reconcile's retries, it must never block a real settlement.
            if (! in_array($locked->status, ['processing', 'pending', 'needs_review'], true)) {
                return;
            }

            $locked->update([
                'status' => 'paid',
                'processed_at' => now(),
            ]);

            Audit::log('payout.paid', $locked);
        });
    }

    private function markFailedAndReverse(Payout $payout, ?string $reason): void
    {
        DB::transaction(function () use ($payout, $reason) {
            $locked = Payout::where('id', $payout->id)->lockForUpdate()->first();

            if (in_array($locked->status, ['paid', 'failed', 'cancelled'], true)) {
                return;
            }

            $alreadyReversed = TokenLedger::where('reference_type', 'payout')
                ->where('reference_id', $locked->id)
                ->where('entry_type', 'payout_reversal')
                ->exists();

            if (! $alreadyReversed) {
                $this->tokenService->credit(
                    $locked->performer,
                    $locked->tokens,
                    'payout_reversal',
                    'payout',
                    $locked->id,
                    "Estorno do saque #{$locked->id}",
                );
            }

            $locked->update([
                'status' => 'failed',
                'failure_reason' => $reason,
            ]);

            Audit::log('payout.failed', $locked, ['reason' => $reason]);
        });
    }
}
