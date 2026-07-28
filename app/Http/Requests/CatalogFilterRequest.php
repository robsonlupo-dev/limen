<?php

namespace App\Http\Requests;

use App\Models\PerformerProfile;
use App\Services\PerformerCatalogService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros do catálogo de performers (Sprint 9).
 *
 * Compartilhado pelo catálogo autenticado e pelo público — as duas telas
 * oferecem as mesmas facetas, e um Form Request por porta divergiria na
 * primeira faceta nova. As regras vivem em
 * PerformerCatalogService::filterRules(), que a API também consome.
 *
 * `authorize()` devolve true: não há o que autorizar num filtro de listagem
 * pública. O recorte de segurança (só ativa + verificada + com slug) é do
 * `publicCatalog()`, não daqui, e nenhuma faceta consegue afrouxá-lo.
 *
 * PRIVACIDADE: isto é o MEMBRO filtrando PERFORMERS — a direção segura. Nada
 * do membro entra na query nem sai na resposta. A direção inversa (performer
 * filtrando membro) não existe e não deve passar a existir por aqui.
 */
class CatalogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return PerformerCatalogService::filterRules();
    }

    /**
     * As facetas normalizadas, prontas para applyFilters().
     *
     * `boolean()` e não o valor cru: o checkbox chega como '1'/'0'/'true' e o
     * `! empty('0')` do service leria a string '0' como verdadeira.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'search' => $this->input('search'),
            'sort' => $this->input('sort'),
            'level' => $this->input('level'),
            'is_live' => $this->boolean('is_live'),
            'has_photo' => $this->boolean('has_photo'),
            'tier' => $this->input('tier'),
            'tags' => (array) $this->input('tags', []),
            'languages' => (array) $this->input('languages', []),
            'drinks' => $this->input('drinks'),
            'smokes' => $this->input('smokes'),
            // Inteiro ou null — o service decide se a faixa foi estreitada, e
            // '' vindo de um slider intocado não pode virar 0 no cast.
            'height_min' => $this->filled('height_min') ? (int) $this->input('height_min') : null,
            'height_max' => $this->filled('height_max') ? (int) $this->input('height_max') : null,
        ];
    }

    /**
     * O que volta para a tela repopular os controles. É o mesmo array dos
     * filtros mais a faixa de altura resolvida: o slider precisa de número nos
     * dois extremos para renderizar, e null viraria 0 no `v-model`.
     *
     * @return array<string, mixed>
     */
    public function filterState(): array
    {
        $filters = $this->filters();

        return array_merge($filters, [
            'height_min' => $filters['height_min'] ?? PerformerProfile::HEIGHT_MIN_CM,
            'height_max' => $filters['height_max'] ?? PerformerProfile::HEIGHT_MAX_CM,
        ]);
    }
}
