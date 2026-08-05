<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Um lado é avisado de que a chamada terminou (Sprint 15). Canal privado
 * user.{recipientUserId}.
 *
 * A performer recebe SEMPRE a mesma mensagem neutra ("o membro encerrou a
 * sessão") — M.13.10: ela nunca sabe se acabou o saldo do membro, se ele
 * desligou, ou se bateu o teto de tempo. Revelar "sem saldo" seria informação
 * financeira do membro. O `reason` viaja só para o LADO DO MEMBRO (que já sabe o
 * próprio saldo), para o front dele mostrar o banner certo; para a performer o
 * front usa uma cópia fixa.
 *
 * ⚠️ Ressalva de timing registrada (achado 🟡 da revisão): quando o fim é por
 * falta de saldo a chamada cai ~no fim de um minuto, o que dá à performer uma
 * correlação FRACA de "acabou o dinheiro". Não expomos o número; não é
 * indetectável. Não descrever este encerramento como anônimo.
 */
class CallEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $recipientUserId,
        public int $callId,
        public string $reason,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->recipientUserId)];
    }

    public function broadcastAs(): string
    {
        return 'call.ended';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callId,
            'reason' => $this->reason,
        ];
    }
}
