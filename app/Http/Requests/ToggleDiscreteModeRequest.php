<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ToggleDiscreteModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // elegibilidade é do DiscreteModeService (403 com mensagem própria)
    }

    public function rules(): array
    {
        return [
            // Opcional: sem ele, inverte. Com ele, o cliente diz o estado que
            // quer — que é o que torna o duplo clique / retry inofensivo.
            'discrete_mode' => ['sometimes', 'boolean'],
        ];
    }

    /** O estado desejado, ou null para inverter o atual. */
    public function desiredValue(): ?bool
    {
        return $this->has('discrete_mode') ? $this->boolean('discrete_mode') : null;
    }
}
