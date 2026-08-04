<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Uma operação de LiveKit (criar sala / emitir token) foi tentada com a feature
 * flag DESLIGADA (config/features.php). Backstop de defesa em profundidade do
 * dark launch: o middleware `feature:*` cobre a porta HTTP, esta cobre qualquer
 * chamador de LiveKitService fora de uma rota gateada (command, job, PR futuro).
 *
 * Teardown (deleteRoom/revokeParticipant) NÃO a lança — precisa rodar DEPOIS do
 * off para esvaziar salas vivas (kill-switch).
 */
class FeatureDisabledException extends RuntimeException
{
    public function __construct(public readonly string $feature)
    {
        parent::__construct("Feature '{$feature}' is disabled.");
    }
}
