<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PPV no chat (Onda 4): a performer manda uma peça do cofre TRAVADA com um preço. Só
 * valida o INPUT (id da peça + preço). A autorização real (é a dona da conversa? a
 * peça é dela e está pronta? preço no piso/passo/teto?) vive no ChatService — o lugar
 * dela, não a validação de request. FailsValidationAsJson porque o envio é fetch/JSON.
 */
class StorePpvMessageRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true; // policy `view` no controller + regra no ChatService
    }

    public function rules(): array
    {
        return [
            'content_id' => ['required', 'integer', 'min:1'],
            'price_tokens' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'content_id.required' => 'Escolha um conteúdo para enviar.',
            'price_tokens.required' => 'Defina um preço em tokens.',
        ];
    }
}
