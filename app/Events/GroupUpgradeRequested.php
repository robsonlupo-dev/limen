<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A performer recebe, em tempo real, um pedido de upgrade do group show para 1:1
 * (Sprint 15). Canal privado user.{performerUserId} (só ela assina).
 *
 * O payload leva só o FanAlias LABEL (4 díg., exibição) do solicitante + o id do
 * group — NUNCA member_id, handle, tier ou saldo (M.13.10). Ela decide aceitar/
 * recusar por `groupId`, sem conhecer o id real do membro.
 */
class GroupUpgradeRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $performerUserId,
        public int $groupId,
        public string $memberLabel,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->performerUserId)];
    }

    public function broadcastAs(): string
    {
        return 'group.upgrade.requested';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->groupId,
            'member_label' => $this->memberLabel,
        ];
    }
}
