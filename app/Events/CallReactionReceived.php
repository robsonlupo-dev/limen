<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Reação de gorjeta/presente para a performer DENTRO de uma chamada 1:1
 * (feat/in-call-reaction). Diferente da live, a chamada 1:1 não tem o canal
 * `live.{slug}` nem a prova social pública — então a performer não via nada ao
 * receber uma gorjeta/presente na chamada. Este evento leva o sinal pelo canal
 * PESSOAL `user.{performerUserId}` (o mesmo trilho confiável do CallEnded), e o
 * <PrivateCall> da performer anima a reação enquanto estiver montado.
 *
 * É disparado em TODO envio novo de gorjeta/presente (o gate de "está em
 * chamada?" é implícito: só o <PrivateCall> ouve `.call.reaction`, e ele só
 * existe durante a chamada). Fora de uma chamada ninguém consome — inócuo.
 *
 * Privacidade (M.13.10): o payload é NÃO-sensível — tipo + valor/presente.
 * NUNCA member_id, saldo ou tier. O valor vem do SERVIDOR (não de um pacote de
 * cliente forjável), então o que a performer vê é sempre o crédito real.
 */
class CallReactionReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  { type:'tip'|'gift', amount?, gift_slug?, gift_name? }
     */
    public function __construct(
        public int $performerUserId,
        public array $payload,
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
        return 'call.reaction';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
