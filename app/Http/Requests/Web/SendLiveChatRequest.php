<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Uma mensagem do chat da live (feat/live-room-console). Rota WEB consumida por
 * fetch → FailsValidationAsJson (fora de api/*, a ValidationException viraria
 * redirect). O gate de papel/feature/sessão de live vive na rota e no serviço.
 *
 * `body` é o texto da mensagem — teto de 500 chars (mensagem de chat, não post).
 * O filtro de conteúdo (risco legal / conduta) roda no LiveChatService, não aqui.
 */
class SendLiveChatRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => trim((string) $this->input('body'))]);
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:500'],
        ];
    }
}
