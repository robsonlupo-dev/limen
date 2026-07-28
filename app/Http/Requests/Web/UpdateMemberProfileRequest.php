<?php

namespace App\Http\Requests\Web;

use App\Models\PerformerProfile;
use App\Models\User;
use App\Rules\NoProhibitedOffer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Perfil do membro (Sprint 9): interesses + "o que estou buscando".
 *
 * Só a porta web — o front-end do Limen fala com rotas web (sessão + CSRF), e
 * não existe hoje endpoint de API para este perfil. Quando existir, é ESTE
 * request que ele usa: a lição do `documents.accepted` (gate que fecha uma
 * porta só não é gate) vale igual para validação.
 */
class UpdateMemberProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // O conjunto válido é o da PERFORMER, não uma lista própria: o
            // interesse do membro só vale se casar com a tag da performer no
            // cruzamento de afinidade. Ver o cabeçalho de MemberInterest.
            //
            // `distinct` porque a junção tem índice único em (user_id,
            // tag_slug): sem ele, dois "fitness" no mesmo POST viravam
            // Duplicate entry (500) em vez de erro de validação. `max` conta
            // ANTES do distinct, então 9 repetidos já param aqui.
            'interests' => ['sometimes', 'nullable', 'array', 'max:'.User::MAX_INTERESTS],
            'interests.*' => ['string', 'distinct', Rule::in(PerformerProfile::allTags())],

            // NoProhibitedOffer aqui NÃO é pelo mesmo motivo da bio da
            // performer. Lá o argumento é alcance: a bio sai numa página
            // pública indexável. `seeking` não é publicado em lugar nenhum — a
            // razão aqui é o destino do campo. Ele existe para alimentar o
            // cruzamento de afinidade do Sprint 10, e uma oferta de encontro
            // mediante pagamento escrita nele viraria critério de PAREAMENTO:
            // a plataforma passaria a aproximar quem oferece de quem procura.
            // Isso é pior do que publicá-la.
            //
            // Só TIPO 1, como no perfil da performer: conduta fica de fora
            // porque num campo sobre si mesmo não há alvo.
            //
            // Teto igual ao do `looking_for` da performer — é um parágrafo,
            // não uma segunda biografia.
            'seeking' => ['sometimes', 'nullable', 'string', 'max:1000', new NoProhibitedOffer],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'interests.max' => 'Escolha no máximo '.User::MAX_INTERESTS.' interesses.',
            'interests.*.in' => 'Um dos interesses escolhidos não existe.',
            'interests.*.distinct' => 'O mesmo interesse foi enviado duas vezes.',
        ];
    }
}
