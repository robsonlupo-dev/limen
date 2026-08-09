<?php

namespace App\Http\Requests\Auth;

use App\Rules\CaptchaValid;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedido de código OTP — compartilhado pelas DUAS portas (web e API), como o
 * LoginRequest. Numa rota web a falha de validação vira Inertia/redirect; em
 * `api/*` vira 422 JSON automaticamente (shouldRenderJsonWhen). O captcha entra
 * aqui uma vez e vale nas duas portas de request — pôr num controller deixaria a
 * outra aberta.
 *
 * Só valida a FORMA do e-mail. A existência da conta NÃO é checada aqui de
 * propósito: revelar "e-mail inválido para nossa base" seria enumeração. Quem
 * responde igual para existente e inexistente é o OtpService.
 */
class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            // No-op enquanto CAPTCHA_PROVIDER=none — ver CaptchaValid::rules().
            CaptchaValid::FIELD => CaptchaValid::rules(),
        ];
    }

    public function messages(): array
    {
        return CaptchaValid::messages();
    }
}
