<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\SavedSearchException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreSavedSearchRequest;
use App\Models\SavedSearch;
use App\Services\SavedSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buscas salvas do membro (Sprint 12).
 *
 * Vive em `Web\Consumer\` e nunca terá controller irmão do lado da performer: a
 * busca salva é privada do membro (decisão do PO — R3 do Sprint 9). Toda a regra
 * (cap sob lock, allowlist de filtros) está no `SavedSearchService`; aqui só
 * entra a tradução para HTTP. O front lê estas respostas com `fetch`, então elas
 * são JSON explícito — numa rota WEB a exceção não vira JSON sozinha.
 */
class SavedSearchController extends Controller
{
    public function __construct(private SavedSearchService $savedSearches) {}

    /**
     * As buscas salvas do membro. Mesma fonte que a prop do catálogo
     * (`SavedSearchService::listFor`), para as duas não divergirem.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'saved_searches' => $this->savedSearches->listFor($request->user()),
        ]);
    }

    /**
     * Salva a busca atual. 422 quando o teto de 10 é atingido — recusa de
     * negócio que a tela sabe explicar.
     */
    public function store(StoreSavedSearchRequest $request): JsonResponse
    {
        try {
            $search = $this->savedSearches->save(
                $request->user(),
                (string) $request->validated('name'),
                (array) $request->validated('filters'),
            );
        } catch (SavedSearchException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'saved_search' => [
                'id' => $search->id,
                'name' => $search->name,
                'filters' => $search->filters,
            ],
        ], 201);
    }

    /**
     * Apaga uma busca salva. Route-model binding + checagem de dono: 404 para a
     * busca de outro membro, indistinguível de inexistente, para o par de
     * respostas não virar oráculo.
     *
     * `abort_unless` e não uma query escopada aqui: o binding já resolveu a
     * linha; a decisão de propriedade fica visível em code review, na porta.
     */
    public function destroy(Request $request, SavedSearch $search): JsonResponse
    {
        abort_unless($search->user_id === $request->user()->id, 404);

        $search->delete();

        return response()->json(['status' => 'deleted']);
    }
}
