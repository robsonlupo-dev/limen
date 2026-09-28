<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\StoryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ReactToStoryRequest;
use App\Models\PerformerStory;
use App\Services\StoryReactionService;
use Illuminate\Http\JsonResponse;

/**
 * Reação rápida do MEMBRO a um story (roadmap social, Onda 1b).
 *
 * Só delega — a autorização (mesma porta do serving), o toggle e o agregado vivem
 * no `StoryReactionService` (item 9 do CLAUDE.md). O que é daqui é a tradução para
 * HTTP: rota WEB, então a exceção não vira JSON sozinha (só em `api/*`), daí o
 * `response()->json()` explícito.
 *
 * A resposta devolve só a reação ATUAL do próprio membro (`null` = removeu) —
 * nunca o agregado nem nada de outros membros. O contador que a performer vê é
 * outro caminho (`StoryPresenter`).
 */
class StoryReactionController extends Controller
{
    public function __construct(private StoryReactionService $reactions) {}

    public function store(ReactToStoryRequest $request, PerformerStory $story): JsonResponse
    {
        try {
            $current = $this->reactions->react(
                $request->user(),
                $story,
                (string) $request->string('reaction'),
            );
        } catch (StoryException $e) {
            // 404 para vencido/fora do ar, 403 para sem alcance/exclusivo — a mesma
            // distinção do serving, decidida pela regra (ver denialFor).
            return response()->json(
                ['reason' => $e->reason, 'message' => $e->getMessage()],
                $e->reason === StoryException::EXPIRED ? 404 : 403,
            );
        }

        return response()->json(['reaction' => $current]);
    }
}
