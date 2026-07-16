<?php

namespace App\Http\Requests\Web;

use App\Models\PerformerProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterWebRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Derive the account role from the `tipo` picker (see /entrada). Only
     * `consumer` and `performer` are ever accepted here — `admin` can never be
     * created through public registration.
     */
    protected function prepareForValidation(): void
    {
        $tipo = $this->input('tipo', $this->input('role'));

        $this->merge([
            'role' => $tipo === 'performer' ? 'performer' : 'consumer',
        ]);
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
                'before_or_equal:' . now()->subYears(18)->format('Y-m-d'),
            ],
            'accept_terms' => ['required', 'accepted'],
            'lgpd_consent' => ['required', 'accepted'],

            'role' => ['required', 'in:consumer,performer'],

            // Performer-only fields.
            'stage_name' => array_merge(
                ['required_if:role,performer', 'nullable'],
                PerformerProfile::stageNameRules(),
            ),
            'category' => ['required_if:role,performer', 'nullable', Rule::in(PerformerProfile::WORLDS)],

            // Member-only "world" preference. Optional server-side (defaults to
            // "mulheres" in the catalog), required in the UI.
            'preferred_world' => ['nullable', Rule::in(PerformerProfile::WORLDS)],
        ];
    }

    public function messages(): array
    {
        return [
            'birthdate.before_or_equal' => 'Você precisa ter pelo menos 18 anos para se cadastrar.',
            'password.regex' => 'A senha deve conter ao menos uma letra maiúscula e um número.',
            'accept_terms.accepted' => 'Você deve aceitar os termos de uso.',
            'lgpd_consent.accepted' => 'Você deve consentir com o tratamento de dados (LGPD).',
            'stage_name.required_if' => 'Informe seu nome artístico.',
            'category.required_if' => 'Selecione o mundo que você representa.',
        ];
    }
}
