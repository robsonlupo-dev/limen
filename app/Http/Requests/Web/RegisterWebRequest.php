<?php

namespace App\Http\Requests\Web;

use App\Models\PerformerProfile;
use App\Rules\CpfValido;
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

        // Multi-worlds: a performer may pick more than one world. The primary
        // `category` (kept for compatibility) is always derived server-side from
        // the first selected world — never trusted from a separate field — so
        // the two can't disagree. Old callers that still post only `category`
        // keep working: no `worlds` here means the fallback below leaves it.
        $worlds = $this->input('worlds');
        if (is_array($worlds) && $worlds !== []) {
            $worlds = array_values(array_unique($worlds));
            $this->merge([
                'worlds' => $worlds,
                'category' => $worlds[0],
            ]);
        }
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
                'before_or_equal:'.now()->subYears(18)->format('Y-m-d'),
            ],
            'accept_terms' => ['required', 'accepted'],
            'lgpd_consent' => ['required', 'accepted'],

            'role' => ['required', 'in:consumer,performer'],

            // Maioridade do membro (ECA Digital). Obrigatório só para membro:
            // a performer entrega o CPF no KYC, com documento e prova de vida,
            // e pedir aqui duplicaria a coleta de PII sem ganho.
            //
            // O CPF é validado e descartado — não existe coluna para ele. O que
            // sobra é o HMAC em `age_verifications` (App\Support\CpfHash).
            'cpf' => ['required_if:role,consumer', 'nullable', 'string', new CpfValido],

            // Performer-only fields.
            'stage_name' => array_merge(
                ['required_if:role,performer', 'nullable'],
                PerformerProfile::stageNameRules(),
            ),
            'category' => ['required_if:role,performer', 'nullable', Rule::in(PerformerProfile::WORLDS)],

            // Multi-worlds (performer). Optional at the schema level for
            // backward compatibility with callers that send only `category`;
            // when present it must be a non-empty list of known worlds. The
            // wizard always sends it — an empty array is a real error there.
            'worlds' => ['nullable', 'array', 'min:1'],
            'worlds.*' => [Rule::in(PerformerProfile::WORLDS)],

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
            'cpf.required_if' => 'Informe seu CPF para confirmarmos que você é maior de 18 anos.',
            'stage_name.required_if' => 'Informe seu nome artístico.',
            'category.required_if' => 'Selecione o mundo que você representa.',
            'worlds.min' => 'Selecione ao menos um mundo que você representa.',
            'worlds.*.in' => 'Um dos mundos selecionados é inválido.',
        ];
    }
}
