<?php

namespace App\Http\Requests;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Services\CallChatService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Envio de uma mensagem de texto no chat da chamada 1:1 (feat/in-call-chat). Rota
 * WEB consumida por fetch → precisa do FailsValidationAsJson (senão a falha de
 * validação viraria redirect e o front receberia HTML).
 */
class SendCallMessageRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        // A autorização de PARTICIPANTE e de chamada-ativa é do controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.CallChatService::MAX_LENGTH],
        ];
    }
}
