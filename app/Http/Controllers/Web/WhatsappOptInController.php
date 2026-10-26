<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\WhatsappOptInRequest;
use App\Services\WhatsappOptInService;
use App\Support\BrazilPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Opt-in de WhatsApp da tela de pós-cadastro (feat/signup-email-gate-and-whatsapp-optin).
 * Rotas WEB (sessão + CSRF) consumidas por fetch do Inertia — por isso devolvem JSON
 * explícito (fora de `api/*` a exceção não vira JSON sozinha; ver CLAUDE.md).
 */
class WhatsappOptInController extends Controller
{
    public function __construct(private WhatsappOptInService $service) {}

    /** Grava o opt-in (número + consentimento) e enfileira o push ao Chatwoot. */
    public function store(WhatsappOptInRequest $request): JsonResponse
    {
        $e164 = $this->service->optIn($request->user(), (string) $request->input('whatsapp'));

        if ($e164 === null) {
            return response()->json([
                'message' => 'Informe um número de celular válido com DDD.',
                'errors' => ['whatsapp' => ['Informe um número de celular válido com DDD.']],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'whatsapp_masked' => BrazilPhone::mask($e164),
            'message' => 'Pronto! Vamos te avisar por WhatsApp quando as portas abrirem.',
        ]);
    }

    /** Revoga o opt-in ("Agora não" / sair depois de ter aceitado). */
    public function destroy(Request $request): JsonResponse
    {
        $this->service->optOut($request->user());

        return response()->json(['ok' => true]);
    }
}
