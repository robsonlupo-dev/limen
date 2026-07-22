<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dados do cartão para assinar um Círculo. Os campos sensíveis (card_number,
 * card_cvv) NUNCA são persistidos localmente — vão direto para o Asaas, que
 * tokeniza. Eles também estão no dontFlash global (bootstrap/app.php), então
 * jamais voltam para a sessão/log num erro de validação.
 */
class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'consumer';
    }

    protected function prepareForValidation(): void
    {
        // Aceita o número/CVV com espaços ou máscara e normaliza para dígitos.
        $this->merge([
            'card_number' => preg_replace('/\D/', '', (string) $this->input('card_number')),
            'card_cvv' => preg_replace('/\D/', '', (string) $this->input('card_cvv')),
        ]);
    }

    public function rules(): array
    {
        $year = (int) date('Y');

        return [
            'circle_slug' => ['required', 'string', Rule::exists('circles', 'slug')->where('active', true)],
            'card_number' => ['required', 'string', 'regex:/^\d{13,19}$/'],
            'card_holder' => ['required', 'string', 'max:255'],
            'card_expiry_month' => ['required', 'integer', 'between:1,12'],
            'card_expiry_year' => ['required', 'integer', "between:{$year},".($year + 20)],
            'card_cvv' => ['required', 'string', 'regex:/^\d{3,4}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'card_number.regex' => 'Número de cartão inválido.',
            'card_cvv.regex' => 'CVV inválido.',
            'card_expiry_month.between' => 'Mês de validade inválido.',
            'card_expiry_year.between' => 'Ano de validade inválido.',
        ];
    }

    /** Payload de cartão no formato esperado pelo SubscriptionService::subscribe. */
    public function cardData(): array
    {
        return [
            'holderName' => $this->validated('card_holder'),
            'number' => $this->validated('card_number'),
            'expiryMonth' => (string) $this->validated('card_expiry_month'),
            'expiryYear' => (string) $this->validated('card_expiry_year'),
            'ccv' => $this->validated('card_cvv'),
            'holder' => [
                'name' => $this->validated('card_holder'),
                'email' => $this->user()->email,
            ],
        ];
    }
}
