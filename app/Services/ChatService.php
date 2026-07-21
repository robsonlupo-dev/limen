<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Events\NewMessage;
use App\Exceptions\ChatException;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\ChatContentFilter;
use Illuminate\Support\Facades\Cache;

/**
 * Chat pós-desbloqueio de Interesse. Ver docs/INTEREST_SYSTEM_SPEC.md §4-5 e
 * docs/COMMUNICATION_ECONOMY.md §2.
 *
 * Invariantes:
 * - A conversa NÃO é aberta pelo membro. Ela nasce no desbloqueio do Interesse
 *   (openConversationForUnlock, chamado de InterestService::unlock).
 * - A performer manda de graça. O membro conversa de graça se tiver Círculo
 *   ativo; senão precisa de um ACESSO pago em dia (ChatAccessService) — a
 *   cobrança é por acesso/janela, não por mensagem.
 * - Enviar para um membro que optou por sair do Interesse precisa PARECER
 *   sucesso e não entregar nada — senão o opt-out vaza no envio
 *   (INTEREST_ANONYMITY_FLOOR.md, "Consequência para o chat").
 */
class ChatService
{
    public function __construct(private ChatAccessService $chatAccessService) {}

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
     * Envia uma mensagem numa conversa aberta. O remetente precisa participar; o
     * controller e a policy já garantem — aqui é guarda de defesa.
     *
     * Cobrança é por ACESSO, não por mensagem: a performer sempre pode enviar;
     * o membro só envia com Círculo ativo OU acesso pago em dia (can_send). Sem
     * isso, ChatException::accessRequired — o controller mostra o CTA de compra.
     *
     * @throws ChatException não-participante, conversa arquivada, ou sem acesso
     */
    public function sendMessage(Conversation $conversation, User $sender, string $body): Message
    {
        $conversation->loadMissing('performerProfile');

        $this->assertContentAllowed($sender, $body);

        if (! $conversation->hasParticipant($sender)) {
            throw ChatException::notAParticipant();
        }

        if ($conversation->status !== 'active') {
            throw ChatException::conversationArchived();
        }

        $senderIsPerformer = $sender->id === $conversation->performerProfile->user_id;

        // A performer sempre pode enviar (grátis). O membro depende do acesso.
        if (! $senderIsPerformer) {
            $state = $this->chatAccessService->accessState($conversation, $sender);

            if (! $state['can_send']) {
                throw ChatException::accessRequired();
            }
        }

        $message = Message::forceCreate([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => $body,
        ]);

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
     *   devolve null. O controller responde 202 igual ao sucesso real.
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

        // ANTES da máscara de opt-out, e isso é o ponto: o caminho suprimido
        // devolve 202 sem persistir nada, e o caminho normal cairia no 422 do
        // filtro lá dentro (sendMessage). A performer que mandasse um termo
        // barrado veria 202 para quem optou por sair e 422 para quem não optou
        // — o par de respostas viraria oráculo do opt-out, que é exatamente o
        // que INTEREST_ANONYMITY_FLOOR.md proíbe. Filtrando aqui em cima, o
        // termo barrado devolve 422 para todo mundo, e a resposta volta a
        // depender só do texto que a própria performer escreveu.
        $this->assertContentAllowed($performerProfile->user, $body);

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
     * Barra a mensagem que casa com a lista de termos proibidos.
     *
     * Roda ANTES de qualquer checagem de participação, acesso ou opt-out — de
     * propósito. O resultado depende só do texto, então não distingue estado
     * nenhum do destinatário e não vira oráculo de nada. Fosse depois do gate
     * de acesso, o par de respostas passaria a contar ao remetente se ele tinha
     * acesso, coisa que o filtro não precisa saber.
     *
     * @throws ChatException
     */
    private function assertContentAllowed(User $sender, string $body): void
    {
        $match = ChatContentFilter::match($body);

        if ($match === null) {
            return;
        }

        $isConduct = $match['category'] === ChatContentFilter::CONDUCT;

        $this->auditBlock($sender, $body, $match, $isConduct);

        throw $isConduct
            ? ChatException::conductBlocked()
            : ChatException::legalRiskBlocked();
    }

    /**
     * Registra o bloqueio, deduplicado por (usuário, regra) na janela.
     *
     * A regra vai em HMAC; o CORPO da mensagem não vai de jeito nenhum —
     * decisão do PO, e `audit_logs` é lido por admin e sobrevive ao Hard
     * Delete: copiar a mensagem para cá criaria uma segunda cópia do conteúdo
     * privado do chat, fora do soft-delete que o LGPD do projeto aplica em
     * `messages`. A contrapartida é que a moderação age por REPETIÇÃO, não
     * julgando o caso isolado.
     *
     * A deduplicação existe porque a lista é enumerável: sem ela, uma conta
     * varrendo os termos escreve dezenas de linhas por minuto e enterra a
     * trilha — o mesmo cuidado que o GeoBlock já toma.
     *
     * @param  array{category: string, rule: string}  $match
     */
    private function auditBlock(User $sender, string $body, array $match, bool $isConduct): void
    {
        $ruleHash = ChatContentFilter::digest($match['rule']);
        $minutes = max(1, (int) config('chat_filters.audit_dedup_minutes'));

        // add() é atômico: dois envios simultâneos não viram duas linhas.
        if (! Cache::add('chatfilter:'.$sender->id.':'.substr($ruleHash, 0, 32), true, now()->addMinutes($minutes))) {
            return;
        }

        AuditLog::create([
            'user_id' => $sender->id,
            'action' => 'chat.message_blocked',
            'ip' => request()->ip(),
            'metadata' => [
                'category' => $match['category'],
                'rule_hash' => $ruleHash,
                'body_length' => mb_strlen($body),
                // Só conduta vai para a fila de moderação. Risco legal é
                // barrado e contado; conduta é barrada E olhada por gente,
                // porque reincidência ali é caso de suspensão, não de config.
                'flagged_for_review' => $isConduct,
            ],
        ]);
    }

    /**
     * Pós-persistência comum: carimba last_message_at e transmite o evento no
     * canal privado.
     */
    private function finalize(Conversation $conversation, Message $message): void
    {
        $conversation->forceFill(['last_message_at' => $message->created_at])->save();

        // event() (não broadcast()) porque MessageSent é ShouldBroadcast: o
        // dispatcher transmite igual, e fica interceptável por Event::fake().
        event(new MessageSent($message));

        $this->broadcastListUpdate($conversation, $message);
    }

    /**
     * Empurra a atualização da LISTA (Chat/Index) para os DOIS participantes, cada
     * um no seu canal privado user.{id}. O preview do membro respeita o paywall
     * (mesma regra do ChatController::index): sem leitura plena, vai null e a UI
     * mostra o cadeado — nunca vaza o corpo. A performer lê sempre.
     */
    private function broadcastListUpdate(Conversation $conversation, Message $message): void
    {
        $preview = str($message->body)->limit(60)->value();
        $occurredAt = $message->created_at->toIso8601String();
        $performerUserId = $conversation->performerProfile->user_id;

        event(new NewMessage(
            recipientUserId: $performerUserId,
            conversationId: $conversation->id,
            occurredAt: $occurredAt,
            incrementsUnread: $message->sender_id !== $performerUserId,
            preview: $preview,
        ));

        $member = $conversation->member;
        if ($member !== null) {
            $memberCanRead = $this->chatAccessService->accessState($conversation, $member)['can_read'];

            event(new NewMessage(
                recipientUserId: $member->id,
                conversationId: $conversation->id,
                occurredAt: $occurredAt,
                incrementsUnread: $message->sender_id !== $member->id,
                preview: $memberCanRead ? $preview : null,
            ));
        }
    }
}
