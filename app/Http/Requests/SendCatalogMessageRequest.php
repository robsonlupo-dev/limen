<?php

namespace App\Http\Requests;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Http\Requests\Web\Concerns\ResolvesCatalogMember;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MENSAGEM PERSONALIZADA a partir do catálogo de membros. Alvo resolvido contra
 * os membros visíveis à performer (ResolvesCatalogMember). `body` valida como no
 * chat (mesmo teto de tamanho); a franquia diária e o filtro de conteúdo vivem no
 * ChatService, não aqui.
 */
class SendCatalogMessageRequest extends FormRequest
{
    use FailsValidationAsJson;
    use ResolvesCatalogMember;

    public function authorize(): bool
    {
        return true; // alvo em resolvedMember(); performer-active é da rota
    }

    public function rules(): array
    {
        return [
            'member_handle' => ['required', 'string'],
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

    public function resolvedMember(): User
    {
        return $this->resolveCatalogMember((string) $this->validated('member_handle'));
    }
}
