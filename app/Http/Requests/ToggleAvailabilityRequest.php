<?php

namespace App\Http\Requests;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Toggle de "Disponível para conversa" (Sprint 11).
 *
 * Rota WEB consumida pelo `patchJson` do dashboard — usa FailsValidationAsJson
 * para o erro de validação sair 422 JSON, não redirect com erros de sessão
 * (convenção das duas portas de auth, ver o trait).
 */
class ToggleAvailabilityRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true; // role/2fa/documents.accepted são gate de rota; o dono é resolvido no controller.
    }

    public function rules(): array
    {
        return [
            // Opcional: sem ele, inverte o estado atual. Com ele, o cliente diz
            // o estado que quer — o que torna o duplo clique / retry inofensivo,
            // mesmo padrão do ToggleDiscreteModeRequest.
            'available' => ['sometimes', 'boolean'],
        ];
    }

    /** O estado desejado, ou null para inverter o atual. */
    public function desiredValue(): ?bool
    {
        return $this->has('available') ? $this->boolean('available') : null;
    }
}
