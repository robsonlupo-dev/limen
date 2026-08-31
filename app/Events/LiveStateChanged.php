<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A live pública PAUSOU ou RETOMOU (feat/private-call-from-live). Difundido a TODOS
 * na sala pelo Reverb, no MESMO canal privado `live.{slug}` do chat/overlay, para os
 * espectadores mostrarem/esconderem o aviso "Em chamada privada — volta já" EM TEMPO
 * REAL, sem recarregar. Payload não-sensível: só o booleano `paused` — nada do
 * membro que pediu a chamada, nada do financeiro (M.13.10).
 *
 * O vídeo da chamada 1:1 roda numa SALA LiveKit SEPARADA (outro room/token): os
 * espectadores da live NUNCA recebem o A/V da chamada — este evento só alterna um
 * aviso na tela deles.
 */
class LiveStateChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $performerSlug,
        public bool $paused,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('live.'.$this->performerSlug)];
    }

    public function broadcastAs(): string
    {
        return 'live.state';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['paused' => $this->paused];
    }
}
