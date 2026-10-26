<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Support\BrazilPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opt-in de WhatsApp na tela de pós-cadastro (feat/signup-email-gate-and-whatsapp-optin).
 * Rota WEB consumida por fetch do Inertia → usa FailsValidationAsJson (422 JSON, não
 * redirect-com-erros-de-sessão; ver a nota do CLAUDE.md sobre as duas portas de auth).
 *
 * `consent` é obrigatório e precisa ser verdadeiro: sem a caixa marcada (desmarcada por
 * padrão) não há base legal para gravar o canal — a regra de aceite vive aqui, no
 * servidor, nunca só no checkbox do front.
 */
class WhatsappOptInRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'whatsapp' => ['required', 'string', 'max:32'],
            // accepted = "1", "true", "on", "yes", true. Caixa desmarcada reprova.
            'consent' => ['required', 'accepted'],
        ];
    }

    public function withValidator($validator): void
    {
        // Número precisa ser um celular brasileiro plausível (normaliza para E.164).
        $validator->after(function ($validator) {
            $raw = (string) $this->input('whatsapp', '');
            if ($raw !== '' && BrazilPhone::toE164($raw) === null) {
                $validator->errors()->add('whatsapp', 'Informe um número de celular válido com DDD.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'consent.accepted' => 'É preciso aceitar receber avisos por WhatsApp para continuar.',
            'whatsapp.required' => 'Informe seu número de WhatsApp.',
        ];
    }
}
