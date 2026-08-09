<?php

namespace App\Http\Requests;

use App\Http\Requests\Web\Concerns\ResolvesCatalogMember;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Interesse Controlado a partir do CATÁLOGO DE MEMBROS (Sprint 16).
 *
 * Terceira porta, ao lado de [[SendInterestRequest]] (seguidores) e
 * [[SendInterestToVisitorRequest]] (visitantes). Rota/Request próprios pela mesma
 * razão travada no CLAUDE.md: a origem decide contra QUAL conjunto o handle é
 * resolvido, e deixar essa escolha num `source` do payload permitiria pedir
 * "catálogo" e cair no predicado de outra tela. Aqui o handle é resolvido contra
 * exatamente os membros que o catálogo mostraria — a lista e o envio concordam
 * por construção (MemberCatalogService é a fonte única), então o par 404/201 não
 * vira oráculo de quem a lista esconde.
 */
class SendInterestFromCatalogRequest extends FormRequest
{
    use ResolvesCatalogMember;

    public function authorize(): bool
    {
        return true; // a regra de alvo mora em resolvedMember(); performer-active é da rota
    }

    public function rules(): array
    {
        return [
            // Handle opaco (16 hex, App\Support\FanAlias), nunca o id: o id do
            // membro não chega ao front da performer, então não volta dele.
            'member_handle' => ['required', 'string'],
        ];
    }

    /**
     * O alvo precisa ser um membro que apareça AGORA no catálogo desta performer.
     * Tudo o que esconde alguém do catálogo esconde-o também aqui, sem código a
     * mais, porque a fonte é a mesma (MemberCatalogService::visibleMemberIds):
     * invisível (visible_to_performers), Modo Discreto, conta não verificada ou
     * inativa — nenhum resolve, e o 404 é o mesmo de um handle inventado.
     */
    public function resolvedMember(): User
    {
        return $this->resolveCatalogMember((string) $this->validated('member_handle'));
    }
}
