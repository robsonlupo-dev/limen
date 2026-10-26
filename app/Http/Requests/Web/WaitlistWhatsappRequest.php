<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Support\BrazilPhone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Opt-in de WhatsApp na tela de continuação da lista de espera
 * (feat/waitlist-continue-screen). Rota WEB por fetch do Inertia →
 * FailsValidationAsJson (422 JSON, não redirect). `consent` precisa ser aceito
 * (caixa desmarcada por padrão) — a base legal exige o aceite explícito.
 */
class WaitlistWhatsappRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        // Identificação é por sessão (a pessoa que acabou de entrar na lista);
        // o controller recusa se não houver registro na sessão.
        return true;
    }

    public function rules(): array
    {
        return [
            'whatsapp' => ['required', 'string', 'max:32'],
            'consent' => ['required', 'accepted'],
        ];
    }

    public function withValidator($validator): void
    {
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
