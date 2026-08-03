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
 * Acesso pago ao chat. Ver docs/COMMUNICATION_ECONOMY.md §2.
 *
 * ── M.13.1 (Sprint 14, PR #132): NÃO existe mais chat grátis ────────────────
 * TODO tier paga para ABRIR o chat — NA/Explorador/Insider/Prestige = 2 tokens,
 * Black/FC = 1 token (TokenCreditPolicy::chatCost, a dona única). A performer
 * recebe 1 token FIXO por abertura, em todos os casos, FORA do split percentual
 * (chatOpenPerformerCredit). O subsídio da plataforma foi eliminado.
 *
 * Consequência que ANTES não valia e agora vale (não reintroduzir o atalho de
 * assinante): **assinante TAMBÉM gera linha de `chat_access`** e a janela dele
 * vence como a de qualquer um (access_days + grace_days, depois o GC soft-deleta
 * as mensagens). Assinatura ativa NÃO garante mais chat livre nem histórico
 * permanente.
 *
 * - Membro paga chatCost tokens por performer por janela de access_days; depois
 *   há grace_days de carência (leitura bloqueada, sem envio); passada a carência
 *   o job soft-deleta as mensagens.
 * - Débito via token_ledger append-only; a performer é creditada 1 token fixo
 *   (chat_access_credit), nunca por split.
 */
class ChatAccessService
{
    public function __construct(
        private TokenService $tokenService,
        private TokenCreditPolicy $creditPolicy,
    ) {}

    private function accessDays(): int
    {
        return (int) config('chat.access_days');
    }

    private function graceDays(): int
    {
        return (int) config('chat.grace_days');
    }

    /**
     * Compra ou renova o acesso do membro à conversa (debita o custo por tier,
     * credita 1 token fixo à performer, cria/estende a janela). Idempotente por
     * idempotency_key: um double-submit com a mesma chave não cobra de novo.
     *
     * Estende a partir do fim da janela vigente se ainda ativa (renovação
     * antecipada empilha), senão a partir de agora.
     *
     * @throws \InvalidArgumentException quem chama não é o membro da conversa
     * @throws InsufficientBalanceException saldo insuficiente
     */
    public function openOrRenew(Conversation $conversation, User $member, string $idempotencyKey): ChatAccess
    {
        $conversation->loadMissing('performerProfile.user');
        $performerProfile = $conversation->performerProfile;

        if ($member->id !== $conversation->member_id) {
            throw new \InvalidArgumentException('Only the conversation member can buy chat access.');
        }

        // M.13.1: assinante também paga e gera linha — sem atalho de chat grátis.

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
            // Custo por tier (M.13.1): 2 (NA/Expl/Ins/Pres) ou 1 (Black/FC).
            $cost = $this->creditPolicy->chatCost($member);

            $spendEntry = $this->tokenService->debit(
                $member,
                $cost,
                'spend_chat_access',
                ChatAccess::class,
                $access?->id,
                "Acesso ao chat de {$performerProfile->stage_name}",
            );

            // Crédito FIXO de 1 token à performer (M.13.1), fora do split, sempre
            // (nunca zero). Via policy: chat_access_credit nunca respeita teto.
            $creditEntry = $this->creditPolicy->credit(
                $performerUser,
                $this->creditPolicy->chatOpenPerformerCredit(),
                'chat_access_credit',
                ChatAccess::class,
                $access?->id,
                'Acesso ao chat recebido',
            );

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
        // M.13.1: sem atalho de assinante. TODO membro passa pela linha de
        // `chat_access` — assinante sem janela paga cai em 'none' como qualquer um.
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
     *  2. `accessState()['can_send']`, a fonte única do gate — NÃO uma segunda
     *     consulta crua a `chat_access`. Desde M.13.1 (PR #132) TODO membro,
     *     assinante ou não, precisa de janela paga para enviar; `accessState` já
     *     resolve isso. **Não reintroduza um atalho de assinante aqui** — era o
     *     modelo antigo (chat livre sem linha), e um `if activeSubscription`
     *     recriaria um bypass do gate da Foto Efêmera. Carência (`grace`) NÃO
     *     passa: quem não pode nem responder não deve receber rosto novo.
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
     * O membro JÁ tem algum vínculo de chat com esta performer? (Sprint 12,
     * revisado no PR #132.)
     *
     * ── Por que NÃO é `canMemberSendTo()` ───────────────────────────────────
     * `canMemberSendTo()` pergunta "pode ENVIAR agora numa conversa ativa" — e
     * exige uma `Conversation` de pé. O convite via Stories precisa de outra
     * pergunta: "este seguidor já entrou no funil de chat pago, ou é alvo
     * legítimo da isca?". A resposta é a existência de QUALQUER linha de
     * `chat_access` do par (em qualquer status): quem já comprou acesso algum dia
     * não é "novo seguidor que nunca conversou".
     *
     * ── M.13.1 (PR #132): a assinatura NÃO conta mais aqui ──────────────────
     * No modelo antigo, assinante tinha chat livre sem linha, então a resposta
     * era a UNIÃO (assinatura OU linha). Com o fim do chat grátis, a assinatura
     * não abre chat com performer nenhuma por si só — o assinante paga para abrir,
     * como todos, e aí ganha a linha. Logo o vínculo é SÓ a linha de `chat_access`.
     * Um assinante que ainda não abriu chat com esta performer é alvo legítimo do
     * convite (ele pagaria 1-2 tokens para abrir) — e isso é correto.
     *
     * Fica AQUI, e não no StoryVisibilityService, pela disciplina de dona única.
     * O convite é DELA sobre a própria publicação; esta leitura não expõe membro
     * nenhum à performer — é consumida só no feed do próprio membro, para decidir
     * se ELE vê o selo.
     */
    public function memberHasChatWith(User $member, PerformerProfile $performer): bool
    {
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
