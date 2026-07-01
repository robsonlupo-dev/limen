<?php

namespace App\Http\Requests\Web;

use App\Rules\CpfValido;
use Illuminate\Foundation\Http\FormRequest;

class WalletPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $needsCpf = ! $this->user()?->asaas_customer_id;

        return [
            'cpf' => $needsCpf
                ? ['required', 'string', new CpfValido]
                : ['nullable'],
        ];
    }
}
