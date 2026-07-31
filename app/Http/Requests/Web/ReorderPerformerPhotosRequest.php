<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reordenação da galeria (Sprint 10): a nova ordem das fotos, como lista de ids.
 *
 * Aqui só a FORMA é validada (lista não vazia de inteiros). Quem confere que os
 * ids são EXATAMENTE os da galeria da performer — nem faltando, nem sobrando,
 * nem de outra pessoa — é o PerformerPhotoService::reorder(), que tem o conjunto
 * real à mão sob transação. Validar propriedade aqui seria uma segunda cópia da
 * regra, e a divergência é o vazamento.
 *
 * `FailsValidationAsJson` porque o Vue lê a resposta com `fetch`.
 */
class ReorderPerformerPhotosRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Envie a nova ordem das fotos.',
            'ids.array' => 'Ordem inválida.',
        ];
    }
}
