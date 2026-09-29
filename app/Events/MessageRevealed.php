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
 * Emitido quando o DESTINATÁRIO revela (consome) uma mensagem efêmera com timer
 * (roadmap social, Onda 3). Transmite no canal privado conversation.{id} para que a
 * outra ponta — o REMETENTE — esconda a própria bolha em tempo real ("expirada"),
 * sem depender de reload. O destinatário que acabou de revelar ignora o evento (não
 * pode cortar a contagem dele mesmo).
 *
 * Como os demais eventos de chat, o payload é só metadado — NUNCA o conteúdo. Aqui é
 * ainda mais óbvio: o ponto do evento é justamente ESCONDER o conteúdo.
 */
class MessageRevealed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.revealed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            // O REMETENTE da mensagem — a outra ponta usa isto para saber que é ELA
            // que deve esconder a própria bolha (o destinatário que revelou, não).
            'sender_id' => $this->message->sender_id,
        ];
    }
}
