<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Http\Controllers\Controller;
use App\Http\Requests\ToggleDiscreteModeRequest;
use App\Models\User;
use App\Services\DiscreteModeService;
use Illuminate\Http\JsonResponse;

class PreferencesController extends Controller
{
    public function __construct(private DiscreteModeService $discreteMode) {}

    /**
     * Liga/desliga o Modo Discreto: o membro continua seguindo e contando para o
     * Piso de Anonimato, mas some da lista de seguidores da performer — e, com
     * isso, deixa de poder receber Interesse Controlado.
     *
     * A regra vive no DiscreteModeService, compartilhada com a porta web: as
     * duas não podem divergir.
     */
    public function toggleDiscreteMode(ToggleDiscreteModeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $desired = $request->desiredValue() ?? ! $user->discrete_mode;

        if (! $this->discreteMode->mayApply($user, $desired)) {
            return response()->json([
                'message' => 'Modo Discreto disponível apenas para membros Black e Founders Circle',
            ], 403);
        }

        return response()->json([
            'discrete_mode' => $this->discreteMode->apply($user, $desired),
        ]);
    }
}
