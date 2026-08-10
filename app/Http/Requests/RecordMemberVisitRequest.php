<?php

namespace App\Http\Requests;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Http\Requests\Web\Concerns\ResolvesCatalogMember;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro de VISITA da performer ao perfil de um membro (visitas bidirecionais,
 * A.0.4). O alvo é resolvido contra os membros que o catálogo mostraria AGORA a
 * esta performer (ResolvesCatalogMember) — a MESMA fonte da lista e das outras
 * ações (coração, mensagem, interesse). Assim o par 404/sucesso não vira oráculo
 * de quem a lista esconde, e uma visita nunca é gravada para um membro que a
 * performer não podia ver.
 */
class RecordMemberVisitRequest extends FormRequest
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
