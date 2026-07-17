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
 * Emitido quando uma mensagem é entregue numa conversa. Transmite no canal
 * PRIVADO conversation.{id} — a autorização em routes/channels.php garante que
 * só os dois participantes recebem.
 *
 * Broadcasting usa illuminate/broadcasting (já presente). O transporte em
 * produção será o Reverb, mas o pacote do servidor ainda não está instalado; o
 * evento é agnóstico de driver e funciona com log/null nos testes.
 */
class MessageSent implements ShouldBroadcast
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
        return 'message.sent';
    }

    /**
     * Só metadados — o CORPO nunca vai no broadcast. Os dois participantes do
     * canal têm estados de acesso diferentes (a performer lê sempre; o membro só
     * com acesso pago em dia), e um payload de canal é único para todos os
     * inscritos. Mandar o corpo aqui entregaria o texto em tempo real a um membro
     * em carência/sem acesso, furando o paywall de leitura que o show() aplica.
     * O cliente recebe o "ping" e busca o corpo pelo show() (gateado por acesso).
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id' => $this->message->sender_id,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
