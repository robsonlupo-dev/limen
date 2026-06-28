<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePerformerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stage_name' => ['sometimes', 'required', 'string', 'max:255'],
            'bio'        => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category'   => ['sometimes', 'required', 'in:mulheres,homens,casais,trans,gls,swing'],
            'work_modes'   => ['sometimes', 'nullable', 'array'],
            'work_modes.*' => ['string', Rule::in(['live', 'video', 'chat', 'fotos', 'privado', 'exclusivo'])],
            'rate_public'  => ['sometimes', 'required', 'integer', 'min:0'],
            'rate_private' => ['sometimes', 'required', 'integer', 'min:0'],
            'rate_camera'  => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }
}
