<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O MEMBRO é avisado de que a performer recusou a chamada (Sprint 15). Canal
 * privado user.{memberUserId}. Payload mínimo: só o callId — a recusa não expõe
 * motivo (a performer não deve justificar, e um motivo viraria superfície de
 * pressão sobre ela).
 */
class CallDeclined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $memberUserId,
        public int $callId,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->memberUserId)];
    }

    public function broadcastAs(): string
    {
        return 'call.declined';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['call_id' => $this->callId];
    }
}
