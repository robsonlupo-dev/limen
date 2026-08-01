<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\ResolvesMemberNoteTarget;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE de uma nota privada da performer sobre um membro (Sprint 11).
 *
 * Sem corpo: o alvo é o `member_handle` da rota, resolvido pelo mesmo trait do
 * PUT — a resolução do handle é a autorização, e mantê-la num lugar só impede
 * que as duas portas divirjam. O serviço trata a idempotência (apagar o que não
 * existe é no-op).
 */
class DeleteMemberNoteRequest extends FormRequest
{
    use ResolvesMemberNoteTarget;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
