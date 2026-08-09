<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Uma reserva foi RESOLVIDA sem virar chamada (feat/scheduled-call-v1) — cancelada,
 * não-confirmada, ou no-show de um dos lados. Avisa o lado afetado no canal PRIVADO
 * user.{recipientUserId}. `outcome` é estável para a UI:
 *
 *  - `refunded_not_confirmed`  → membro: performer não confirmou, saldo devolvido;
 *  - `refunded_no_show_performer` → membro: performer não compareceu, saldo liberado;
 *  - `no_show_member`          → performer: membro não compareceu, depósito é seu.
 *
 * NÃO carrega member_id/tier/saldo (M.13.10). O outcome do no_show_member é o único
 * que a performer vê, e diz só "o membro não veio" (o par já é conhecido por ela via
 * a fila de reservas por FanAlias).
 */
class CallReservationResolved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $recipientUserId,
        public int $reservationId,
        public string $outcome,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->recipientUserId)];
    }

    public function broadcastAs(): string
    {
        return 'reservation.resolved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'reservation_id' => $this->reservationId,
            'outcome' => $this->outcome,
        ];
    }
}
