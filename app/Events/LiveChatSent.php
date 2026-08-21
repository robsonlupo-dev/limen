<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Uma mensagem do chat da live (feat/live-room-console), difundida a TODOS na sala
 * (performer + espectadores) pelo Reverb, no MESMO canal privado `live.{slug}` do
 * <LiveOverlay> — o data channel do LiveKit segue desligado (canPublishData:false),
 * o texto anda pelo Reverb que já roda.
 *
 * ── Payload não-sensível (mesma disciplina do LiveReaction / M.13.10) ─────────
 * Leva o `id` da mensagem (referência de moderação: a performer silencia clicando
 * numa mensagem, o servidor resolve o autor), o `label` de EXIBIÇÃO (FanAlias 4
 * díg. do membro, ou o stage_name da performer), o corpo já filtrado e `is_performer`.
 * NUNCA sender_id, tier, saldo nem o handle de 16 hex — o id da mensagem é opaco e
 * só a performer (dona da sala) consegue moderá-lo.
 *
 * O canal é por SLUG (público, já no resource) — nunca o room_name do LiveKit.
 */
class LiveChatSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $performerSlug,
        public int $messageId,
        public string $label,
        public string $body,
        public bool $isPerformer,
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
        return 'live.chat';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->messageId,
            'label' => $this->label,
            'body' => $this->body,
            'is_performer' => $this->isPerformer,
        ];
    }
}
