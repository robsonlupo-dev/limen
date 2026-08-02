<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Services\PerformerCatalogService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Salvar uma busca do catálogo (Sprint 12).
 *
 * `FailsValidationAsJson` porque o Vue consome esta resposta com `fetch` numa
 * rota WEB — sem o trait, uma ValidationException viraria redirect-com-erros-de-
 * sessão e o front receberia HTML (convenção das duas portas de auth, CLAUDE.md).
 *
 * ── Os filtros são validados pela MESMA fonte do catálogo ────────────────────
 * `filters.*` reusa `PerformerCatalogService::filterRules()` — a dona única das
 * facetas — prefixada com `filters.`. Salvar não pode aceitar uma faceta que o
 * catálogo recusa, senão a busca salva reaplicaria algo que o filtro rejeita.
 * O que o Form Request valida é o TIPO de cada chave conhecida; o descarte de
 * chaves DESCONHECIDAS é do `SavedSearchService` (Arr::only), para o JSON nunca
 * virar blob arbitrário. O TETO de 10 NÃO é validado aqui: conta o estado atual
 * do membro e precisa de lock — vive no service.
 */
class StoreSavedSearchRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            // `min:1`: uma busca sem nenhuma faceta não tem o que reaplicar — o
            // botão da tela só aparece com filtros ativos, e o servidor cobra o
            // mesmo. Array vazio falha aqui.
            'filters' => ['required', 'array', 'min:1'],
        ];

        // As regras de cada faceta, prefixadas — a dona é o PerformerCatalogService.
        foreach (PerformerCatalogService::filterRules() as $key => $rule) {
            $rules["filters.{$key}"] = $rule;
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Dê um nome para a busca.',
            'name.max' => 'O nome da busca é muito longo (máximo 100 caracteres).',
            'filters.required' => 'Salve uma busca com ao menos um filtro ativo.',
            'filters.min' => 'Salve uma busca com ao menos um filtro ativo.',
        ];
    }
}
