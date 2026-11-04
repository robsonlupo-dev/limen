<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Criação de link personalizado de indicação (feat/referral-custom-links).
 * Rota WEB por fetch → FailsValidationAsJson (422 JSON). A validação PROFUNDA
 * (formato, teto, reservadas, personificação, unicidade) é dona única do
 * ReferralLinkService — aqui só o básico do payload.
 */
class ReferralLinkRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.required' => 'Digite o link que você quer criar.',
        ];
    }
}
