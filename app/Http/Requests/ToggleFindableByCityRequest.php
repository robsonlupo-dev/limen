<?php

namespace App\Http\Requests;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Toggle do opt-in "encontrável por cidade" (filtro de cidade consentido).
 * Espelha o ToggleAvailabilityRequest: `findable` opcional (ausente = inverte
 * o estado atual), sempre booleano.
 *
 * Rota WEB consumida por `patchJson` — usa FailsValidationAsJson para a
 * ValidationException virar 422 JSON (fora de api/*, o default vira redirect).
 */
class ToggleFindableByCityRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        // Autorização real (performer ativa) é do middleware role:performer +
        // gate performer-active na rota; aqui só valida o payload.
        return true;
    }

    public function rules(): array
    {
        return [
            'findable' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Valor desejado, ou null quando o campo não veio (o controller inverte).
     */
    public function desiredValue(): ?bool
    {
        return $this->has('findable') ? $this->boolean('findable') : null;
    }
}
