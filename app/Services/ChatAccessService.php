<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
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
     * @throws InsufficientBalanceException saldo insuficiente
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

        return DB::transaction(function () use ($member, $performerProfile, $idempotencyKey) {
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
     * O membro pode ENVIAR para esta performer agora? Fonte única.
     *
     * ── Por que existe ──────────────────────────────────────────────────────
     * A pergunta tinha duas implementações: `ChatService::sendMessage()` e
     * `MemberPhotoService::shareWith()`, esta última uma CÓPIA declarada da
     * primeira. Foi assim que o `status === 'active'` passou batido na primeira
     * versão da foto efêmera — a cópia nasceu com uma porta a menos. Era o 4º
     * bloqueador de go-live da foto.
     *
     * ── As duas portas, e por que são duas ──────────────────────────────────
     *  1. `conversation->status === 'active'`. Nada seta `archived` hoje (o enum
     *     existe na migration, a transição não), mas o dia em que existir —
     *     bloqueio pelo membro, Panic Button, ação de moderação — é exatamente o
     *     dia em que o canal de mensagem fecha e o de ROSTO não pode continuar
     *     aberto. Conversa é arquivada justamente no conflito.
     *  2. `accessState()['can_send']`, e não uma consulta crua a `chat_access`:
     *     assinante de Círculo tem chat livre e **não gera linha** naquela
     *     tabela — a leitura literal recusaria justamente quem paga mais.
     *     Carência (`grace`) NÃO passa: quem não pode nem responder não deve
     *     receber rosto novo.
     *
     * Conversa inexistente é `false`, e não uma exceção: para a foto isso é o
     * caso comum (membro que nunca conversou com aquela performer), e para o
     * chat é inalcançável (quem chega em `sendMessage` já tem a conversa).
     *
     * ── O que este método deliberadamente NÃO decide ────────────────────────
     * - **Se a performer está de pé** (perfil encerrado, conta suspensa/banida).
     *   Isso é gate exclusivo da FOTO e continua em `MemberPhotoService`: trazê-lo
     *   para cá passaria a impedir o membro de responder no chat de uma performer
     *   suspensa, que é mudança de comportamento do chat, não unificação.
     * - **Quem é participante da conversa** e **se quem envia é a performer** —
     *   `sendMessage` resolve os dois antes de chegar aqui, e a performer nunca
     *   passa por este método (ela envia de graça).
     *
     * **Regra nova sobre "o membro pode falar com esta performer" entra AQUI** —
     * e fecha as duas portas de uma vez. É o que o teste de fonte única cobra.
     */
    public function canMemberSendTo(User $member, PerformerProfile $performer): bool
    {
        $conversation = Conversation::query()
            ->where('member_id', $member->getKey())
            ->where('performer_profile_id', $performer->getKey())
            ->first();

        if ($conversation === null || $conversation->status !== 'active') {
            return false;
        }

        return $this->accessState($conversation, $member)['can_send'];
    }

    /**
     * O membro JÁ tem algum vínculo de chat com esta performer? (Sprint 12)
     *
     * ── Por que NÃO é `canMemberSendTo()` ───────────────────────────────────
     * `canMemberSendTo()` pergunta "pode ENVIAR agora numa conversa ativa" — e
     * exige uma `Conversation` de pé. O convite via Stories precisa de outra
     * pergunta: "este seguidor já está no funil de chat, ou é alvo legítimo da
     * isca?". Um assinante de Círculo NÃO gera linha de `chat_access` e pode nem
     * ter aberto conversa ainda, mas tem chat livre — mandar-lhe o CTA "compre 50
     * tokens para conversar" seria vender o que ele já tem. Por isso a resposta é
     * a UNIÃO de dois vínculos, e não a interseção que o envio exige:
     *
     *  1. Assinatura de Círculo ativa → chat livre e permanente (não é alvo).
     *  2. QUALQUER linha de `chat_access` do par, em qualquer status → ele já
     *     entrou no funil pago (comprou acesso algum dia). "sem ChatAccess" da
     *     spec é a ausência da linha, então `exists()` sobre o par, sem filtrar
     *     status: quem já comprou não é "novo seguidor que nunca conversou".
     *
     * Fica AQUI, e não no StoryVisibilityService, pela disciplina de dona única:
     * "o membro tem vínculo de chat com esta performer" é conhecimento do domínio
     * de chat (a tabela `chat_access` e a assinatura). Reescrevê-lo no serviço de
     * story seria a segunda cópia que diverge — e divergiria mostrando o convite a
     * quem não é alvo, ou escondendo-o de quem é.
     *
     * O convite é DELA sobre a própria publicação; esta leitura não expõe membro
     * nenhum à performer — é consumida só no feed do próprio membro, para decidir
     * se ELE vê o selo.
     */
    public function memberHasChatWith(User $member, PerformerProfile $performer): bool
    {
        if ($member->activeSubscription() !== null) {
            return true;
        }

        return ChatAccess::query()
            ->where('member_id', $member->getKey())
            ->where('performer_profile_id', $performer->getKey())
            ->exists();
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
