<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Chamada agendada em curso COM próximo agendamento na fila: avisa a PERFORMER
 * X minutos antes do fim do slot (feat/scheduled-call-v1, spec §9). Canal PRIVADO
 * user.{performerUserId}. **O membro NÃO vê este aviso** (é gestão de agenda da
 * performer, não estado da chamada dele).
 */
class CallReservationSlotWarning implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $performerUserId,
        public int $reservationId,
        public int $minutesLeft,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->performerUserId)];
    }

    public function broadcastAs(): string
    {
        return 'reservation.slot_warning';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'reservation_id' => $this->reservationId,
            'minutes_left' => $this->minutesLeft,
        ];
    }
}
