<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use App\Models\Follow;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PreferencesController extends Controller
{
    /**
     * Liga/desliga o Modo Discreto: o membro continua seguindo e contando para o
     * Piso de Anonimato, mas some da lista de seguidores da performer — e, com
     * isso, deixa de poder receber Interesse Controlado.
     *
     * Perk de Black/Founders Circle. A checagem é por rank de tier, não por lista
     * fixa de slugs: um tier novo acima de Black herda o benefício sem que este
     * arquivo precise ser lembrado.
     */
    public function toggleDiscreteMode(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $circle = $user->activeCircle();

        $minimumRank = array_search('black', Circle::TIER_ORDER, true);

        if (! $circle || $circle->tierRank() < $minimumRank) {
            return response()->json([
                'message' => 'Modo Discreto disponível apenas para membros Black e Founders Circle',
            ], 403);
        }

        $newValue = ! $user->discrete_mode;

        DB::transaction(function () use ($user, $newValue) {
            // Atribuição explícita: discrete_mode NÃO é mass-assignable, como
            // preferred_world. É um privilégio de tier, não um campo de formulário.
            $user->discrete_mode = $newValue;
            $user->save();

            // A cópia em follows precisa acompanhar na mesma transação: se a
            // segunda escrita falhasse sozinha, o membro se veria discreto
            // enquanto seguiria listado para as performers.
            Follow::where('user_id', $user->id)->update(['discrete_mode' => $newValue]);
        });

        // Sem PII e sem a lista de performers afetadas — só o fato.
        Audit::log('member.discrete_mode_toggled', $user, ['enabled' => $newValue]);

        return response()->json(['discrete_mode' => $newValue]);
    }
}
