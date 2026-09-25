<?php

namespace App\Http\Requests;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Http\Requests\Web\Concerns\ResolvesCatalogMember;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MENSAGEM de catálogo a partir do catálogo de membros (feat/catalog-message-
 * templates). A performer NÃO envia texto livre: ela escolhe um MODELO
 * pré-cadastrado (`template_id`). O corpo é resolvido no servidor a partir do
 * modelo — fechando o vetor de fuga em que texto livre grátis levava contato para
 * fora da plataforma. Alvo resolvido contra os membros visíveis à performer
 * (ResolvesCatalogMember). Franquia diária e filtro de conteúdo vivem no
 * ChatService.
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
            // Só um modelo ATIVO. O corpo nunca vem do cliente — é lido do modelo
            // no servidor (anti-fuga: sem texto livre grátis).
            'template_id' => [
                'required', 'integer',
                'exists:chat_catalog_templates,id',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'template_id.required' => 'Escolha uma mensagem para enviar.',
            'template_id.exists' => 'Essa mensagem não está mais disponível.',
        ];
    }

    public function resolvedMember(): User
    {
        return $this->resolveCatalogMember((string) $this->validated('member_handle'));
    }
}
