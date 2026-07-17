<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Exceptions\ChatException;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Chat pós-desbloqueio de Interesse. Ver docs/INTEREST_SYSTEM_SPEC.md §4-5 e
 * docs/COMMUNICATION_ECONOMY.md §2.
 *
 * Invariantes:
 * - A conversa NÃO é aberta pelo membro. Ela nasce no desbloqueio do Interesse
 *   (openConversationForUnlock, chamado de InterestService::unlock).
 * - A performer manda de graça; o membro paga por mensagem, exceto se tiver um
 *   Círculo ativo (chat livre). Débito/crédito sempre via token_ledger
 *   append-only (princípio nº 2 do CLAUDE.md), com split como a gorjeta.
 * - Enviar para um membro que optou por sair do Interesse precisa PARECER
 *   sucesso e não entregar nada — senão o opt-out vaza no envio
 *   (INTEREST_ANONYMITY_FLOOR.md, "Consequência para o chat").
 */
class ChatService
{
    public function __construct(private TokenService $tokenService) {}

    private function messageCost(): int
    {
        return (int) config('chat.message_cost');
    }

    /**
     * Abre (ou recupera) o canal do par no desbloqueio do Interesse. Idempotente:
     * um segundo desbloqueio do mesmo par reusa a mesma conversa. Deve rodar
     * dentro da transação do unlock — o índice único (member, performer) fecha a
     * corrida de dois desbloqueios simultâneos.
     */
    public function openConversationForUnlock(PerformerInterest $interest): Conversation
    {
        return Conversation::firstOrCreate(
            [
                'member_id' => $interest->member_id,
                'performer_profile_id' => $interest->performer_profile_id,
            ],
            ['status' => 'active'],
        );
    }

    /**
     * Envia uma mensagem dentro de uma conversa já aberta (resposta de qualquer
     * um dos lados). O remetente precisa participar da conversa — o controller e
     * a policy já garantem; aqui é guarda de defesa.
     *
     * @throws ChatException não-participante ou conversa arquivada
     * @throws \App\Exceptions\InsufficientBalanceException saldo insuficiente do membro
     */
    public function sendMessage(Conversation $conversation, User $sender, string $body): Message
    {
        $conversation->loadMissing('performerProfile');

        if (! $conversation->hasParticipant($sender)) {
            throw ChatException::notAParticipant();
        }

        if ($conversation->status !== 'active') {
            throw ChatException::conversationArchived();
        }

        $senderIsPerformer = $sender->id === $conversation->performerProfile->user_id;

        // Performer nunca paga. Membro com Círculo ativo tem chat livre.
        $free = $senderIsPerformer || $sender->activeCircle() !== null;

        if ($free) {
            $message = Message::forceCreate([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'body' => $body,
            ]);

            $this->finalize($conversation, $message);

            return $message;
        }

        // Membro sem Círculo: paga por mensagem. Cria a mensagem primeiro (para o
        // ledger apontar de volta via reference_id), depois debita/credita, e por
        // fim amarra os ids de ledger — tudo numa transação. Se o débito falhar
        // por saldo, o rollback descarta a mensagem: nunca cobra sem entregar
        // nem entrega sem cobrar.
        $message = DB::transaction(function () use ($conversation, $sender, $body) {
            $performerProfile = $conversation->performerProfile;
            $performerUser = $performerProfile->user;
            $cost = $this->messageCost();

            $message = Message::forceCreate([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'body' => $body,
            ]);

            $spendEntry = $this->tokenService->debit(
                $sender,
                $cost,
                'spend_message',
                Message::class,
                $message->id,
                "Mensagem para {$performerProfile->stage_name}",
            );

            // Split como a gorjeta: a performer recebe split_pct%; o resto é
            // retenção da plataforma. Só credita se sobrar ao menos 1 token.
            // Descrição genérica — o id do membro não vai para o ledger da
            // performer (o vínculo já existe via reference_id / credit_ledger_id).
            $performerAmount = (int) floor($cost * $performerProfile->split_pct / 100);
            $creditEntry = $performerAmount > 0
                ? $this->tokenService->credit(
                    $performerUser,
                    $performerAmount,
                    'message_credit',
                    Message::class,
                    $message->id,
                    'Mensagem recebida no chat',
                )
                : null;

            $message->forceFill([
                'spend_ledger_id' => $spendEntry->id,
                'credit_ledger_id' => $creditEntry?->id,
            ])->save();

            return $message;
        });

        $this->finalize($conversation, $message);

        return $message;
    }

    /**
     * A performer manda a PRIMEIRA mensagem a partir de uma linha de Interesse
     * (o gatilho do canal). Chaveado no interesse, não na conversa, porque é aqui
     * que a máscara de opt-out precisa agir.
     *
     * A resposta é observável pela performer, então os três caminhos precisam ser
     * indistinguíveis do lado dela quanto a "deu certo":
     * - suppressed (membro optou por sair): NÃO persiste nada, NÃO transmite,
     *   devolve null. O controller responde 200 igual ao sucesso real.
     * - unlocked: abre/recupera a conversa e entrega a mensagem (grátis).
     * - sent (ainda não revelou): canal não aberto — a performer vê 'sent' de
     *   forma honesta e não deveria tentar; erro de guarda.
     *
     * @throws ChatException canal ainda não aberto, ou interesse de outra performer
     */
    public function performerMessageFromInterest(
        PerformerProfile $performerProfile,
        PerformerInterest $interest,
        string $body,
    ): ?Message {
        // Releitura fresca do status REAL (o displayed pode estar mascarado).
        $interest = PerformerInterest::findOrFail($interest->id);

        if ($interest->performer_profile_id !== $performerProfile->id) {
            throw ChatException::notAParticipant();
        }

        // Máscara de opt-out: a resposta precisa ESPELHAR o status que a performer
        // vê (scopeDisplayedAsUnlocked), não o status real — senão a diferença
        // 202 vs 422 vaza o opt-out (INTEREST_ANONYMITY_FLOOR.md).
        //  - suprimido exibido como 'sent' (sem unlock prévio): comporta-se como
        //    um 'sent' genuíno → mesmo channelNotOpen (422) de baixo.
        //  - suprimido exibido como 'unlocked' (havia unlock prévio): sucesso
        //    mascarado — nada persistido, nada transmitido, 202 como o real.
        if ($interest->isSuppressed()) {
            AuditLog::create([
                'user_id' => $performerProfile->user_id,
                'action' => 'chat.suppressed_send',
                'subject_type' => PerformerInterest::class,
                'subject_id' => $interest->id,
                'ip' => request()->ip(),
                'metadata' => ['member_id' => $interest->member_id],
            ]);

            if (! $interest->isDisplayedAsUnlocked()) {
                throw ChatException::channelNotOpen();
            }

            return null;
        }

        if (! $interest->isUnlocked()) {
            throw ChatException::channelNotOpen();
        }

        $conversation = $this->openConversationForUnlock($interest);
        $conversation->loadMissing('performerProfile');

        return $this->sendMessage($conversation, $performerProfile->user, $body);
    }

    /**
     * Pós-persistência comum: carimba last_message_at e transmite o evento no
     * canal privado. Fora da transação de cobrança — broadcast não deve reter o
     * lock do wallet.
     */
    private function finalize(Conversation $conversation, Message $message): void
    {
        $conversation->forceFill(['last_message_at' => $message->created_at])->save();

        // event() (não broadcast()) porque MessageSent é ShouldBroadcast: o
        // dispatcher transmite igual, e fica interceptável por Event::fake().
        event(new MessageSent($message));
    }
}
