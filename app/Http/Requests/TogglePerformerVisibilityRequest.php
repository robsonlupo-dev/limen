<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Toggle "Visível para performers" (Sprint 16). O membro diz o estado que quer;
 * a escrita é a escolha EXPLÍCITA do tri-state (a partir daqui a coluna nunca
 * mais é `null` para este membro). Sem elegibilidade por tier: qualquer membro
 * pode ligar/desligar a própria visibilidade.
 */
class TogglePerformerVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate é a rota (role:consumer + member.verified, auth+2fa)
    }

    public function rules(): array
    {
        return [
            // Obrigatório, não "inverte se ausente": o cliente manda o estado
            // desejado, o que torna duplo clique / retry inofensivos.
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function desiredValue(): bool
    {
        return filter_var($this->validated('enabled'), FILTER_VALIDATE_BOOLEAN);
    }
}
