<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mudança de estado de uma encomenda sob medida (Onda 4 §4.3) empurrada em tempo real
 * para a OUTRA parte (a que precisa agir/saber): pedido→performer, aceite/recusa/
 * entrega/resolução→membro. Vai no canal privado `user.{id}` do destinatário.
 *
 * Payload só metadado — `order_id` + `outcome` (requested/accepted/declined/delivered/
 * released/refunded/disputed). NUNCA member_id/tier/saldo/descrição (privacidade
 * M.13.10, mesma disciplina do CallReservationResolved). A outra ponta re-busca a
 * encomenda pela porta autorizada.
 */
class CustomOrderChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $recipientUserId,
        public int $orderId,
        public string $outcome,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->recipientUserId)];
    }

    public function broadcastAs(): string
    {
        return 'custom_order.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'outcome' => $this->outcome,
        ];
    }
}
