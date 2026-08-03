<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Toggle público/privado de uma foto da galeria (Sprint 13).
 *
 * Rota WEB consumida por `fetch`: FailsValidationAsJson para o erro sair 422 JSON
 * e não redirect com erros de sessão (convenção das duas portas de auth). A
 * propriedade da foto é checada no PhotoAccessService, não aqui.
 */
class TogglePhotoVisibilityRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true; // role/2fa/documents.accepted/performer-active são gate de rota; a posse é do service.
    }

    public function rules(): array
    {
        return [
            // Explícito, não inversão: o cliente diz o estado desejado, o que torna
            // duplo clique / retry inofensivo (a foto fica no estado enviado).
            'is_private' => ['required', 'boolean'],
        ];
    }
}
