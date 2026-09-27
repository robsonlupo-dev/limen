<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\HighlightException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\HighlightTitleRequest;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\StoryHighlight;
use App\Models\StoryHighlightItem;
use App\Services\StoryHighlightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gerência de Destaques do lado da performer (roadmap social, Onda 1a). O
 * controller só delega ao StoryHighlightService (dona única). Rotas WEB: a
 * exceção não vira JSON sozinha, então o motivo sai em response()->json()
 * explícito, como no StoryController.
 */
class HighlightController extends Controller
{
    public function __construct(private StoryHighlightService $highlights) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok($this->profile($request));
    }

    public function store(HighlightTitleRequest $request): JsonResponse
    {
        $profile = $this->profile($request);

        try {
            $this->highlights->createCollection($profile, (string) $request->string('title'));
        } catch (HighlightException $e) {
            return $this->fail($e);
        }

        return $this->ok($profile);
    }

    public function rename(HighlightTitleRequest $request, StoryHighlight $highlight): JsonResponse
    {
        $profile = $this->profile($request);

        try {
            $this->highlights->rename($profile, $highlight, (string) $request->string('title'));
        } catch (HighlightException $e) {
            return $this->fail($e);
        }

        return $this->ok($profile);
    }

    public function addStory(Request $request, StoryHighlight $highlight, PerformerStory $story): JsonResponse
    {
        $profile = $this->profile($request);

        try {
            $this->highlights->addStory($profile, $highlight, $story);
        } catch (HighlightException $e) {
            return $this->fail($e);
        }

        return $this->ok($profile);
    }

    public function removeItem(Request $request, StoryHighlightItem $item): JsonResponse
    {
        $profile = $this->profile($request);

        try {
            $this->highlights->removeItem($profile, $item);
        } catch (HighlightException $e) {
            return $this->fail($e);
        }

        return $this->ok($profile);
    }

    public function destroy(Request $request, StoryHighlight $highlight): JsonResponse
    {
        $profile = $this->profile($request);

        try {
            $this->highlights->deleteCollection($profile, $highlight);
        } catch (HighlightException $e) {
            return $this->fail($e);
        }

        return $this->ok($profile);
    }

    private function ok(PerformerProfile $profile): JsonResponse
    {
        return response()->json(['highlights' => $this->highlights->ownerView($profile)]);
    }

    private function fail(HighlightException $e): JsonResponse
    {
        // not_owner → 403; demais recusas de negócio → 422 (a tela sabe explicar).
        $status = $e->reason === HighlightException::NOT_OWNER ? 403 : 422;

        return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $status);
    }

    private function profile(Request $request): PerformerProfile
    {
        $profile = $request->user()->performerProfile;

        abort_if($profile === null, 403);

        return $profile;
    }
}
