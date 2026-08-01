<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Http\Requests\Web\Concerns\ResolvesMemberNoteTarget;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT de uma nota privada da performer sobre um membro (Sprint 11).
 *
 * O alvo vem no `member_handle` da ROTA (handle opaco do FanAlias), nunca o id
 * — resolvido pelo trait contra os seguidores/visitantes que a performer vê. O
 * corpo traz só o texto.
 *
 * `FailsValidationAsJson` porque o front consome com `fetch` (putJson): numa
 * rota web, sem o trait, uma ValidationException viraria redirect + HTML e o
 * `response.json()` do cliente quebraria (CLAUDE.md, § das duas portas de auth).
 */
class SaveMemberNoteRequest extends FormRequest
{
    use FailsValidationAsJson;
    use ResolvesMemberNoteTarget;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Nota não-vazia: esvaziar o campo e salvar é DELETE, não um PUT com
            // string vazia (o front faz essa escolha). O teto de 5.000 cabe
            // folgado no `text` cifrado — ver a migration.
            'content' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Escreva algo para salvar a nota.',
            'content.max' => 'A nota pode ter no máximo 5.000 caracteres.',
        ];
    }
}
