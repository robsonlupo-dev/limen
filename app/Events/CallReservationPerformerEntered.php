<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A performer entrou na sala da chamada agendada — avisa o MEMBRO para entrar
 * ("performer entrou, clique para iniciar") e abre a janela de join_window segundos
 * (feat/scheduled-call-v1). Canal PRIVADO user.{memberUserId}. Sem member_id/saldo;
 * o membro entra por reservation_id (a autorização é do CallReservationService).
 */
class CallReservationPerformerEntered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $memberUserId,
        public int $reservationId,
        public int $joinWindowSeconds,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->memberUserId)];
    }

    public function broadcastAs(): string
    {
        return 'reservation.performer_entered';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'reservation_id' => $this->reservationId,
            'join_window_seconds' => $this->joinWindowSeconds,
        ];
    }
}
