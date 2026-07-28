<?php

namespace App\Http\Requests;

use App\Rules\CpfValido;
use App\Rules\HCaptchaValid;
use Illuminate\Foundation\Http\FormRequest;

class RegisterConsumerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required', 'string', 'confirmed', 'min:8',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
            ],
            'birthdate' => [
                'required', 'date', 'before_or_equal:today',
                'before_or_equal:'.now()->subYears(18)->format('Y-m-d'),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            // Obrigatório desde a verificação de maioridade do Sprint 6 — antes
            // era opcional e não era persistido em lugar nenhum. Continua não
            // sendo: só o HMAC vai para `age_verifications`.
            'cpf' => ['required', 'string', new CpfValido],
            'accept_terms' => ['required', 'accepted'],
            'lgpd_consent' => ['required', 'accepted'],
            'terms_version' => ['required', 'string', 'max:20'],

            // hCaptcha. Herdado por RegisterPerformerRequest, então as DUAS
            // portas de cadastro da API v1 ficam cobertas de uma vez. No-op com
            // HCAPTCHA_ENABLED=false.
            HCaptchaValid::FIELD => HCaptchaValid::rules(),
        ];
    }

    public function messages(): array
    {
        return array_merge(HCaptchaValid::messages(), [
            'birthdate.before_or_equal' => 'You must be at least 18 years old to register.',
            'password.regex' => 'Password must contain at least one uppercase letter and one number.',
            'accept_terms.accepted' => 'You must accept the terms of service.',
            'lgpd_consent.accepted' => 'You must consent to the LGPD data processing terms.',
        ]);
    }
}
