<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Atualização em tempo real da LISTA de conversas (Chat/Index) de UM participante,
 * no canal privado user.{id}. Cada usuário só assina o próprio id
 * (routes/channels.php), então o payload é específico do destinatário.
 *
 * Diferente do MessageSent — que sinaliza a conversa aberta e OMITE o corpo por
 * ser um payload único para os dois lados — aqui o canal é privado do
 * destinatário, então o preview já vem calculado RESPEITANDO o paywall dele: a
 * performer lê sempre; o membro só com Círculo ativo ou janela paga vigente. Sem
 * leitura, preview = null e a lista mostra o cadeado — nunca vaza o corpo.
 *
 * ── Remetente pela PERSPECTIVA do destinatário (toast, PR #144) ──────────────
 * `senderName`/`senderAvatarUrl` descrevem a OUTRA parte, já mascarada para quem
 * recebe: ao MEMBRO vai o stage_name + avatar da performer; à PERFORMER vai o
 * FanAlias LABEL do membro e avatar NULL — ela NUNCA recebe nome/foto reais do
 * membro (M.13.10 / disciplina do FanAlias). O corpo continua fora: o toast só
 * mostra "enviou uma mensagem"; o `preview` (paywallado) é para a LISTA, não para
 * o toast.
 */
class NewMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $recipientUserId,
        public int $conversationId,
        public string $occurredAt,
        public bool $incrementsUnread,
        public ?string $preview,
        public string $senderName,
        public ?string $senderAvatarUrl,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->recipientUserId)];
    }

    public function broadcastAs(): string
    {
        return 'new.message';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'occurred_at' => $this->occurredAt,
            'increments_unread' => $this->incrementsUnread,
            'preview' => $this->preview,
            // Para o toast (PR #144). Nunca o corpo; nunca o id/nome real do membro
            // no lado da performer (senderName já vem como FanAlias label lá).
            'sender_name' => $this->senderName,
            'sender_avatar_url' => $this->senderAvatarUrl,
        ];
    }
}
