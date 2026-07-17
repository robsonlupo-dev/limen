<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ChatAccess;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Acesso pago ao chat (membro sem assinatura). Ver config/chat.php e
 * docs/COMMUNICATION_ECONOMY.md §2.
 *
 * - Assinante de qualquer Círculo ativo tem chat livre e permanente — não passa
 *   por aqui (accessState devolve 'subscriber').
 * - Membro sem assinatura paga access_cost tokens por performer por janela de
 *   access_days; depois há grace_days de carência (leitura bloqueada, sem
 *   envio); passada a carência o job soft-deleta as mensagens.
 * - Débito via token_ledger append-only; a performer é creditada pelo split_pct
 *   (como a gorjeta).
 */
class ChatAccessService
{
    public function __construct(private TokenService $tokenService) {}

    private function accessCost(): int
    {
        return (int) config('chat.access_cost');
    }

    private function accessDays(): int
    {
        return (int) config('chat.access_days');
    }

    private function graceDays(): int
    {
        return (int) config('chat.grace_days');
    }

    /**
     * Compra ou renova o acesso do membro à conversa (debita access_cost, credita
     * o split à performer, cria/estende a janela). Idempotente por
     * idempotency_key: um double-submit com a mesma chave não cobra de novo.
     *
     * Estende a partir do fim da janela vigente se ainda ativa (renovação
     * antecipada empilha), senão a partir de agora.
     *
     * @throws \InvalidArgumentException assinante (não deveria pagar) ou performer
     * @throws \App\Exceptions\InsufficientBalanceException saldo insuficiente
     */
    public function openOrRenew(Conversation $conversation, User $member, string $idempotencyKey): ChatAccess
    {
        $conversation->loadMissing('performerProfile.user');
        $performerProfile = $conversation->performerProfile;

        if ($member->id !== $conversation->member_id) {
            throw new \InvalidArgumentException('Only the conversation member can buy chat access.');
        }

        // Assinante tem chat livre — nunca compra acesso avulso.
        if ($member->activeSubscription() !== null) {
            throw new \InvalidArgumentException('Active subscribers already have free chat.');
        }

        return DB::transaction(function () use ($conversation, $member, $performerProfile, $idempotencyKey) {
            // Serializa opens/renovações concorrentes do mesmo par.
            $access = ChatAccess::where('member_id', $member->id)
                ->where('performer_profile_id', $performerProfile->id)
                ->lockForUpdate()
                ->first();

            // Replay: mesma chave do último open/renew → não cobra de novo.
            if ($access && $access->last_idempotency_key === $idempotencyKey) {
                return $access;
            }

            $performerUser = $performerProfile->user;
            $cost = $this->accessCost();

            $spendEntry = $this->tokenService->debit(
                $member,
                $cost,
                'spend_chat_access',
                ChatAccess::class,
                $access?->id,
                "Acesso ao chat de {$performerProfile->stage_name}",
            );

            // Split como a gorjeta; só credita se sobrar ao menos 1 token.
            $performerAmount = (int) floor($cost * $performerProfile->split_pct / 100);
            $creditEntry = $performerAmount > 0
                ? $this->tokenService->credit(
                    $performerUser,
                    $performerAmount,
                    'chat_access_credit',
                    ChatAccess::class,
                    $access?->id,
                    'Acesso ao chat recebido',
                )
                : null;

            // Base da nova janela: empilha sobre a atual se ainda ativa, senão agora.
            $now = now();
            $base = ($access && $now->lessThan($access->expires_at)) ? $access->expires_at : $now;
            $expiresAt = $base->copy()->addDays($this->accessDays());
            $graceEndsAt = $expiresAt->copy()->addDays($this->graceDays());

            if ($access) {
                $access->forceFill([
                    'expires_at' => $expiresAt,
                    'grace_ends_at' => $graceEndsAt,
                    'renewed_at' => $now,
                    'status' => 'active',
                    'spend_ledger_id' => $spendEntry->id,
                    'credit_ledger_id' => $creditEntry?->id,
                    'last_idempotency_key' => $idempotencyKey,
                ])->save();
            } else {
                $access = ChatAccess::create([
                    'member_id' => $member->id,
                    'performer_profile_id' => $performerProfile->id,
                    'unlocked_at' => $now,
                    'expires_at' => $expiresAt,
                    'grace_ends_at' => $graceEndsAt,
                    'status' => 'active',
                    'last_idempotency_key' => $idempotencyKey,
                ]);
                $access->forceFill([
                    'spend_ledger_id' => $spendEntry->id,
                    'credit_ledger_id' => $creditEntry?->id,
                ])->save();
            }

            AuditLog::create([
                'user_id' => $member->id,
                'action' => 'chat.access_purchased',
                'subject_type' => ChatAccess::class,
                'subject_id' => $access->id,
                'ip' => request()->ip(),
                'metadata' => [
                    'performer_profile_id' => $performerProfile->id,
                    'cost' => $cost,
                    'renewal' => $access->wasChanged() && $access->renewed_at !== null,
                ],
            ]);

            return $access;
        });
    }

    /**
     * Linha de acesso do par, ou null. Sem efeitos colaterais.
     */
    public function accessFor(Conversation $conversation, User $member): ?ChatAccess
    {
        if ($member->id !== $conversation->member_id) {
            return null;
        }

        return ChatAccess::where('member_id', $member->id)
            ->where('performer_profile_id', $conversation->performer_profile_id)
            ->first();
    }

    /**
     * Estado do acesso do MEMBRO, para gating de envio/leitura e para a UI.
     * A performer não passa por aqui (ela sempre acessa a própria conversa).
     *
     * @return array{state:string,can_send:bool,can_read:bool,locked:bool,days_remaining:?int,expires_at:?string}
     */
    public function accessState(Conversation $conversation, User $member): array
    {
        // Assinante: chat livre e permanente.
        if ($member->activeSubscription() !== null) {
            return [
                'state' => 'subscriber',
                'can_send' => true,
                'can_read' => true,
                'locked' => false,
                'days_remaining' => null,
                'expires_at' => null,
            ];
        }

        $access = $this->accessFor($conversation, $member);

        if ($access && $access->hasFullAccess()) {
            return [
                'state' => 'active',
                'can_send' => true,
                'can_read' => true,
                'locked' => false,
                'days_remaining' => (int) ceil(now()->diffInDays($access->expires_at, absolute: true)),
                'expires_at' => $access->expires_at->toIso8601String(),
            ];
        }

        if ($access && $access->isInGrace()) {
            // Carência: histórico visível porém bloqueado (blur), sem envio.
            return [
                'state' => 'grace',
                'can_send' => false,
                'can_read' => true,
                'locked' => true,
                'days_remaining' => 0,
                'expires_at' => $access->expires_at->toIso8601String(),
            ];
        }

        // Nunca comprou, ou já passou a carência (mensagens soft-deletadas).
        return [
            'state' => $access ? 'expired' : 'none',
            'can_send' => false,
            'can_read' => false,
            'locked' => true,
            'days_remaining' => 0,
            'expires_at' => null,
        ];
    }

    /**
     * Job diário. Duas transições, sempre append-only/soft:
     *  1. active com expires_at vencido → status 'expired' (entra na carência).
     *  2. grace_ends_at vencido (e não 'deleted') → soft-delete das mensagens da
     *     conversa do par + status 'deleted'. As linhas ficam retidas no
     *     servidor (soft delete), nunca hard-delete.
     *
     * @return array{expired:int,purged:int,messages_deleted:int}
     */
    public function purgeExpired(): array
    {
        $expired = 0;
        $purged = 0;
        $messagesDeleted = 0;

        // 1) Marca vencidos que ainda constam 'active'.
        $expired = ChatAccess::where('status', 'active')
            ->where('expires_at', '<', now())
            ->where('grace_ends_at', '>=', now())
            ->update(['status' => 'expired']);

        // 2) Passada a carência: soft-delete das mensagens + status 'deleted'.
        ChatAccess::whereIn('status', ['active', 'expired'])
            ->where('grace_ends_at', '<', now())
            ->orderBy('id')
            ->each(function (ChatAccess $access) use (&$purged, &$messagesDeleted) {
                DB::transaction(function () use ($access, &$purged, &$messagesDeleted) {
                    $conversation = Conversation::where('member_id', $access->member_id)
                        ->where('performer_profile_id', $access->performer_profile_id)
                        ->first();

                    if ($conversation) {
                        $messagesDeleted += Message::where('conversation_id', $conversation->id)->delete();
                    }

                    $access->update(['status' => 'deleted']);

                    AuditLog::create([
                        'user_id' => $access->member_id,
                        'action' => 'chat.access_purged',
                        'subject_type' => ChatAccess::class,
                        'subject_id' => $access->id,
                        'ip' => null,
                        'metadata' => [
                            'performer_profile_id' => $access->performer_profile_id,
                            'soft_deleted' => true,
                        ],
                    ]);

                    $purged++;
                });
            });

        return ['expired' => $expired, 'purged' => $purged, 'messages_deleted' => $messagesDeleted];
    }
}
