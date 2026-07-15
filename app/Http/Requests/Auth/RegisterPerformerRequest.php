<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\RegisterConsumerRequest;
use App\Models\PerformerProfile;
use App\Rules\CpfValido;

class RegisterPerformerRequest extends RegisterConsumerRequest
{
    public function rules(): array
    {
        $parent = parent::rules();
        // CPF is required for performers (overrides nullable from consumer)
        $parent['cpf'] = ['required', 'string', new CpfValido];

        return array_merge($parent, [
            'stage_name' => array_merge(['required'], PerformerProfile::stageNameRules()),
            'category' => ['nullable', 'string', 'in:mulheres,homens,casais,trans,gls,swing'],
        ]);
    }
}
