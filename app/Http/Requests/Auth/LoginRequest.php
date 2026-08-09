<?php

namespace App\Http\Requests\Auth;

use App\Rules\CaptchaValid;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Compartilhado pelas DUAS portas de login: Web\Auth\LoginController (sessão) e
 * Api\V1\Auth\LoginController (Sanctum). Por isso o captcha entra aqui uma vez
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
            // No-op enquanto CAPTCHA_PROVIDER=none — ver CaptchaValid::rules().
            CaptchaValid::FIELD => CaptchaValid::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CaptchaValid::messages();
    }
}
