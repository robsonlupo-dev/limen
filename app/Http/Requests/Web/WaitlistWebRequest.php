<?php

namespace App\Http\Requests\Web;

use App\Rules\NotDisposableEmailDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class WaitlistWebRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Normalize the email so idempotency (unique email+role) is not defeated
        // by casing/whitespace variations.
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', new NotDisposableEmailDomain],
            'role' => ['required', 'in:performer,member'],
            'world' => ['nullable', 'in:mulheres,homens,casais,trans,gls,swing'],
            'age_confirmed' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe seu nome.',
            'email.required' => 'Informe seu e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'role.required' => 'Escolha se você quer entrar como membro ou performer.',
            'role.in' => 'Escolha se você quer entrar como membro ou performer.',
            'age_confirmed.required' => 'Você precisa confirmar que tem 18 anos ou mais.',
            'age_confirmed.accepted' => 'Você precisa confirmar que tem 18 anos ou mais.',
        ];
    }
}
