<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\StoryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\AttachPollRequest;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Services\StoryInteractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Enquete do lado da PERFORMER (roadmap social, Onda 1b): prender e remover a
 * enquete de um story dela. Só delega — ownership, não-exclusivo e nº de opções
 * vivem no `StoryInteractionService`. Rota web → `response()->json()` explícito.
 */
class StoryInteractionController extends Controller
{
    public function __construct(private StoryInteractionService $interactions) {}

    public function store(AttachPollRequest $request, PerformerStory $story): JsonResponse
    {
        try {
            $interaction = $this->interactions->attach(
                $this->profile($request),
                $story,
                (string) $request->string('prompt'),
                (array) $request->input('options', []),
            );
        } catch (StoryException $e) {
            // 403 para "não é sua"/exclusivo, 404 para vencido — a distinção é da regra.
            return response()->json(
                ['reason' => $e->reason, 'message' => $e->getMessage()],
                $e->reason === StoryException::EXPIRED ? 404 : 403,
            );
        }

        return response()->json([
            'poll' => $this->interactions->ownerView($story->fresh()),
        ], 201);
    }

    public function destroy(Request $request, PerformerStory $story): JsonResponse
    {
        try {
            $this->interactions->remove($this->profile($request), $story);
        } catch (StoryException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 403);
        }

        return response()->json(['status' => 'removed']);
    }

    private function profile(Request $request): PerformerProfile
    {
        $profile = $request->user()->performerProfile;

        abort_if($profile === null, 403);

        return $profile;
    }
}
