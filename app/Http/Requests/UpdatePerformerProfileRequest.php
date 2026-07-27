<?php

namespace App\Http\Requests;

use App\Models\PerformerProfile;
use App\Rules\NoProhibitedOffer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePerformerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ignore() no próprio perfil: salvar sem trocar o nome não pode
            // colidir consigo mesmo.
            'stage_name' => array_merge(
                ['sometimes', 'required'],
                PerformerProfile::stageNameRules($this->user()?->performerProfile?->id),
            ),
            // NoProhibitedOffer: a bio é publicada em página pública indexável,
            // então oferta de encontro pago é barrada aqui como é no chat. Este
            // Form Request é compartilhado pelas TRÊS portas que escrevem o
            // perfil (edição web, onboarding e a API v1) — colocar a regra numa
            // só teria deixado as outras duas abertas.
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000', new NoProhibitedOffer],
            // Multi-worlds: a performer pode pertencer a mais de um mundo. Se
            // `worlds` vier, ele é a fonte da verdade e `category` é DERIVADA de
            // worlds[0] no servidor (controller) — nunca do request. `min:1`
            // quando presente: array vazio é escolha inválida, não "sem
            // alteração" (esse é o campo ausente). `category` continua aceito
            // para o caminho legado que ainda posta só ele.
            'worlds' => ['sometimes', 'nullable', 'array', 'min:1'],
            'worlds.*' => [Rule::in(PerformerProfile::WORLDS)],
            'category' => ['sometimes', 'required', Rule::in(PerformerProfile::WORLDS)],
            'work_modes' => ['sometimes', 'nullable', 'array'],
            'work_modes.*' => ['string', Rule::in(['live', 'video', 'chat', 'fotos', 'privado', 'exclusivo'])],
            'rate_public' => ['sometimes', 'required', 'integer', 'min:0'],
            'rate_private' => ['sometimes', 'required', 'integer', 'min:0'],
            'rate_camera' => ['sometimes', 'required', 'integer', 'min:0'],

            // "Sobre mim" (Sprint 9). Todos são `sometimes` + `nullable`: a tela
            // de edição manda o bloco inteiro, mas o onboarding e a API não —
            // ausente significa "não mexe", e presente-e-nulo significa "limpa".
            //
            // O conjunto válido de tags vem de PerformerProfile::allTags(), não
            // de uma lista repetida aqui: a tela desenha da mesma constante, e
            // duas cópias divergiriam na primeira tag nova.
            //
            // `distinct` porque a junção tem índice único em
            // (performer_profile_id, tag_slug): sem ele, dois "fitness" no mesmo
            // POST viravam Duplicate entry (500) em vez de erro de validação.
            // `max` conta ANTES do distinct, então 9 repetidas já param aqui.
            'tags' => ['sometimes', 'nullable', 'array', 'max:'.PerformerProfile::MAX_TAGS],
            'tags.*' => ['string', 'distinct', Rule::in(PerformerProfile::allTags())],

            'languages' => ['sometimes', 'nullable', 'array', 'max:'.count(PerformerProfile::LANGUAGES)],
            'languages.*' => ['string', 'distinct', Rule::in(PerformerProfile::LANGUAGES)],

            'drinks' => ['sometimes', 'nullable', Rule::in(PerformerProfile::DRINKS)],
            'smokes' => ['sometimes', 'nullable', Rule::in(PerformerProfile::SMOKES)],

            'height_cm' => [
                'sometimes', 'nullable', 'integer',
                'min:'.PerformerProfile::HEIGHT_MIN_CM,
                'max:'.PerformerProfile::HEIGHT_MAX_CM,
            ],

            // Texto livre exibido no perfil PÚBLICO, como a bio. Teto menor que o
            // da bio de propósito: é um parágrafo, não uma segunda biografia.
            'looking_for' => ['sometimes', 'nullable', 'string', 'max:1000', new NoProhibitedOffer],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tags.max' => 'Escolha no máximo '.PerformerProfile::MAX_TAGS.' tags.',
            'tags.*.in' => 'Uma das tags escolhidas não existe.',
            'tags.*.distinct' => 'A mesma tag foi enviada duas vezes.',
            'languages.*.in' => 'Um dos idiomas escolhidos não existe.',
            'height_cm.min' => 'A altura deve ficar entre '.PerformerProfile::HEIGHT_MIN_CM.' e '.PerformerProfile::HEIGHT_MAX_CM.' cm.',
            'height_cm.max' => 'A altura deve ficar entre '.PerformerProfile::HEIGHT_MIN_CM.' e '.PerformerProfile::HEIGHT_MAX_CM.' cm.',
        ];
    }
}
