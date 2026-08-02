<?php

namespace App\Services;

use App\Exceptions\SavedSearchException;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Buscas salvas do membro (Sprint 12) — dona única da regra.
 *
 * O membro guarda combinações de filtros do catálogo para reaplicar. É área de
 * MEMBRO e nada aqui tem lado da performer — mesma disciplina de `Favorite`:
 * este serviço não tem, e não pode ganhar, um método que responda pelo lado
 * dela. Nada deste fluxo entra em `audit_logs` (seria a cópia do mapa de
 * interesses que o Hard Delete apaga).
 *
 * Duas invariantes vivem aqui, não no controller nem no Vue:
 *  - o TETO de 10 buscas por membro, imposto sob lock (ver `save()`);
 *  - o ALLOWLIST de filtros: só as facetas conhecidas do catálogo são
 *    persistidas. Payload extra é descartado ANTES de gravar, então o JSON nunca
 *    vira blob arbitrário — e o que volta reaplica limpo por
 *    `PerformerCatalogService::applyFilters()`.
 */
class SavedSearchService
{
    /**
     * As chaves de filtro que podem ser salvas — derivadas da fonte única das
     * facetas (`PerformerCatalogService::filterRules()`), nunca uma lista à mão.
     * Faceta nova no catálogo passa a poder ser salva sem tocar este arquivo; e
     * o que o allowlist recusa é qualquer chave que a busca não conhece.
     *
     * As entradas com `.*` (regras de item de array, ex. `tags.*`) são
     * descartadas: o allowlist é de chaves de topo.
     *
     * @return array<int, string>
     */
    public static function allowedFilterKeys(): array
    {
        return array_values(array_filter(
            array_keys(PerformerCatalogService::filterRules()),
            fn (string $key) => ! str_contains($key, '.'),
        ));
    }

    /**
     * Salva a busca. Cap de `SavedSearch::MAX_SAVED` imposto SOB LOCK.
     *
     * ── Por que sob lock, e o que se trava ──────────────────────────────────
     * O cap é por membro e conta linhas, não uma linha só — então não há um
     * UNIQUE que o proteja como no toggle do favorito. Sem serializar, dois
     * saves concorrentes do mesmo membro leriam "9" juntos e gravariam o 10º e o
     * 11º. Travar a LINHA do usuário (`users`, que sempre existe) serializa os
     * saves daquele membro sem bloquear os de outros: o segundo request espera, e
     * relê a contagem já com o 10º gravado. É cap DURO, não soft — diferente do
     * teto de slots do Boost, porque aqui há uma linha-âncora natural para travar.
     *
     * O allowlist roda depois do lock, sobre `filters` JÁ validado pelo Form
     * Request: `Arr::only` garante que nenhuma chave desconhecida atravesse para
     * o banco, mesmo que o Request valide só o TIPO das chaves conhecidas.
     *
     * @param  array<string, mixed>  $filters  já validado por StoreSavedSearchRequest
     *
     * @throws SavedSearchException teto atingido (LIMIT → 422)
     */
    public function save(User $user, string $name, array $filters): SavedSearch
    {
        return DB::transaction(function () use ($user, $name, $filters) {
            // Trava a linha do usuário para serializar saves concorrentes DELE —
            // ver o docblock. `lockForUpdate` sobre uma linha que sempre existe.
            DB::table('users')->where('id', $user->getKey())->lockForUpdate()->first();

            if (SavedSearch::where('user_id', $user->getKey())->count() >= SavedSearch::MAX_SAVED) {
                throw SavedSearchException::limitReached(SavedSearch::MAX_SAVED);
            }

            // FKs e conteúdo fora do $fillable (ver SavedSearch): atribuição
            // explícita, e só o allowlist de filtros — nunca o array cru.
            $search = new SavedSearch;
            $search->user_id = $user->getKey();
            $search->name = $name;
            $search->filters = Arr::only($filters, self::allowedFilterKeys());
            $search->save();

            return $search;
        });
    }

    /**
     * As buscas salvas do membro, como arrays prontos para a tela — a fonte única
     * consumida pelo endpoint de listagem E pela prop do catálogo, para as duas
     * não divergirem. `user_id` nunca sai (é chave interna; o $hidden do model é
     * a segunda barreira). Mais recente primeiro.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFor(User $user): array
    {
        return $this->collectionFor($user)
            ->map(fn (SavedSearch $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'filters' => $s->filters,
            ])
            ->all();
    }

    /**
     * Apaga uma busca do membro. 404 (não 403) para uma busca que não é dele:
     * indistinguível de inexistente, para o par de respostas não virar oráculo —
     * o escopo por `user_id` faz o `firstOrFail` errar do lado seguro.
     */
    public function deleteFor(User $user, int $id): void
    {
        SavedSearch::query()
            ->where('user_id', $user->getKey())
            ->whereKey($id)
            ->firstOrFail()
            ->delete();
    }

    /** @return Collection<int, SavedSearch> */
    private function collectionFor(User $user): Collection
    {
        return SavedSearch::query()
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->get();
    }
}
