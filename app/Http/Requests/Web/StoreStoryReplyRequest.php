<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RESPOSTA a um story da performer (feat/story-reply-to-chat). Estilo Insta: o
 * membro responde em cima do story e essa resposta vira a 1ª mensagem do chat —
 * entra na economia (1º envio abre/paga a janela paga) via
 * ChatService::memberSendToPerformer, carimbada com o story respondido.
 *
 * Só valida o INPUT (o corpo). O story vem por route-model-binding e a
 * visibilidade (quem pode ver aquele story) é checada no controller com o
 * StoryVisibilityService — validação de request não é o lugar da autorização.
 * FailsValidationAsJson porque o envio é fetch/JSON (o compositor do story).
 */
class StoreStoryReplyRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true; // gate de rota (role:consumer + verificado) e visibilidade no controller
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.(int) config('chat.max_length')],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'A mensagem não pode ficar vazia.',
            'body.max' => 'A mensagem excede o tamanho máximo de :max caracteres.',
        ];
    }
}
