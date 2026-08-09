<?php

namespace App\Http\Requests;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Http\Requests\Web\Concerns\ResolvesCatalogMember;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * CORAÇÃO a partir do catálogo de membros. O alvo é resolvido contra os membros
 * que o catálogo mostraria AGORA a esta performer (ResolvesCatalogMember) — a
 * lista e a ação concordam por construção.
 */
class SendHeartRequest extends FormRequest
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
            // Handle opaco (16 hex), nunca o id: o id do membro não chega ao
            // front da performer, então não volta dele.
            'member_handle' => ['required', 'string'],
        ];
    }

    public function resolvedMember(): User
    {
        return $this->resolveCatalogMember((string) $this->validated('member_handle'));
    }
}
