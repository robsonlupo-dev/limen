<?php

namespace App\Http\Requests\Moderation;

use App\Models\PerformerVoiceIntro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Decisão do moderador sobre uma intro de voz (feat/voice-intro).
 *
 * A autorização de QUEM (moderator/admin) é do middleware `moderator.access` na
 * rota — aqui só valida o payload. Só dois destinos: `approved` / `rejected`
 * (`pending`/`processing`/`failed` são estados de máquina, não decisões humanas).
 * Motivo OBRIGATÓRIO na recusa (a performer precisa saber por quê para regravar).
 */
class ModerateVoiceIntroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                PerformerVoiceIntro::STATUS_APPROVED,
                PerformerVoiceIntro::STATUS_REJECTED,
            ])],
            'reject_reason' => ['nullable', 'required_if:status,'.PerformerVoiceIntro::STATUS_REJECTED, 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reject_reason.required_if' => 'Informe o motivo da recusa.',
        ];
    }
}
