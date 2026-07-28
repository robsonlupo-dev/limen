<?php

namespace App\Http\Requests\Auth;

use App\Rules\HCaptchaValid;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Compartilhado pelas DUAS portas de login: Web\Auth\LoginController (sessão) e
 * Api\V1\Auth\LoginController (Sanctum). Por isso o hCaptcha entra aqui uma vez
 * só e vale nas duas — colocá-lo num controller teria deixado a outra aberta.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // No-op enquanto HCAPTCHA_ENABLED=false — ver HCaptchaValid::rules().
            HCaptchaValid::FIELD => HCaptchaValid::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return HCaptchaValid::messages();
    }
}
