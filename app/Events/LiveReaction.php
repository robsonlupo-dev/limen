<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Gorjeta/presente enviado DURANTE uma live pública ativa (Sprint 15, PR #142).
 * É PROVA SOCIAL: todos na sala (performer + espectadores) veem a animação do
 * <LiveOverlay>. Vai por Reverb (não pelo data channel do LiveKit — o Reverb já
 * roda) no canal privado `live.{slug}` da performer.
 *
 * ── Payload não-sensível (achado da spec) ────────────────────────────────────
 * Leva SÓ: tipo (tip|gift), slug do presente, tokens e o FanAlias LABEL (4 díg.,
 * exibição) do remetente. NUNCA member_id, saldo, tier nem o handle de 16 hex —
 * é a mesma disciplina do FanAlias das outras superfícies (M.13.10). O label é
 * por par (performer, membro), então não correlaciona entre perfis.
 *
 * O canal é por SLUG (público, já no resource do viewer) e não pelo room_name do
 * LiveKit — o room_name nunca sai em canal/URL/log (invariante do #138).
 */
class LiveReaction implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $performerSlug,
        public string $type,
        public ?string $giftSlug,
        public int $amountTokens,
        public string $fanAliasLabel,
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
        return 'live.reaction';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'gift_slug' => $this->giftSlug,
            'amount_tokens' => $this->amountTokens,
            'fan_alias_label' => $this->fanAliasLabel,
        ];
    }
}
