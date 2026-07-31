<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Verificação do código OTP — compartilhado por web e API (ver RequestOtpRequest).
 *
 * Sem hCaptcha: o captcha protege o ENVIO (que dispara e-mail e é o vetor de
 * flood/enumeração). A verificação já é contida pelos 5 palpites por código e
 * pelo throttle por IP na rota. Valida só a forma — 6 dígitos —; se o código não
 * bate, o OtpService devolve a mesma resposta genérica de qualquer falha.
 *
 * ── O campo `email` NÃO tem a mesma autoridade nas duas portas ───────────────
 * Na porta API o e-mail vem do corpo (não há sessão onde guardá-lo entre os dois
 * passos). Na porta WEB o controller IGNORA este campo e usa o e-mail da SESSÃO
 * (OtpLoginController::verify) — para um POST forjado não trocar o alvo da
 * verificação. Aqui ele segue `required` porque a API depende dele; um refactor
 * que fizer o controller web voltar a lê-lo daqui reabre esse buraco, e o teste
 * "verify uses the session email, not the (forgeable) request body" existe para
 * pegar exatamente isso.
 */
class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            // string, não integer: 000123 é um código válido, e como int perderia
            // os zeros à esquerda. `digits:6` garante 6 CARACTERES NUMÉRICOS —
            // recusa cedo "abcdef" (que com `size:6` chegaria ao hash_equals e
            // queimaria um palpite à toa).
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Digite o código de 6 dígitos.',
            'code.digits' => 'O código tem 6 dígitos.',
        ];
    }
}
