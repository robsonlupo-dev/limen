<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Events\NewMessage;
use App\Exceptions\ChatException;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PerformerInterest;
use App\Models\PerformerMessageQuota;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\ChatContentFilter;
use App\Support\FanAlias;
use App\Support\MessageTeaser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

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
     * feat/chat-economy-v2: a performer sempre envia de graça; o membro paga o
     * acesso no ATO DO 1º ENVIO (openForFirstSend) — depois da 1ª abertura a janela
     * expira/renova EXATAMENTE como hoje. Ver o comentário do ramo abaixo.
     *
     * @throws ChatException não-participante, conversa arquivada, ou acesso em carência/expirado
     * @throws \App\Exceptions\InsufficientBalanceException saldo insuficiente no 1º envio
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

        // feat/chat-economy-v2: a cobrança do membro passou do desbloqueio prévio
        // para o ATO DO 1º ENVIO. A performer sempre envia de graça. Para o membro:
        //
        //  - Sem linha de acesso (nunca abriu com esta performer): o envio ABRE e
        //    paga a janela de 30 dias no mesmo gesto (openForFirstSend), débito +
        //    crédito 80/20 na MESMA transação da criação da mensagem — ou os três
        //    persistem, ou nenhum. Saldo insuficiente sobe InsufficientBalance-
        //    Exception, revertendo tudo (sem mensagem, sem cobrança, sem negativo).
        //  - Com janela ATIVA: envia de graça (2ª+ mensagem da janela não debita).
        //  - Com linha em carência/expirada: BLOQUEIA (accessRequired). A retenção
        //    fica EXATAMENTE como hoje — grace/expired exige renovação EXPLÍCITA
        //    (pagar para ler, openOrRenew), não auto-renova no envio.
        //
        // A leitura de `accessFor` é sem-lock só para DECIDIR o ramo; a cobrança em
        // si (openForFirstSend) re-lê sob lock e é charge-once por concorrência.
        //
        // O guard de conversa arquivada fica ACIMA, FORA da transação: conversa
        // bloqueada recusa ANTES de qualquer débito (nem pagando o membro fura o
        // bloqueio), e a exceção específica (conversationArchived) não se perde.
        if (! $senderIsPerformer) {
            $access = $this->chatAccessService->accessFor($conversation, $sender);

            if ($access !== null && ! $access->hasFullAccess()) {
                throw ChatException::accessRequired();
            }
        }

        // Cobrança + criação da mensagem + carimbo de last_message_at: ATÔMICOS. O
        // broadcast fica FORA (após o commit) — o padrão do projeto (tip/gift/call):
        // o cliente re-busca via HTTP, que só chega depois deste commit, então não
        // há corrida entre o evento e a escrita. (Na dev o driver é `log`.)
        $message = DB::transaction(function () use ($conversation, $sender, $body, $senderIsPerformer) {
            if (! $senderIsPerformer && $this->chatAccessService->accessFor($conversation, $sender) === null) {
                $this->chatAccessService->openForFirstSend($conversation, $sender);
            }

            $message = Message::forceCreate([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'body' => $body,
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });

        $this->broadcastMessage($conversation, $message);

        return $message;
    }

    /**
     * feat/chat-economy-v2: o MEMBRO inicia a conversa com uma performer que ele
     * descobriu no catálogo — sem ela ter demonstrado Interesse antes. É a inversão
     * do portão histórico (o canal só nascia no unlock do Interesse, que dependia de
     * a performer descobrir o membro — em catálogo de pré-lançamento, zero conversas).
     *
     * A conversa nasce aqui (idempotente por (member, performer)); a cobrança do
     * tier acontece no ENVIO (sendMessage → openForFirstSend), não neste ponto.
     *
     * @throws ChatException conteúdo barrado (filtro), conversa arquivada
     * @throws \App\Exceptions\InsufficientBalanceException saldo insuficiente
     */
    public function memberSendToPerformer(PerformerProfile $performerProfile, User $member, string $body): Message
    {
        // Filtro ANTES de qualquer transação/criação: mensagem barrada audita e
        // devolve 422 sem criar conversa nem cobrar token (mesma disciplina de
        // sendCatalogMessage/performerMessageFromInterest). Roda fora da transação
        // para o audit do bloqueio PERSISTIR (não ser revertido no rollback).
        $this->assertContentAllowed($member, $body);

        return DB::transaction(function () use ($performerProfile, $member, $body) {
            // Cria (ou recupera) a conversa do par — o índice único (member,
            // performer) fecha a corrida de dois inícios simultâneos.
            $conversation = Conversation::firstOrCreate(
                ['member_id' => $member->id, 'performer_profile_id' => $performerProfile->id],
                ['status' => 'active'],
            );
            $conversation->loadMissing('performerProfile');

            // sendMessage cobra no envio. Se o saldo não cobrir, a exceção sobe e
            // ESTA transação reverte a conversa recém-criada — sem conversa-fantasma
            // que a performer veria como thread vazia, sem cobrança.
            return $this->sendMessage($conversation, $member, $body);
        });
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
     * MENSAGEM PERSONALIZADA do catálogo de membros (home da performer).
     *
     * A performer inicia a conversa com um membro que ela vê no catálogo — a
     * PRIMEIRA superfície em que a performer abre o canal (o Interesse Controlado
     * abre só no unlock pago do MEMBRO). Grátis para ela até esgotar a franquia
     * diária (config/member_engagement.php); o membro vê QUE recebeu e DE QUEM (a
     * performer nunca é anônima), mas o CORPO fica bloqueado até ele abrir o chat
     * pago (ChatAccessService — a mesma economia M.13.1). Só o membro paga, e paga
     * para LER, nunca por mensagem.
     *
     * @throws ChatException conteúdo barrado (filtro) ou franquia diária esgotada
     */
    public function sendCatalogMessage(PerformerProfile $performerProfile, User $member, string $body): Message
    {
        // Filtro ANTES de consumir a franquia (mesma disciplina de
        // performerMessageFromInterest): uma mensagem barrada não gasta cota e
        // responde 422 pelo texto, sem depender de nada do destinatário.
        $this->assertContentAllowed($performerProfile->user, $body);

        return DB::transaction(function () use ($performerProfile, $member, $body) {
            // Serializa os envios DESTA performer travando a linha do perfil como
            // 1ª instrução (padrão do InterestService): a contagem da franquia
            // abaixo fica inescapável por corrida — dois envios simultâneos não
            // furam o teto diário.
            PerformerProfile::whereKey($performerProfile->id)->lockForUpdate()->first();

            $this->consumeDailyMessageQuota($performerProfile);

            // Reusa a conversa do par se já existir (ex.: interesse desbloqueado
            // antes), senão nasce aqui. Idempotência por (member, performer) —
            // mesmo firstOrCreate de openConversationForUnlock.
            $conversation = Conversation::firstOrCreate(
                ['member_id' => $member->id, 'performer_profile_id' => $performerProfile->id],
                ['status' => 'active'],
            );
            $conversation->loadMissing('performerProfile');

            // A performer envia de graça (sendMessage não a submete ao gate de
            // acesso do membro). O membro só LÊ o corpo com janela paga
            // (accessState.can_read); até lá o broadcast e o preview vão sem corpo
            // (broadcastListUpdate já respeita o paywall). O filtro roda de novo
            // aqui dentro — idempotente, já passou.
            return $this->sendMessage($conversation, $performerProfile->user, $body);
        });
    }

    /**
     * Consome uma unidade da franquia diária de mensagens grátis da performer, ou
     * lança se esgotada. Contado sob o lock do perfil (sendCatalogMessage), então
     * a leitura da linha do dia é serializada. Uma linha por (performer, dia); dia
     * novo = linha nova, reset implícito.
     *
     * @throws ChatException franquia diária esgotada
     */
    private function consumeDailyMessageQuota(PerformerProfile $performerProfile): void
    {
        $limit = (int) config('member_engagement.free_messages_per_day');
        $today = now()->toDateString();

        $quota = PerformerMessageQuota::firstOrCreate([
            'performer_profile_id' => $performerProfile->id,
            'quota_date' => $today,
        ]);

        // Trava a própria linha do contador: sob o lock do perfil ela já não
        // corre com outro envio desta performer, mas travá-la mantém a leitura
        // fresca (imune ao snapshot de REPEATABLE READ).
        $quota = PerformerMessageQuota::whereKey($quota->id)->lockForUpdate()->first();

        if ($quota->sent_count >= $limit) {
            throw ChatException::dailyMessageLimit($limit);
        }

        $quota->increment('sent_count');
    }

    /**
     * Quantas mensagens grátis restam à performer hoje. Consumido pelo catálogo
     * para exibir "N/limite" e travar o botão ao esgotar.
     */
    public function remainingDailyMessages(PerformerProfile $performerProfile): int
    {
        $limit = (int) config('member_engagement.free_messages_per_day');

        $sent = (int) PerformerMessageQuota::where('performer_profile_id', $performerProfile->id)
            ->where('quota_date', now()->toDateString())
            ->value('sent_count');

        return max(0, $limit - $sent);
    }

    /**
     * Total de mensagens NÃO lidas endereçadas ao usuário — o número da bolinha ao
     * lado de "Mensagens" na nav, para os DOIS papéis. Não lida = mensagem do
     * OUTRO participante ainda sem read_at.
     *
     * Respeita o MESMO paywall do index()/show(): a performer sempre lê; o membro
     * só conta conversas com ACESSO pleno vigente (chat_access status != deleted e
     * expires_at futuro — o hasFullAccess() do ChatAccess, aqui em SQL). Sem isso,
     * a nav diria "3" enquanto a lista de mensagens mostra 0 atrás do cadeado — o
     * par viraria a contagem-atrás-do-paywall que o index() recusa de propósito.
     *
     * É o irmão-agregado da contagem POR conversa do ChatController::index: os dois
     * têm que concordar na regra (outro participante + sem read_at + só quando
     * legível). Message respeita SoftDeletes, então mensagem retida não conta.
     */
    public function unreadCountFor(User $user): int
    {
        $viewerIsPerformer = $user->role === 'performer' && $user->performerProfile !== null;

        if (! $viewerIsPerformer && $user->role !== 'consumer') {
            return 0;
        }

        return Message::query()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $user->id)
            ->whereHas('conversation', function ($conversation) use ($user, $viewerIsPerformer) {
                if ($viewerIsPerformer) {
                    $conversation->where('performer_profile_id', $user->performerProfile->id);

                    return;
                }

                $conversation->where('member_id', $user->id)
                    ->whereExists(function ($sub) use ($user) {
                        $sub->select(DB::raw(1))
                            ->from('chat_access')
                            ->whereColumn('chat_access.performer_profile_id', 'conversations.performer_profile_id')
                            ->where('chat_access.member_id', $user->id)
                            ->where('chat_access.status', '!=', 'deleted')
                            ->where('chat_access.expires_at', '>', now());
                    });
            })
            ->count();
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
     * Transmite o evento no canal privado da conversa + a atualização da lista aos
     * dois participantes. Chamado APÓS o commit da escrita (o carimbo de
     * last_message_at fica na transação de sendMessage), para o broadcast nunca
     * preceder a persistência.
     */
    private function broadcastMessage(Conversation $conversation, Message $message): void
    {
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
        $profile = $conversation->performerProfile;
        $performerUserId = $profile->user_id;

        // Remetente pela perspectiva de CADA destinatário (toast, PR #144):
        //  - à performer, a OUTRA parte é o membro → FanAlias LABEL, avatar NULL
        //    (ela nunca vê nome/foto reais do membro — M.13.10).
        //  - ao membro, a OUTRA parte é a performer → stage_name + avatar dela.
        $memberAlias = $conversation->member_id !== null
            ? FanAlias::label($profile->id, $conversation->member_id)
            : 'Membro';

        event(new NewMessage(
            recipientUserId: $performerUserId,
            conversationId: $conversation->id,
            occurredAt: $occurredAt,
            incrementsUnread: $message->sender_id !== $performerUserId,
            preview: $preview,
            senderName: $memberAlias,
            senderAvatarUrl: null,
        ));

        $member = $conversation->member;
        if ($member !== null) {
            // Chaveia em `locked`, NÃO em `can_read`. Na CARÊNCIA (grace) o
            // can_read é true mas o corpo é retido em todo lugar (index usa
            // hasFullAccess → teaser; show devolve body=null) — usar can_read aqui
            // mandaria o preview de 60 chars ao membro em grace, mais do que o
            // teaser que o resto da superfície concede. `locked` (false só com
            // acesso pleno) alinha o broadcast ao index/show. (Achado da revisão.)
            $memberLocked = $this->chatAccessService->accessState($conversation, $member)['locked'];

            // Travado (nunca pagou, expirado OU carência): o membro recebe o GANCHO
            // (teaser cortado no servidor), nunca o corpo completo. A lista/toast
            // mostram o teaser + "desbloqueie para ler" (locked=true). Com acesso
            // pleno, o preview normal e locked=false.
            event(new NewMessage(
                recipientUserId: $member->id,
                conversationId: $conversation->id,
                occurredAt: $occurredAt,
                incrementsUnread: $message->sender_id !== $member->id,
                preview: $memberLocked ? MessageTeaser::for($message->body) : $preview,
                senderName: $profile->stage_name,
                senderAvatarUrl: $this->performerAvatarUrl($profile),
                locked: $memberLocked,
            ));
        }
    }

    /**
     * URL assinada temporária do avatar da performer para o toast (mesma rota e TTL
     * do PerformerPublicResource). Null quando não há avatar — o toast cai num
     * placeholder. Usa o profile_id (nunca o user_id) na assinatura.
     */
    private function performerAvatarUrl(PerformerProfile $profile): ?string
    {
        if (! $profile->avatar_path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'performer.media',
            now()->addMinutes(60),
            ['profile_id' => $profile->id, 'type' => 'avatar'],
        );
    }
}
