<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O MEMBRO é avisado, em tempo real, de que a performer aceitou a chamada (Sprint
 * 15). Canal privado user.{memberUserId} (só o próprio membro assina). O membro
 * então busca o próprio JWT em `call.token-refresh` para entrar na sala — o token
 * NUNCA viaja no broadcast (o canal é privado, mas o token dá acesso a vídeo e
 * pertence a uma sessão de sessão-web, não a um socket).
 */
class CallAccepted implements ShouldBroadcast
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
        return 'call.accepted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['call_id' => $this->callId];
    }
}
