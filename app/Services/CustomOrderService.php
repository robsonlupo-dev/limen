<?php

namespace App\Services;

use App\Events\CustomOrderChanged;
use App\Exceptions\CustomOrderException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\ContentUnlock;
use App\Models\CustomOrder;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\Audit;
use App\Support\FanAlias;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Encomenda de conteúdo sob medida com ESCROW (Onda 4, §4.3). Dona ÚNICA do ciclo de
 * vida + contabilidade do escrow. Espelha o depósito da chamada agendada
 * (CallReservationService):
 *
 *  - HOLD: o débito do membro (`spend_custom_order`) ocorre no ACEITE; os tokens saem da
 *    carteira e a LINHA da encomenda é o registro do escrow.
 *  - SETTLE (uma vez, guardado por `escrow_settled` sob lock da linha):
 *      release → `custom_order_credit` 80/20 à performer;
 *      refund  → `custom_order_refund` 100% ao membro.
 *
 * Cada transição relê a linha com lockForUpdate e re-checa o estado sob o lock. Os
 * broadcasts (CustomOrderChanged) saem PÓS-COMMIT. Autorização de dono vive AQUI (404
 * uniforme), nunca no route-binding.
 */
class CustomOrderService
{
    public function __construct(
        private TokenService $tokenService,
        private TokenCreditPolicy $creditPolicy,
        private PerformerContentService $content,
    ) {}

    // ── Pedido (membro) ───────────────────────────────────────────────────────────

    /**
     * O membro cria a encomenda (descrição + valor oferecido). Sem token aqui — o
     * débito só acontece no aceite da performer.
     */
    public function request(User $member, PerformerProfile $profile, string $description, int $price): CustomOrder
    {
        if (! $this->creditPolicy->isValidCustomOrderPrice($price)) {
            throw CustomOrderException::invalid('Valor inválido para a encomenda.');
        }

        if (! $this->performerIsReachable($profile)) {
            throw CustomOrderException::invalid('Esta performer não está disponível para encomendas.');
        }

        // Tetos anti-flood (ativos por membro e por par).
        $memberActive = CustomOrder::where('member_id', $member->id)->active()->count();
        if ($memberActive >= (int) config('custom_order.max_active_per_member')) {
            throw CustomOrderException::limit();
        }
        $pairActive = CustomOrder::where('member_id', $member->id)
            ->where('performer_profile_id', $profile->id)
            ->active()->count();
        if ($pairActive >= (int) config('custom_order.max_active_per_pair')) {
            throw CustomOrderException::limit();
        }

        $order = new CustomOrder([
            'member_id' => $member->id,
            'performer_profile_id' => $profile->id,
            'description' => trim($description),
            'offered_price_tokens' => $price,
        ]);
        $order->forceFill(['status' => CustomOrder::STATUS_REQUESTED])->save();

        $this->notify($profile->user_id, $order->id, CustomOrder::STATUS_REQUESTED);

        return $order;
    }

    /** O membro cancela um pedido que a performer ainda NÃO aceitou. Sem token. */
    public function cancel(User $member, CustomOrder $order): void
    {
        DB::transaction(function () use ($member, $order) {
            $locked = $this->lockOwnedByMember($order->id, $member);
            if (! $locked->isRequested()) {
                throw CustomOrderException::gone();
            }
            $locked->forceFill(['status' => CustomOrder::STATUS_CANCELLED, 'resolved_at' => now()])->save();
        });

        $this->notify($order->performerProfile->user_id, $order->id, CustomOrder::STATUS_CANCELLED);
    }

    // ── Aceite / recusa (performer) ───────────────────────────────────────────────

    /**
     * A performer ACEITA: debita o membro (escrow). Sob lock: relê dono/estado/janela; o
     * débito re-checa o saldo sob o lock do wallet (saldo < 0 é impossível) — a race de
     * saldo reverte tudo (o aceite sai junto).
     */
    public function accept(User $performerUser, CustomOrder $order): void
    {
        DB::transaction(function () use ($performerUser, $order) {
            $locked = $this->lockOwnedByPerformer($order->id, $performerUser);
            if (! $locked->isRequested()) {
                throw CustomOrderException::gone();
            }
            if ($this->acceptWindowPassed($locked)) {
                throw CustomOrderException::gone(); // o cron vai expirar
            }

            $member = $locked->member;
            if ($member === null) {
                throw CustomOrderException::gone();
            }

            try {
                $spend = $this->tokenService->debit(
                    $member,
                    (int) $locked->offered_price_tokens,
                    'spend_custom_order',
                    CustomOrder::class,
                    $locked->id,
                    'Encomenda sob medida (escrow)',
                );
            } catch (InsufficientBalanceException) {
                throw CustomOrderException::insufficientBalance();
            }

            $locked->forceFill([
                'status' => CustomOrder::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'spend_ledger_id' => $spend->id,
            ])->save();
        });

        $this->notify($order->member_id, $order->id, CustomOrder::STATUS_ACCEPTED);
    }

    /** A performer RECUSA um pedido ainda não aceito. Sem token (o débito é no aceite). */
    public function decline(User $performerUser, CustomOrder $order): void
    {
        DB::transaction(function () use ($performerUser, $order) {
            $locked = $this->lockOwnedByPerformer($order->id, $performerUser);
            if (! $locked->isRequested()) {
                throw CustomOrderException::gone();
            }
            $locked->forceFill(['status' => CustomOrder::STATUS_DECLINED, 'resolved_at' => now()])->save();
        });

        $this->notify($order->member_id, $order->id, CustomOrder::STATUS_DECLINED);
    }

    // ── Entrega (performer) ───────────────────────────────────────────────────────

    /**
     * A performer ENTREGA: publica a peça (privada da encomenda; moderação roda) e a
     * vincula. Cria o content_unlock do MEMBRO já aqui (ele precisa VER para aprovar/
     * contestar). Abre a janela de contestação. A publicação da mídia roda ANTES da
     * transação (grava bytes/dispatcha job, como a voz do chat); se a encomenda não
     * estiver mais em `accepted` (race), a peça recém-criada é removida e recusa.
     */
    public function deliver(User $performerUser, CustomOrder $order, UploadedFile $file): CustomOrder
    {
        // Checagem barata antes de gastar o upload caro (relê sem lock só para decidir).
        $pre = CustomOrder::find($order->id);
        if ($pre === null || (int) $pre->performerProfile?->user_id !== (int) $performerUser->id) {
            throw CustomOrderException::notFound();
        }
        if (! $pre->isAccepted()) {
            throw CustomOrderException::gone();
        }

        // Marca d'água (§4.3): FanAlias do par + data — nunca dado real do membro. A foto
        // é marcada no store (aqui); o vídeo é marcado no job (que reobtém o texto pelo
        // custom_order_id). Texto calculado sempre; só é aplicado se a marca estiver ligada.
        $watermarkText = WatermarkService::labelFor(FanAlias::label($pre->performer_profile_id, (int) $pre->member_id));
        $piece = $this->content->deliverCustomPiece($pre->performerProfile, $file, $pre->id, $watermarkText);

        try {
            DB::transaction(function () use ($performerUser, $order, $piece) {
                $locked = $this->lockOwnedByPerformer($order->id, $performerUser);
                if (! $locked->isAccepted()) {
                    throw CustomOrderException::gone();
                }

                $window = (int) config('custom_order.dispute_window_hours');
                $locked->forceFill([
                    'status' => CustomOrder::STATUS_DELIVERED,
                    'performer_content_id' => $piece->id,
                    'delivered_at' => now(),
                    'dispute_deadline_at' => now()->addHours($window),
                ])->save();

                // Acesso do membro à peça (ele precisa ver para decidir). UNIQUE(peça,
                // membro) — a peça é nova, sem conflito. tokens_paid = o valor do pedido.
                $unlock = new ContentUnlock;
                $unlock->performer_content_id = $piece->id;
                $unlock->user_id = $locked->member_id;
                $unlock->tokens_paid = (int) $locked->offered_price_tokens;
                $unlock->unlocked_at = now();
                $unlock->save();

                Audit::log('custom_order.delivered', $locked, ['content_id' => $piece->id]);
            });
        } catch (\Throwable $e) {
            // Race perdida (ou falha): não deixa a peça órfã servível ao membro.
            try {
                $this->content->remove($order->performerProfile, $piece);
            } catch (\Throwable) {
            }

            throw $e;
        }

        $this->notify($order->member_id, $order->id, CustomOrder::STATUS_DELIVERED);

        return $order->fresh();
    }

    // ── Aprovação / contestação (membro) ──────────────────────────────────────────

    /** O membro APROVA a entrega → libera o escrow 80/20 na hora. */
    public function approve(User $member, CustomOrder $order): void
    {
        DB::transaction(function () use ($member, $order) {
            $locked = $this->lockOwnedByMember($order->id, $member);
            if (! $locked->isDelivered()) {
                throw CustomOrderException::gone();
            }
            // Não deixa liberar o escrow por conteúdo que o membro não consegue ver: um
            // vídeo ainda em processamento (ou que falhou) tem `can_approve` fechado na
            // UI, mas o servidor é a fonte da verdade. Se a mídia não ficou pronta, o
            // membro espera — e, no fim da janela, o cron ESTORNA (autoReleaseDelivered)
            // em vez de premiar uma entrega que nunca pôde ser vista.
            if (! $locked->deliveredIsReady()) {
                throw CustomOrderException::invalid('A mídia ainda não está pronta para visualização.');
            }
            $this->settleRelease($locked);
        });

        $this->notify($order->performerProfile->user_id, $order->id, CustomOrder::STATUS_RELEASED);
    }

    /** O membro CONTESTA a entrega dentro da janela → segura o escrow para a moderação. */
    public function dispute(User $member, CustomOrder $order): void
    {
        DB::transaction(function () use ($member, $order) {
            $locked = $this->lockOwnedByMember($order->id, $member);
            if (! $locked->isDelivered()) {
                throw CustomOrderException::gone();
            }
            if ($locked->dispute_deadline_at !== null && now()->greaterThan($locked->dispute_deadline_at)) {
                throw CustomOrderException::gone(); // janela vencida → o cron libera
            }
            $locked->forceFill(['status' => CustomOrder::STATUS_DISPUTED])->save();
            Audit::log('custom_order.disputed', $locked, ['content_id' => $locked->performer_content_id]);
        });

        $this->notify($order->performerProfile->user_id, $order->id, CustomOrder::STATUS_DISPUTED);
    }

    // ── Resolução de disputa (moderação/admin) ────────────────────────────────────

    /**
     * Admin resolve uma encomenda CONTESTADA: 'release' (a favor da performer) libera
     * 80/20; 'refund' (a favor do membro) estorna 100% e revoga o acesso à peça.
     */
    public function resolveDispute(CustomOrder $order, string $decision): void
    {
        DB::transaction(function () use ($order, $decision) {
            $locked = CustomOrder::whereKey($order->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->isDisputed()) {
                throw CustomOrderException::gone();
            }
            if ($decision === 'release') {
                $this->settleRelease($locked);
            } else {
                $this->settleRefund($locked, CustomOrder::STATUS_REFUNDED);
            }
        });

        $outcome = $decision === 'release' ? CustomOrder::STATUS_RELEASED : CustomOrder::STATUS_REFUNDED;
        $this->notify($order->member_id, $order->id, $outcome);
        $this->notify($order->performerProfile->user_id, $order->id, $outcome);
    }

    // ── Varredura por tempo (cron; idempotente por linha) ─────────────────────────

    /** Pedidos não aceitos além da janela → expiram (sem token). */
    public function expireStaleRequests(): int
    {
        $deadline = now()->subHours((int) config('custom_order.accept_window_hours'));
        $ids = CustomOrder::where('status', CustomOrder::STATUS_REQUESTED)
            ->where('created_at', '<', $deadline)->pluck('id');

        $n = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$n) {
                $locked = CustomOrder::whereKey($id)->lockForUpdate()->first();
                if ($locked && $locked->isRequested()) {
                    $locked->forceFill(['status' => CustomOrder::STATUS_EXPIRED, 'resolved_at' => now()])->save();
                    $n++;
                }
            });
            $this->notify($this->memberIdOf($id), $id, CustomOrder::STATUS_EXPIRED);
        }

        return $n;
    }

    /** Aceitos e não entregues além da janela → estorno 100% ao membro. */
    public function refundUndelivered(): int
    {
        $deadline = now()->subHours((int) config('custom_order.deliver_window_hours'));
        $ids = CustomOrder::where('status', CustomOrder::STATUS_ACCEPTED)
            ->where('accepted_at', '<', $deadline)->pluck('id');

        return $this->settleEach($ids, function (CustomOrder $locked) {
            if ($locked->isAccepted()) {
                $this->settleRefund($locked, CustomOrder::STATUS_REFUNDED);

                return $locked->member_id;
            }

            return null;
        }, CustomOrder::STATUS_REFUNDED);
    }

    /** Entregues além da janela de contestação → libera (ou estorna se a mídia falhou). */
    public function autoReleaseDelivered(): int
    {
        $ids = CustomOrder::where('status', CustomOrder::STATUS_DELIVERED)
            ->whereNotNull('dispute_deadline_at')
            ->where('dispute_deadline_at', '<', now())->pluck('id');

        $n = 0;
        foreach ($ids as $id) {
            $outcome = CustomOrder::STATUS_RELEASED;
            $recipient = null;
            DB::transaction(function () use ($id, &$outcome, &$recipient) {
                $locked = CustomOrder::whereKey($id)->lockForUpdate()->first();
                if ($locked === null || ! $locked->isDelivered()) {
                    return;
                }
                $piece = $locked->deliveredContent;
                if ($piece !== null && $piece->isReady()) {
                    $this->settleRelease($locked);
                    $outcome = CustomOrder::STATUS_RELEASED;
                    $recipient = $locked->performerProfile?->user_id;
                } else {
                    // Vídeo não ficou pronto (falhou/travou) → o membro não pôde ver:
                    // estorna em vez de liberar.
                    $this->settleRefund($locked, CustomOrder::STATUS_REFUNDED);
                    $outcome = CustomOrder::STATUS_REFUNDED;
                    $recipient = $locked->member_id;
                }
            });
            if ($recipient !== null) {
                $this->notify($recipient, $id, $outcome);
                $n++;
            }
        }

        return $n;
    }

    // ── Liquidação do escrow (idempotente por escrow_settled; PRESSUPÕE lock) ──────

    private function settleRelease(CustomOrder $locked): void
    {
        if ($locked->escrow_settled) {
            return;
        }

        $performerUser = $locked->performerProfile?->user;

        // Sem performer para creditar (conta sumiu entre o aceite e a liberação): NÃO
        // marca settled sem mover nada — isso deixaria os tokens do membro presos na
        // plataforma. Estorna ao membro, que é o destino justo quando o crédito é
        // impossível. (settleRefund também guarda escrow_settled, então continua one-time.)
        if ($performerUser === null) {
            $this->settleRefund($locked, CustomOrder::STATUS_REFUNDED);

            return;
        }

        $credit = $this->creditPolicy->creditWithSplit(
            $performerUser,
            (int) $locked->offered_price_tokens,
            'content',
            'custom_order_credit',
            CustomOrder::class,
            $locked->id,
            'Encomenda entregue para '.FanAlias::label($locked->performer_profile_id, $locked->member_id),
        );

        $locked->forceFill([
            'escrow_settled' => true,
            'status' => CustomOrder::STATUS_RELEASED,
            'resolved_at' => now(),
            'settle_ledger_id' => $credit->id,
        ])->save();
    }

    private function settleRefund(CustomOrder $locked, string $newStatus): void
    {
        if ($locked->escrow_settled) {
            return;
        }

        $member = $locked->member;
        $credit = null;
        if ($member !== null) {
            $credit = $this->creditPolicy->credit(
                $member,
                (int) $locked->offered_price_tokens,
                'custom_order_refund',
                CustomOrder::class,
                $locked->id,
                'Estorno de encomenda sob medida',
            );
        }

        // Revoga o acesso do membro à peça entregue (ele foi reembolsado; não fica com o
        // conteúdo). O content_unlock some; os bytes seguem sob a peça (moderação/prova).
        if ($locked->performer_content_id !== null && $member !== null) {
            ContentUnlock::where('performer_content_id', $locked->performer_content_id)
                ->where('user_id', $member->id)
                ->delete();
        }

        $locked->forceFill([
            'escrow_settled' => true,
            'status' => $newStatus,
            'resolved_at' => now(),
            'settle_ledger_id' => $credit?->id,
        ])->save();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────

    private function lockOwnedByMember(int $id, User $member): CustomOrder
    {
        $locked = CustomOrder::whereKey($id)->lockForUpdate()->first();
        if ($locked === null || (int) $locked->member_id !== (int) $member->id) {
            throw CustomOrderException::notFound();
        }

        return $locked;
    }

    private function lockOwnedByPerformer(int $id, User $performerUser): CustomOrder
    {
        $locked = CustomOrder::whereKey($id)->lockForUpdate()->first();
        if ($locked === null || (int) $locked->performerProfile?->user_id !== (int) $performerUser->id) {
            throw CustomOrderException::notFound();
        }

        return $locked;
    }

    private function acceptWindowPassed(CustomOrder $order): bool
    {
        if ($order->created_at === null) {
            return false;
        }

        return now()->greaterThan($order->created_at->copy()->addHours((int) config('custom_order.accept_window_hours')));
    }

    private function performerIsReachable(?PerformerProfile $profile): bool
    {
        if ($profile === null || $profile->trashed()) {
            return false;
        }
        $user = $profile->user()->withTrashed()->first();

        return $user !== null && ! $user->trashed() && $user->status === 'active';
    }

    private function memberIdOf(int $orderId): int
    {
        return (int) CustomOrder::whereKey($orderId)->value('member_id');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $ids
     * @param  callable(CustomOrder): ?int  $settle
     */
    private function settleEach($ids, callable $settle, string $outcome): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $recipient = null;
            DB::transaction(function () use ($id, $settle, &$recipient) {
                $locked = CustomOrder::whereKey($id)->lockForUpdate()->first();
                if ($locked !== null) {
                    $recipient = $settle($locked);
                }
            });
            if ($recipient !== null) {
                $this->notify($recipient, $id, $outcome);
                $n++;
            }
        }

        return $n;
    }

    private function notify(?int $recipientUserId, int $orderId, string $outcome): void
    {
        if ($recipientUserId !== null) {
            CustomOrderChanged::dispatch($recipientUserId, $orderId, $outcome);
        }
    }
}
