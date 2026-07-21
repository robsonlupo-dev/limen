<?php

namespace App\Http\Requests;

use App\Services\PrivacyPerkService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TogglePrivacyPerkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // elegibilidade é do PrivacyPerkService (403 com mensagem própria)
    }

    public function rules(): array
    {
        return [
            // Allowlist fechada nos três perks conhecidos. Sem ela, o nome do
            // campo viria do request e viraria escrita arbitrária em coluna de
            // `users` — o service ainda barraria, mas a validação é a primeira
            // porta e é onde isto tem que morrer.
            'perk' => ['required', Rule::in(PrivacyPerkService::PERKS)],
            // Obrigatório, e não "inverte se ausente": o cliente diz o estado
            // que quer, e é isso que torna duplo clique / retry inofensivos.
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function perk(): string
    {
        return $this->validated('perk');
    }

    public function desiredValue(): bool
    {
        // Do payload VALIDADO, não do input cru: uma fonte só, e é a que passou
        // pela regra `boolean`.
        return filter_var($this->validated('enabled'), FILTER_VALIDATE_BOOLEAN);
    }
}
