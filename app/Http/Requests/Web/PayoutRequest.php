<?php

namespace App\Http\Requests\Web;

use App\Rules\CpfValido;
use Illuminate\Foundation\Http\FormRequest;

class PayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tokens' => ['required', 'integer', 'min:'.config('monetization.payout.min_tokens'), 'max:'.config('monetization.payout.max_tokens')],
            'pix_key_type' => ['required', 'string', 'in:cpf,email,phone,random'],
            'pix_key' => ['required', 'string', 'max:255', match ($this->input('pix_key_type')) {
                'cpf' => new CpfValido,
                'email' => 'email:rfc',
                'phone' => 'regex:/^\+?[0-9\s\-\(\)]{10,15}$/',
                default => 'string',
            }],
        ];
    }
}
