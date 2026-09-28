<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\BroadcastException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\SendBroadcastRequest;
use App\Models\PerformerProfile;
use App\Services\BroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Canal de transmissão do lado da PERFORMER (roadmap social, Onda 2): publicar uma
 * mensagem para os seguidores. Só delega — anti-contato (Form Request), teto diário
 * e propriedade vivem no service/request. Rota web → `response()->json()` explícito.
 */
class BroadcastController extends Controller
{
    public function __construct(private BroadcastService $broadcasts) {}

    public function store(SendBroadcastRequest $request): JsonResponse
    {
        try {
            $message = $this->broadcasts->send(
                $this->profile($request),
                (string) $request->string('body'),
            );
        } catch (BroadcastException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'broadcast' => [
                'id' => $message->id,
                'body' => $message->body,
                'sent_slot' => 'Hoje',
            ],
        ], 201);
    }

    private function profile(Request $request): PerformerProfile
    {
        $profile = $request->user()->performerProfile;

        abort_if($profile === null, 403);

        return $profile;
    }
}
