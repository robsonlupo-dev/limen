<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso não-intrusivo T-5min de uma chamada agendada (feat/scheduled-call-v1).
 * Vai no canal PRIVADO user.{recipientUserId} — despachado UMA VEZ por lado
 * (membro e performer), cada um com o rótulo do OUTRO lado adequado ao destinatário:
 *
 *  - à PERFORMER: FanAlias LABEL do membro (M.13.10 — nunca id/tier/saldo);
 *  - ao MEMBRO: o stage_name da performer.
 *
 * O service/cron monta o `counterpartLabel` correto; este evento não decide máscara.
 */
class CallReservationReminder implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $recipientUserId,
        public int $reservationId,
        public string $counterpartLabel,
        public int $minutesUntil,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->recipientUserId)];
    }

    public function broadcastAs(): string
    {
        return 'reservation.reminder';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'reservation_id' => $this->reservationId,
            'counterpart_label' => $this->counterpartLabel,
            'minutes_until' => $this->minutesUntil,
        ];
    }
}
