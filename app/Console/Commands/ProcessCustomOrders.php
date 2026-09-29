<?php

namespace App\Console\Commands;

use App\Services\CustomOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Motor de tempo da encomenda sob medida (Onda 4 §4.3). Roda periodicamente e faz três
 * varreduras, todas idempotentes por linha (escrow_settled) com broadcasts pós-commit:
 *
 *  a) REQUESTED não aceito até a janela de aceite → EXPIRA (sem token; o débito é no aceite).
 *  b) ACCEPTED não entregue até a janela de entrega → ESTORNA 100% ao membro.
 *  c) DELIVERED além da janela de contestação → LIBERA 80/20 (ou estorna se a mídia falhou).
 *
 * Disputas (DISPUTED) NÃO são tocadas aqui — ficam retidas para a resolução da moderação.
 */
class ProcessCustomOrders extends Command
{
    protected $signature = 'custom-orders:process';

    protected $description = 'Processa encomendas: expira pedidos, estorna não-entregues, libera entregues.';

    public function handle(CustomOrderService $orders): int
    {
        $expired = $orders->expireStaleRequests();
        $refunded = $orders->refundUndelivered();
        $released = $orders->autoReleaseDelivered();

        $this->info("expired={$expired} refunded={$refunded} released={$released}");
        Log::info('custom-orders:process', [
            'expired' => $expired,
            'refunded' => $refunded,
            'released' => $released,
        ]);

        return self::SUCCESS;
    }
}
