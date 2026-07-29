<?php

namespace App\Http\Requests\Web\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Erro de validação em 422 JSON numa rota WEB.
 *
 * É a consequência direta da convenção das duas portas de auth (CLAUDE.md):
 * `shouldRenderJsonWhen` só liga o JSON em `api/*`, então fora de lá uma
 * `ValidationException` vira redirect-com-erros-de-sessão — mesmo com
 * `Accept: application/json` no request. Para um formulário Inertia isso é o
 * comportamento certo; para os endpoints que o front consome com `fetch`, não:
 * o `postJson`/`postForm` do `resources/js/lib/http.js` faz `response.json()` e
 * receberia um 302 seguido de HTML.
 *
 * Use este trait no Form Request de rota web cuja resposta o JavaScript lê.
 * O formato é o mesmo do Laravel em `api/*` (`message` + `errors`), para que o
 * front não precise saber por qual porta a resposta veio.
 */
trait FailsValidationAsJson
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
