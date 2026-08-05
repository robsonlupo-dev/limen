<?php

namespace App\Jobs;

use App\Services\LiveKitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Ejeta do LiveKit os participantes que ficaram para trás quando um group show
 * vira 1:1 por upgrade (Sprint 15). Despachado com atraso de 10s no upgrade-accept:
 * o front dos removidos mostra "A sessão privada foi iniciada. Obrigado pela
 * companhia." durante a janela, e ESTE job é o teardown AUTORITATIVO — mesmo que
 * o cliente ignore a despedida, o LiveKit o remove da sala.
 *
 * As linhas de participante já foram marcadas `ended` (cobrança parada) na
 * transação do accept; este job só faz o revoke de rede, best-effort por identity.
 *
 * `$identities` = FanAlias handle por par (o que a sala conhece). O `$roomName`
 * viaja no payload do job (armazenamento interno da fila, não é log/URL/resposta)
 * — mesma sensibilidade de ele viver em `call_sessions.room_name`.
 */
class RevokeGroupParticipants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $identities
     */
    public function __construct(
        public string $roomName,
        public array $identities,
    ) {}

    public function handle(LiveKitService $livekit): void
    {
        foreach ($this->identities as $identity) {
            try {
                $livekit->revokeParticipant($this->roomName, $identity);
            } catch (\Throwable) {
                // Sala já apagada (performer encerrou a 1:1 em <10s) ou participante
                // já saiu: no-op. Loga sem room_name/identity (invariante).
                Log::warning('group.revoke_participant_failed');
            }
        }
    }
}
