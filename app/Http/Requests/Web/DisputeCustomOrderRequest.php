<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Rules\SafeProfileText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Contestação de uma entrega de encomenda sob medida (Onda 4 §4.3) — lado do MEMBRO.
 *
 * O motivo é OBRIGATÓRIO: sem ele a disputa virava "recusou e sumiu" e a performer saía
 * perdendo sem o moderador saber o que houve (apontado pelo PO no UAT). Piso de tamanho
 * para barrar "." / "ruim" que não informam nada; passa pelo mesmo filtro anti-contato
 * (SafeProfileText) dos outros textos livres — o membro não troca contato aqui.
 *
 * A autorização (é o membro dono da encomenda, no estado `delivered` dentro da janela)
 * fica no CustomOrderService, como o resto das transições.
 */
class DisputeCustomOrderRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza ANTES de validar: sem isso o `min`/`max` mediria a string crua e um
     * "ruim      " (4 letras + espaços) passaria no piso de 10 e seria gravado como
     * "ruim" — justamente o relato vazio que o piso existe para barrar.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('motivo'))) {
            $this->merge(['motivo' => trim($this->input('motivo'))]);
        }
    }

    public function rules(): array
    {
        return [
            'motivo' => [
                'required',
                'string',
                'min:'.(int) config('custom_order.dispute_reason_min_length', 10),
                'max:'.(int) config('custom_order.dispute_reason_max_length', 500),
                new SafeProfileText,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Conte o que houve com a entrega para a moderação analisar.',
            'motivo.min' => 'Explique um pouco melhor o problema (mínimo de :min caracteres).',
            'motivo.max' => 'O motivo é muito longo.',
        ];
    }
}
