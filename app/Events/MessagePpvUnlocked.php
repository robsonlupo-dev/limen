<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitido quando o MEMBRO desbloqueia (paga) uma mensagem PPV (conteúdo travado,
 * Onda 4). Só serve para as OUTRAS abas/dispositivos do PRÓPRIO membro trocarem a
 * bolha borrada pela mídia sem reload — a performer não precisa de nada (já é dona).
 *
 * Por isso transmite no canal PRIVADO DO MEMBRO (`user.{id}`), NÃO no
 * `conversation.{id}` (que a performer também assina): mandar o id do membro no canal
 * da conversa entregaria o id cru dele à performer — inclusive no caso de um membro que
 * desbloqueia sem NUNCA ter mandado mensagem, furando o piso de anonimato (FanAlias).
 * No canal do membro, o payload nunca chega ao outro lado. Ainda assim, só metadado —
 * NUNCA a URL/bytes da mídia; a aba re-busca a conversa pela porta autorizada (show).
 */
class MessagePpvUnlocked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message,
        public int $unlockedByUserId,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        // Canal privado do MEMBRO que desbloqueou — a performer não o assina.
        return [
            new PrivateChannel('user.'.$this->unlockedByUserId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.ppv_unlocked';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // Sem id de membro (o canal já é dele); só o suficiente para a aba achar a
        // conversa/mensagem e re-buscar a mídia autorizada.
        return [
            'message_id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
        ];
    }
}
