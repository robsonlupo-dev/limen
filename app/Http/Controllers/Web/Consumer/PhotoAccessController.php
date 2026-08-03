<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Models\PerformerPhoto;
use App\Services\PhotoAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lado do MEMBRO do acesso a fotos privadas (Sprint 13): solicitar acesso.
 *
 * A aprovação/revogação é da performer (Web\Performer\PhotoController). Aqui o
 * membro só PEDE — um ato deliberado, como uma gorjeta: expõe o FanAlias dele à
 * performer, mas **não cria Follow** e não devolve nada sobre a conta dela.
 *
 * PRIVACIDADE: nada aqui devolve id de performer nem confirma a existência de
 * foto privada de perfil fora do ar — o service lança 404 uniforme nesses casos.
 * O membro NUNCA aparece por id; a performer o verá só por FanAlias na fila dela.
 */
class PhotoAccessController extends Controller
{
    public function __construct(private PhotoAccessService $access) {}

    /**
     * Solicita acesso a uma foto privada. Idempotente: reenviar devolve o estado
     * atual. Foto pública ou perfil fora do ar → 404 (ModelNotFoundException no
     * service), indistinguível de foto inexistente.
     */
    public function request(Request $request, PerformerPhoto $photo): JsonResponse
    {
        $state = $this->access->request($request->user(), $photo);

        return response()->json(['access_state' => $state]);
    }
}
