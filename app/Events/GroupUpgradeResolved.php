<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O SOLICITANTE do upgrade é avisado do desfecho (Sprint 15). Canal privado
 * user.{memberUserId}. `accepted=true` → a sessão virou 1:1 (ele continua na mesma
 * sala, agora ao preço 1:1; o front reflete). `accepted=false` → "não disponível
 * no momento", nada muda no grupo.
 */
class GroupUpgradeResolved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $memberUserId,
        public int $groupId,
        public bool $accepted,
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
        return 'group.upgrade.resolved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->groupId,
            'accepted' => $this->accepted,
        ];
    }
}
