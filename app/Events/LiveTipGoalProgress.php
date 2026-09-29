<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Progresso da meta de gorjeta atualizado DURANTE uma live (Onda 4 §4.2). Vai no MESMO
 * canal privado `live.{slug}` da LiveReaction, para a barra da meta no <LiveOverlay>
 * subir em tempo real junto com a animação da gorjeta.
 *
 * Payload AGREGADO e não-sensível: só título, alvo, arrecadado e %. NUNCA "quem deu"
 * (o total é a soma das gorjetas; não correlaciona ninguém). Disparado pós-commit pelo
 * LiveOverlayService, só quando há meta ativa e a live está no ar.
 */
class LiveTipGoalProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $performerSlug,
        public string $title,
        public int $target,
        public int $raised,
        public int $pct,
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
        return 'live.goal';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'title' => $this->title,
            'target' => $this->target,
            'raised' => $this->raised,
            'pct' => $this->pct,
        ];
    }
}
