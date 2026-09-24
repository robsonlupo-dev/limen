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
 * Emitido quando o remetente DESFAZ O ENVIO de uma mensagem
 * (feat/chat-unsend-message). Transmite no canal privado conversation.{id}; a
 * outra ponta recarrega o thread pelo show() e a bolha vira "Mensagem apagada".
 *
 * Como o MessageSent, o payload é só metadado — nunca o conteúdo. Aqui isso é
 * ainda mais óbvio: o ponto do evento é justamente ESCONDER o conteúdo.
 */
class MessageRedacted implements ShouldBroadcast
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
        return 'message.redacted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id' => $this->message->sender_id,
        ];
    }
}
