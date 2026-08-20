<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PerformerEarningsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gate real (performer-active) fica na rota/controller. Aqui só validação
        // dos filtros de leitura.
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', 'string', Rule::in(['all', 'tip', 'chat', 'content', 'gift', 'call', 'live'])],
        ];
    }
}
