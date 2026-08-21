<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A performer silencia o autor de uma mensagem da sua live (feat/live-room-console).
 * O alvo é o ID da MENSAGEM — nunca o member_id nem o handle do FanAlias: o servidor
 * resolve o autor a partir da mensagem (LiveChatService::mute), então o front nunca
 * precisa conhecer a identidade do membro. Rota WEB por fetch → FailsValidationAsJson.
 */
class MuteLiveViewerRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message_id' => ['required', 'integer'],
        ];
    }
}
