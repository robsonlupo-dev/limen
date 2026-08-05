<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Um MEMBRO é avisado de que o group show terminou PARA ELE (Sprint 15). Canal
 * privado user.{memberUserId} — o motivo pode ser real porque vai só para o
 * próprio membro (ele já conhece o próprio estado), nunca para a performer:
 *   - `upgraded`: outro membro levou a sessão para 1:1 ("A sessão privada foi
 *     iniciada. Obrigado pela companhia.") — o front mostra a despedida por 10s.
 *   - `performer_stopped`: a performer encerrou o grupo.
 */
class GroupEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $memberUserId,
        public int $groupId,
        public string $reason,
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
        return 'group.ended';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->groupId,
            'reason' => $this->reason,
        ];
    }
}
