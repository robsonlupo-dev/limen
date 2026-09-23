<?php

namespace App\Http\Requests\Web;

use App\Models\ContentFlag;
use App\Rules\SafeProfileText;
use App\Services\ContentFlagService;
use App\Support\MemberProfileOptions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Perfil PÚBLICO do membro v2 (feat/member-profile-v2): bio, "o que busco" e
 * interesses públicos, cidade/UF, estado civil, altura e o opt-in da faixa
 * etária. TUDO opcional; TUDO visível à performer quando `profile_visible` está
 * ligado — por isso a validação é a fronteira de confiança (não confiar no input
 * cru), e a escrita no controller é forceFill de allowlist (fora do $fillable).
 *
 * Só a porta web hoje (o front fala com rotas web + CSRF). Quando existir API, é
 * ESTE request que ela reusa — a lição do `documents.accepted` (validação que
 * fecha uma porta só não é gate) vale igual aqui.
 *
 * `sometimes` em cada campo: a tela pode salvar um subconjunto. Presente-e-vazio
 * (string '' / array []) é limpeza deliberada; ausente é "não mexe" — o controller
 * distingue os dois por array_key_exists.
 */
class UpdateMemberPublicProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // "Sobre mim". Curta (400) + guarda de contato/conduta (SafeProfileText,
            // que reusa ChatContentFilter + config do apelido). nullable: '' apaga.
            'bio' => ['sometimes', 'nullable', 'string', 'max:400', new SafeProfileText],

            // "O que busco" público — lista controlada. `distinct` porque a
            // exibição não deve repetir; `max` conta ANTES do distinct.
            'public_seeking' => ['sometimes', 'nullable', 'array', 'max:'.MemberProfileOptions::MAX_SEEKING],
            'public_seeking.*' => ['string', 'distinct', Rule::in(MemberProfileOptions::seekingSlugs())],

            // Interesses públicos — lista controlada.
            'public_interests' => ['sometimes', 'nullable', 'array', 'max:'.MemberProfileOptions::MAX_INTERESTS],
            'public_interests.*' => ['string', 'distinct', Rule::in(MemberProfileOptions::interestSlugs())],

            // Título/headline — frase curta em itálico sob o apelido. Texto livre
            // curto + guarda de contato (SafeProfileText), como a bio.
            'headline' => ['sometimes', 'nullable', 'string', 'max:80', new SafeProfileText],

            // Cidade/UF (autocomplete IBGE — nome livre por baixo, como a
            // performer). A UF é validada contra as 27; a cidade é só tamanho.
            'profile_city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'profile_uf' => ['sometimes', 'nullable', 'string', 'size:2', Rule::in(self::UFS)],

            // 2ª e 3ª localização (opt-in) — mesmas regras da 1ª.
            'profile_city_2' => ['sometimes', 'nullable', 'string', 'max:120'],
            'profile_uf_2' => ['sometimes', 'nullable', 'string', 'size:2', Rule::in(self::UFS)],
            'profile_city_3' => ['sometimes', 'nullable', 'string', 'max:120'],
            'profile_uf_3' => ['sometimes', 'nullable', 'string', 'size:2', Rule::in(self::UFS)],

            // Detalhes — selects controlados (sem texto livre).
            'marital_status' => ['sometimes', 'nullable', 'string', Rule::in(MemberProfileOptions::maritalSlugs())],
            'height_cm' => ['sometimes', 'nullable', 'integer', Rule::in(MemberProfileOptions::heightValues())],
            'weight_kg' => ['sometimes', 'nullable', 'integer', Rule::in(MemberProfileOptions::weightValues())],
            'education' => ['sometimes', 'nullable', 'string', Rule::in(MemberProfileOptions::educationSlugs())],
            'occupation_area' => ['sometimes', 'nullable', 'string', Rule::in(MemberProfileOptions::occupationAreaSlugs())],
            'children' => ['sometimes', 'nullable', 'string', Rule::in(MemberProfileOptions::childrenSlugs())],
            'drinks' => ['sometimes', 'nullable', 'string', Rule::in(MemberProfileOptions::drinksSlugs())],
            'smokes' => ['sometimes', 'nullable', 'string', Rule::in(MemberProfileOptions::smokesSlugs())],
            'availability' => ['sometimes', 'nullable', 'string', Rule::in(MemberProfileOptions::availabilitySlugs())],

            // Opt-in da faixa etária (derivada do birthdate). Booleano.
            'show_age_band' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Sinaliza CONDUTA na bio (feat/flagged-content-more-sources, Fase 4c-b). A bio
     * abusiva já é rejeitada pelo SafeProfileText; aqui, além de rejeitar, a
     * tentativa entra na fila de reincidência do moderador — o mesmo tratamento do
     * chat. Roda no `after` (fora de qualquer transação), então o flag persiste
     * mesmo com a validação falhando. Só CONDUTA vira flag (recordFromText filtra);
     * contato/risco legal não.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function () {
            $user = $this->user();
            if ($user === null) {
                return;
            }

            // Bio e headline são texto livre — ambos entram na fila de reincidência
            // quando têm CONDUTA (recordFromText filtra: contato/risco legal não vira
            // flag). Mesmo tratamento do chat.
            foreach (['bio', 'headline'] as $field) {
                $text = $this->input($field);
                if (is_string($text) && trim($text) !== '') {
                    app(ContentFlagService::class)->recordFromText($user, ContentFlag::SOURCE_PROFILE_TEXT, $text);
                }
            }
        });
    }

    /**
     * Normaliza a UF para maiúscula ANTES da validação — o autocomplete emite
     * "sp"/"SP" conforme a base; a coluna e o Rule::in esperam maiúscula.
     */
    protected function prepareForValidation(): void
    {
        foreach (['profile_uf', 'profile_uf_2', 'profile_uf_3'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'public_seeking.max' => 'Escolha no máximo '.MemberProfileOptions::MAX_SEEKING.' opções.',
            'public_interests.max' => 'Escolha no máximo '.MemberProfileOptions::MAX_INTERESTS.' interesses.',
            'public_seeking.*.in' => 'Uma das opções escolhidas não existe.',
            'public_interests.*.in' => 'Um dos interesses escolhidos não existe.',
            'profile_uf.in' => 'UF inválida.',
            'height_cm.in' => 'Altura inválida.',
            'marital_status.in' => 'Estado civil inválido.',
        ];
    }

    /** As 27 unidades federativas do Brasil. */
    private const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
    ];
}
