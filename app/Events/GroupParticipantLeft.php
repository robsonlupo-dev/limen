<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A performer é avisada de que um membro saiu do group show (Sprint 15). Canal
 * privado user.{performerUserId}.
 *
 * SÓ o FanAlias LABEL — a performer vê "{FanAlias} saiu" e NADA sobre o motivo:
 * saiu voluntariamente, ficou sem saldo ou foi banido são indistinguíveis para
 * ela (M.13.10 — nunca informação financeira do membro). Sem member_id/handle/
 * tier/saldo.
 */
class GroupParticipantLeft implements ShouldBroadcast
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
        return 'group.participant.left';
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
