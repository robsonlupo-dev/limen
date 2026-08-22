<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\GiftException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\SendGiftRequest;
use App\Models\Gift;
use App\Services\GiftService;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;

class GiftController extends Controller
{
    public function __construct(
        private GiftService $giftService,
        private TokenService $tokenService,
    ) {}

    /** Catálogo público e cacheável (só presentes ativos, do mais barato ao mais caro). */
    public function catalog(): JsonResponse
    {
        return response()->json(['gifts' => self::activeGifts()]);
    }

    /** Envio de presente. Mapeia a recusa de domínio para HTTP (rota web → JSON explícito). */
    public function store(SendGiftRequest $request): JsonResponse
    {
        $performer = $request->resolvedPerformer();

        // Presente não encontrado/inativo → 422 JSON com motivo REAL (o catálogo é
        // público, sem PII). Antes era um 404 HTML do firstOrFail, ilegível para o
        // fetch → mensagem genérica na sala ao vivo.
        $gift = $request->resolvedGift();
        if ($gift === null) {
            return response()->json([
                'reason' => 'gift_unavailable',
                'message' => 'Este presente não está mais disponível. Atualize a página e tente de novo.',
            ], 422);
        }

        try {
            $send = $this->giftService->send(
                $request->user(),
                $performer,
                $gift,
                $request->validated('idempotency_key'),
            );
        } catch (GiftException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        } catch (InsufficientBalanceException) {
            return response()->json([
                'message' => 'Saldo de tokens insuficiente para enviar este presente.',
                'reason' => 'insufficient_balance',
            ], 422);
        }

        return response()->json([
            'message' => 'Presente enviado.',
            'gift_send_id' => $send->id,
            'tokens' => $send->tokens,
            'new_balance' => $this->tokenService->balance($request->user()),
        ], 201);
    }

    /** Shape único do catálogo, compartilhado com a API. Sem PII — dado da Limen. */
    public static function activeGifts(): array
    {
        return Gift::active()
            ->orderBy('price_tokens')
            ->get(['id', 'slug', 'name', 'price_tokens'])
            ->all();
    }
}
