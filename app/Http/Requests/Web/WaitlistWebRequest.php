<?php

namespace App\Http\Requests\Web;

use App\Models\PerformerProfile;
use App\Rules\NotDisposableEmailDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WaitlistWebRequest extends FormRequest
{
    /** The four official worlds (single source of truth: PerformerProfile::WORLDS). */
    private const WORLDS = PerformerProfile::WORLDS;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Normalize the email so idempotency (unique email+role) is not defeated
        // by casing/whitespace variations.
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * Two-step signup, validated per role on the server — never trusting the
     * client wizard. Performer fields (world, performer_kind) are prohibited for
     * members and vice-versa, so a hand-crafted POST cannot forge the other
     * role's data (e.g. slip a world onto a member, or omit the required world
     * on a performer).
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', new NotDisposableEmailDomain],
            'role' => ['required', 'in:performer,member'],
            'age_confirmed' => ['required', 'accepted'],

            // Performer: one world they represent, required only for performers.
            'world' => [
                'prohibited_unless:role,performer',
                'required_if:role,performer',
                Rule::in(self::WORLDS),
            ],
            // Performer + Mundo Casais: "performer = dois" — solo/casal is required
            // when the world is casais, and only ever meaningful for performers.
            'performer_kind' => [
                'prohibited_unless:role,performer',
                'required_if:world,casais',
                'nullable',
                'in:solo,casal',
            ],

            // Member: the (private, multiple) worlds they want to hear from.
            // prohibited_unless (whitelist) mirrors the performer fields: any role
            // other than member — including missing/invalid — is barred, not just
            // an explicit performer. max:4 fails a hostile oversized array early.
            'world_preferences' => ['prohibited_unless:role,member', 'nullable', 'array', 'max:4'],
            'world_preferences.*' => ['distinct', Rule::in(self::WORLDS)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe seu nome.',
            'email.required' => 'Informe seu e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'role.required' => 'Escolha se você quer entrar como membro ou performer.',
            'role.in' => 'Escolha se você quer entrar como membro ou performer.',
            'age_confirmed.required' => 'Você precisa confirmar que tem 18 anos ou mais.',
            'age_confirmed.accepted' => 'Você precisa confirmar que tem 18 anos ou mais.',
            'world.required_if' => 'Escolha o mundo que você representa.',
            'world.in' => 'Escolha um mundo válido.',
            'performer_kind.required_if' => 'Escolha se é performer solo ou casal.',
            'performer_kind.in' => 'Escolha solo ou casal.',
            'world_preferences.*.in' => 'Escolha mundos válidos.',
        ];
    }
}
