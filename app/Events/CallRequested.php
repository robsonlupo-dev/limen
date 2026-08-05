<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A performer recebe, em tempo real, um pedido de chamada 1:1 (Sprint 15). Vai no
 * canal PRIVADO user.{performerUserId} — só a própria performer o assina
 * (routes/channels.php).
 *
 * O payload NÃO carrega member_id, tier nem saldo do membro (M.13.10): a performer
 * vê só o FanAlias LABEL (4 dígitos, exibição — nunca o handle de 16 hex, que
 * identifica), o preço por minuto (dela mesma) e a janela de resposta. Decide
 * aceitar/recusar por `callId`, sem nunca conhecer o id real do membro.
 */
class CallRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $performerUserId,
        public int $callId,
        public string $memberLabel,
        public int $pricePerMinute,
        public int $expiresInSeconds,
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
        return 'call.requested';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callId,
            'member_label' => $this->memberLabel,
            'price_per_minute' => $this->pricePerMinute,
            'expires_in_seconds' => $this->expiresInSeconds,
        ];
    }
}
