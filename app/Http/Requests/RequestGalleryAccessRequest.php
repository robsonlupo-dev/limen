<?php

namespace App\Http\Requests;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Http\Requests\Web\Concerns\ResolvesCatalogMember;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A performer SOLICITA acesso às fotos privadas de um membro
 * (feat/member-gallery-access-requests). O alvo é resolvido contra os membros que
 * o catálogo mostraria AGORA a esta performer (ResolvesCatalogMember) — a lista e
 * a ação concordam por construção, e o par 404/sucesso não vira oráculo.
 */
class RequestGalleryAccessRequest extends FormRequest
{
    use FailsValidationAsJson;
    use ResolvesCatalogMember;

    public function authorize(): bool
    {
        return true; // alvo em resolvedMember(); performer-active é da rota
    }

    public function rules(): array
    {
        return [
            // Handle opaco (16 hex), nunca o id do membro.
            'member_handle' => ['required', 'string'],
        ];
    }

    public function resolvedMember(): User
    {
        return $this->resolveCatalogMember((string) $this->validated('member_handle'));
    }
}
