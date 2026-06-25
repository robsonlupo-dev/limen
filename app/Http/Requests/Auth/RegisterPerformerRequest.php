<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\RegisterConsumerRequest;

class RegisterPerformerRequest extends RegisterConsumerRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'stage_name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'in:mulheres,homens,casais,trans,gls,swing'],
        ]);
    }
}
