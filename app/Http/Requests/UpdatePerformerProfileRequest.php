<?php

namespace App\Http\Requests;

use App\Models\PerformerProfile;
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
            // ignore() no próprio perfil: salvar sem trocar o nome não pode
            // colidir consigo mesmo.
            'stage_name' => array_merge(
                ['sometimes', 'required'],
                PerformerProfile::stageNameRules($this->user()?->performerProfile?->id),
            ),
            'bio'        => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category'   => ['sometimes', 'required', Rule::in(PerformerProfile::WORLDS)],
            'work_modes'   => ['sometimes', 'nullable', 'array'],
            'work_modes.*' => ['string', Rule::in(['live', 'video', 'chat', 'fotos', 'privado', 'exclusivo'])],
            'rate_public'  => ['sometimes', 'required', 'integer', 'min:0'],
            'rate_private' => ['sometimes', 'required', 'integer', 'min:0'],
            'rate_camera'  => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }
}
