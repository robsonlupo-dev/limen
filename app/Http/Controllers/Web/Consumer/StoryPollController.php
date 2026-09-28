<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\StoryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\VotePollRequest;
use App\Models\PerformerStory;
use App\Services\StoryInteractionService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Voto do MEMBRO numa enquete de story (roadmap social, Onda 1b).
 *
 * Só delega — a autorização (mesma porta do serving), o voto imutável e o agregado
 * vivem no `StoryInteractionService`. A resposta traz só a visão do próprio membro
 * (o voto dele + a distribuição anônima), nunca "quem votou". Rota web → JSON.
 */
class StoryPollController extends Controller
{
    public function __construct(private StoryInteractionService $interactions) {}

    public function vote(VotePollRequest $request, PerformerStory $story): JsonResponse
    {
        try {
            $result = $this->interactions->vote(
                $request->user(),
                $story,
                (int) $request->integer('option_index'),
            );
        } catch (StoryException $e) {
            return response()->json(
                ['reason' => $e->reason, 'message' => $e->getMessage()],
                $e->reason === StoryException::EXPIRED ? 404 : 403,
            );
        } catch (InvalidArgumentException) {
            // Índice fora do intervalo: o Form Request valida integer>=0, mas o teto
            // depende das opções DAQUELA enquete (a performer pode tê-la encurtado
            // desde que o cliente carregou). Recusa limpa (422), não 500 de HTML numa
            // rota web (a mensagem não confirma nada novo — o cliente já tem as opções).
            return response()->json(['reason' => 'invalid_option', 'message' => 'Opção de enquete inválida.'], 422);
        }

        return response()->json($result);
    }
}
