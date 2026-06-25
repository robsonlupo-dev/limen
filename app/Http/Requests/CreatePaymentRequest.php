<?php

namespace App\Http\Requests;

use App\Rules\CpfValido;
use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $needsCpf = ! $this->user()?->asaas_customer_id;

        return [
            'token_package_id' => ['required', 'integer', 'exists:token_packages,id'],
            'cpf' => $needsCpf
                ? ['required', 'string', new CpfValido]
                : ['nullable'],
        ];
    }
}
