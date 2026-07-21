<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Código do segundo fator: TOTP de 6 dígitos ou recovery code.
 *
 * A validação aqui é só de FORMA (tipo e tamanho, para não jogar lixo no
 * comparador). Se o código CONFERE é decisão do TwoFactorService — o Form
 * Request não tem como saber, e espalhar a checagem em regra de validação
 * criaria a segunda dona da regra que o service existe para evitar.
 *
 * O limite superior de 21 é o formato do recovery code (10 + '-' + 10). Sem
 * ele, um POST de 1 MB no campo entraria no laço de comparação.
 */
class TwoFactorCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // a decisão é do service; o controller devolve o erro.
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:6', 'max:21'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Informe o código do seu aplicativo autenticador.',
            'code.min' => 'Código inválido.',
            'code.max' => 'Código inválido.',
        ];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }
}
