<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenChatAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorização (participante / dono) fica no controller. Aqui só validação.
        return true;
    }

    public function rules(): array
    {
        return [
            // Idempotência do open/renew — o cliente gera um uuid por clique.
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
