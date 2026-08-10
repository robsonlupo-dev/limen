<?php

namespace App\Services;

use App\Exceptions\PerformerLocationException;
use App\Models\PerformerLocation;
use App\Models\PerformerProfile;
use App\Support\BrazilianCities;
use Illuminate\Support\Facades\DB;

/**
 * Dona única das localizações da performer (Sprint 13). Substitui as duas
 * colunas `state`/`city` do Sprint 9A por uma lista de até
 * PerformerProfile::MAX_LOCATIONS, uma primária.
 *
 * ── Um dono só para a escrita ────────────────────────────────────────────────
 * `setLocations()` é o ÚNICO caminho que grava localização — a tela dedicada
 * chama aqui, e o cache `state`/`city` de `performer_profiles` é sincronizado
 * AQUI, no mesmo passo. Duas portas escrevendo (a antiga `performer.profile.save`
 * e esta) divergiriam, e a divergência entre a tabela e o cache é exatamente o
 * tipo de buraco que o CLAUDE.md manda fechar com uma dona só — por isso o
 * `UpdatePerformerProfileRequest` deixou de aceitar `state`/`city`.
 *
 * ── As invariantes moram aqui, não só no Form Request ────────────────────────
 * O StoreLocationsRequest valida e devolve 422 antes de chegar aqui, mas as três
 * regras (teto, exatamente uma primária, sem duplicata) são reconferidas sob
 * lock — mesma disciplina do teto DURO do SavedSearchService: a próxima porta que
 * chamar o serviço (um comando, a API) não pode nascer sem elas.
 */
class PerformerLocationService
{
    /**
     * Substitui TODA a lista de localizações da performer, atômico e idempotente.
     *
     * `$locations` é uma lista de arrays `{state, city?, is_primary?}` já
     * validados pelo Form Request. Lista vazia é "não compartilhar localização":
     * apaga tudo e zera o cache — o par vazio do link "Não compartilhar".
     *
     * ── Por que delete+create sob lock, e não um diff ────────────────────────
     * A lista tem no máximo 3 itens: um diff (como o de tags) não paga o custo, e
     * a ordem/primária muda a cada save de qualquer modo. O lock na linha do
     * PERFIL (que sempre existe) serializa dois saves concorrentes da mesma
     * performer — sem ele, dois PUT simultâneos furariam o teto e poderiam gravar
     * duas primárias. Mesma âncora do teto do SavedSearchService (a linha do
     * `users`); aqui a linha natural é o próprio perfil.
     *
     * @param  array<int, array{state: string, city?: ?string, is_primary?: bool}>  $locations
     *
     * @throws PerformerLocationException teto, primária ou duplicata
     */
    public function setLocations(PerformerProfile $profile, array $locations): void
    {
        $locations = array_values($locations);

        if (count($locations) > PerformerProfile::MAX_LOCATIONS) {
            throw PerformerLocationException::limit();
        }

        $this->assertNoDuplicates($locations);

        if ($locations !== []) {
            $this->assertExactlyOnePrimary($locations);
        }

        DB::transaction(function () use ($profile, $locations) {
            // Trava a linha do perfil (sempre existe) para serializar saves
            // concorrentes da mesma performer.
            PerformerProfile::query()->whereKey($profile->getKey())->lockForUpdate()->first();

            $profile->locations()->delete();

            $primaryState = null;
            $primaryCity = null;

            foreach ($locations as $position => $location) {
                $isPrimary = (bool) ($location['is_primary'] ?? false);
                $city = $this->normalizeCity($location['city'] ?? null);

                $row = new PerformerLocation(['state' => $location['state'], 'city' => $city]);
                // Fora do $fillable: dono, primária e posição são autoridade do
                // servidor. A ordem em que a performer as mandou é a posição.
                $row->performer_profile_id = $profile->getKey();
                $row->is_primary = $isPrimary;
                $row->position = $position;
                // Chave de busca acento-insensível do filtro de cidade consentido.
                // Derivada aqui (não é dado de request) e igual à normalização do
                // BrazilianCities — o front normaliza do mesmo jeito antes de casar.
                $row->city_normalized = $city !== null ? BrazilianCities::normalize($city) : null;
                $row->save();

                if ($isPrimary) {
                    $primaryState = $location['state'];
                    $primaryCity = $city;
                }
            }

            // Sincroniza o CACHE da primária nas colunas do Sprint 9A. Por
            // forceFill/save direto — não é mass assignment de request, é o
            // serviço espelhando o que ele mesmo acabou de gravar. Lista vazia
            // zera os dois (o "Não compartilhar localização").
            $profile->forceFill(['state' => $primaryState, 'city' => $primaryCity])->save();

            // A relação em memória ficou velha: quem leu $profile->locations
            // antes do save veria a lista anterior no mesmo request.
            $profile->unsetRelation('locations');
            $profile->unsetRelation('primaryLocation');
        });
    }

    /**
     * A UF primária da performer, ou null. Lê o cache da coluna (mantido em
     * sincronia por setLocations) — não precisa tocar a tabela.
     */
    public function primaryState(PerformerProfile $profile): ?string
    {
        return $profile->state;
    }

    /**
     * `city` vazia vira null: string vazia no banco confundiria o índice único
     * (par (state,'') difere de (state,NULL)) e não é "cidade", é ausência.
     */
    private function normalizeCity(?string $city): ?string
    {
        $city = $city !== null ? trim($city) : null;

        return ($city === null || $city === '') ? null : $city;
    }

    /**
     * @param  array<int, array{state: string, city?: ?string, is_primary?: bool}>  $locations
     *
     * @throws PerformerLocationException
     */
    private function assertNoDuplicates(array $locations): void
    {
        $seen = [];

        foreach ($locations as $location) {
            $key = $location['state'].'|'.strtolower((string) $this->normalizeCity($location['city'] ?? null));

            if (isset($seen[$key])) {
                throw PerformerLocationException::duplicate();
            }

            $seen[$key] = true;
        }
    }

    /**
     * @param  array<int, array{state: string, city?: ?string, is_primary?: bool}>  $locations
     *
     * @throws PerformerLocationException
     */
    private function assertExactlyOnePrimary(array $locations): void
    {
        $primaries = array_filter($locations, fn ($l) => (bool) ($l['is_primary'] ?? false));

        if (count($primaries) !== 1) {
            throw PerformerLocationException::primary();
        }
    }
}
