<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminPayoutController extends Controller
{
    /**
     * Recoloca um payout estacionado em needs_review de volta na fila de
     * reconciliação (status 'processing'). Só um humano chega aqui, depois de
     * decidir que vale reprocessar. NÃO move token nenhum — apenas devolve a linha
     * para o caminho automático; o próximo reconcile a resolve contra o Asaas.
     *
     * unresolved_since é zerado para dar orçamento cheio de tentativas: senão a
     * linha re-estacionaria na primeira busca vazia da próxima rodada.
     */
    public function requeue(Payout $payout): JsonResponse
    {
        $updated = DB::transaction(function () use ($payout) {
            $locked = Payout::where('id', $payout->id)->lockForUpdate()->first();

            // Reconfere sob lock: um webhook pode ter settled o payout entre a
            // leitura da rota e este ponto. Só needs_review pode ser reprocessado.
            if ($locked->status !== 'needs_review') {
                return false;
            }

            $locked->update([
                'status' => 'processing',
                'unresolved_since' => null,
            ]);

            Audit::log('payout.requeued', $locked, ['requeued_by' => auth()->id()]);

            return true;
        });

        if (! $updated) {
            return response()->json([
                'message' => 'Payout não está em revisão manual.',
            ], 422);
        }

        return response()->json([
            'message' => 'Payout recolocado na fila de reconciliação',
        ]);
    }
}
