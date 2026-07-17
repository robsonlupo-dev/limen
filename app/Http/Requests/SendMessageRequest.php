<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorização real (participante / performer dona) fica na policy e no
        // gate da rota. Aqui é só validação de input.
        return true;
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
