<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Abertura/edição do fã-clube da performer (Onda 4). Validação BÁSICA de tipo/limites; a
 * regra autoritativa (piso/passo/teto e público ≥ VIP) é do FanclubService/TokenCreditPolicy
 * — o controller traduz a FanclubException em erro de sessão. Rota WEB (Inertia → redirect),
 * então os erros voltam pela sessão, sem precisar de JSON.
 */
class SaveFanclubSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_open' => ['required', 'boolean'],
            'price_public_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000', 'required_if:is_open,true'],
            'vip_enabled' => ['required', 'boolean'],
            'price_vip_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000', 'required_if:vip_enabled,true'],
        ];
    }

    public function messages(): array
    {
        return [
            'price_public_tokens.required_if' => 'Defina o preço público para abrir o fã-clube.',
            'price_vip_tokens.required_if' => 'Defina o preço VIP ou desligue a opção.',
        ];
    }
}
