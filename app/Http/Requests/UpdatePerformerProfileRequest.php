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
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Multi-worlds: a performer pode pertencer a mais de um mundo. Se
            // `worlds` vier, ele é a fonte da verdade e `category` é DERIVADA de
            // worlds[0] no servidor (controller) — nunca do request. `min:1`
            // quando presente: array vazio é escolha inválida, não "sem
            // alteração" (esse é o campo ausente). `category` continua aceito
            // para o caminho legado que ainda posta só ele.
            'worlds' => ['sometimes', 'nullable', 'array', 'min:1'],
            'worlds.*' => [Rule::in(PerformerProfile::WORLDS)],
            'category' => ['sometimes', 'required', Rule::in(PerformerProfile::WORLDS)],
            'work_modes' => ['sometimes', 'nullable', 'array'],
            'work_modes.*' => ['string', Rule::in(['live', 'video', 'chat', 'fotos', 'privado', 'exclusivo'])],
            'rate_public' => ['sometimes', 'required', 'integer', 'min:0'],
            'rate_private' => ['sometimes', 'required', 'integer', 'min:0'],
            'rate_camera' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }
}
