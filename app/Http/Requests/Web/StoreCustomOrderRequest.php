<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Rules\SafeProfileText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedido de encomenda sob medida (Onda 4 §4.3). O membro descreve o que quer (texto
 * PÚBLICO para a performer — passa pelo filtro anti-contato SafeProfileText) e oferece
 * um valor. A autorização e a regra de preço/limites vivem no CustomOrderService.
 */
class StoreCustomOrderRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:'.(int) config('custom_order.description_max_length', 500), new SafeProfileText],
            'price_tokens' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Descreva o que você quer na encomenda.',
            'price_tokens.required' => 'Ofereça um valor em tokens.',
        ];
    }
}
