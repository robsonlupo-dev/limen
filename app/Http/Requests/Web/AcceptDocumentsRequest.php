<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Os dois checkboxes da tela de aceite.
 *
 * `accepted` (e não `boolean`): "false" explícito é recusa, e recusa não pode
 * passar pela validação e virar linha em `document_acceptances`.
 *
 * A VERSÃO aceita não vem daqui. Se o cliente pudesse mandá-la, bastaria
 * postar a versão antiga para satisfazer o middleware sem nunca ter visto o
 * texto novo — o servidor resolve a versão vigente pelo config.
 */
class AcceptDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'performer';
    }

    public function rules(): array
    {
        return [
            'content_policy' => ['required', 'accepted'],
            'performance_contract' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'content_policy.accepted' => 'É obrigatório aceitar a Política de Conteúdo Proibido.',
            'content_policy.required' => 'É obrigatório aceitar a Política de Conteúdo Proibido.',
            'performance_contract.accepted' => 'É obrigatório aceitar o Contrato de Performance.',
            'performance_contract.required' => 'É obrigatório aceitar o Contrato de Performance.',
        ];
    }
}
