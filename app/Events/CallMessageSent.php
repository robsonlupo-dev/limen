<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Uma mensagem do chat da chamada 1:1 (feat/in-call-chat), difundida aos DOIS
 * participantes pelo canal privado `call.{id}` (Reverb). Espelha o LiveChatSent
 * da live — o texto anda pelo Reverb (o data channel do LiveKit segue sem uso).
 *
 * ── Payload não-sensível (M.13.10) ───────────────────────────────────────────
 * Leva o `id` da mensagem (referência de moderação), o `label` de EXIBIÇÃO
 * (FanAlias do membro, ou o stage_name da performer), o corpo JÁ filtrado e
 * `is_performer` (quem mandou). NUNCA sender_id, member_id, tier ou saldo. Cada
 * cliente decide "minha ou da outra ponta" pelo `is_performer` vs. o próprio papel.
 */
class CallMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $callId,
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
        return [new PrivateChannel('call.'.$this->callId)];
    }

    public function broadcastAs(): string
    {
        return 'call.chat';
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
