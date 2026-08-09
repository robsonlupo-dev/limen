<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O membro entrou na chamada agendada e a `call_sessions` nasceu — avisa a PERFORMER
 * (que já estava na sala esperando) do `call_id` (feat/scheduled-call-v1). A partir
 * daí o cliente dela usa as rotas EXISTENTES do PR #140 (call.token-refresh / call.end)
 * sobre essa sessão — é participante legítima (performer_profile->user_id == ator).
 *
 * Canal PRIVADO user.{performerUserId}. Sem member_id/tier/saldo (M.13.10): só o id
 * da sessão de vídeo, que a performer usa para renovar/encerrar.
 */
class CallReservationCallStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $performerUserId,
        public int $reservationId,
        public int $callId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->performerUserId)];
    }

    public function broadcastAs(): string
    {
        return 'reservation.call_started';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'reservation_id' => $this->reservationId,
            'call_id' => $this->callId,
        ];
    }
}
