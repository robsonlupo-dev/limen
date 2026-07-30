<?php

namespace App\Http\Requests\Web;

use App\Support\LifestyleTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Estilo de Vida" do membro (Sprint 10) — endpoint DEDICADO.
 *
 * Separado do UpdateMemberProfileRequest, que cobre o resto da mesma tela, e a
 * separação não é arrumação: `lifestyle_tier` está fora do `$fillable` do User
 * (mesma disciplina do `discrete_mode`), então ele não pode entrar por um
 * `fill()` de formulário genérico nem por engano. Fora do $fillable + porta
 * própria é o par — só a primeira metade viraria um `forceFill` no meio de um
 * update de vários campos, que é a mass assignment de volta com outro nome.
 *
 * É também o único campo daquela tela que a PERFORMER vê, e uma porta própria
 * torna isso legível em code review: quem mexer aqui está mexendo no que sai
 * para terceiro.
 *
 * Só a porta web, como o request irmão — não existe endpoint de API para o
 * perfil do membro hoje. Quando existir, é ESTE request que ele usa (a lição do
 * `documents.accepted`: gate que fecha uma porta só não é gate).
 */
class UpdateLifestyleTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `required` e não `sometimes`: aqui o request TEM um assunto só, e
            // um POST sem o campo é requisição malformada, não "não mexe". O
            // valor de opt-out é explícito (LifestyleTier::NOT_DISCLOSED), então
            // voltar a não declarar é uma escolha que se posta — não a ausência
            // do campo.
            'lifestyle_tier' => ['required', 'string', Rule::in(LifestyleTier::acceptedValues())],
        ];
    }

    /** O valor já normalizado para a coluna: null quando o membro não declara. */
    public function tier(): ?string
    {
        return LifestyleTier::normalize($this->validated()['lifestyle_tier']);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lifestyle_tier.in' => 'Faixa de estilo de vida inválida.',
            'lifestyle_tier.required' => 'Escolha uma faixa de estilo de vida.',
        ];
    }
}
