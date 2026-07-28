<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\ProfileVisitService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Interesse Controlado a partir do PAINEL DE VISITANTES (Sprint 9).
 *
 * Gêmeo do [[SendInterestRequest]], que resolve pela lista de seguidores. São
 * dois Form Requests e não um com `source` no payload de propósito: a origem
 * decide contra QUAL conjunto o handle é resolvido, e um único request com um
 * branch faria a escolha do resolvedor depender de um campo controlado pelo
 * cliente. Com duas portas, cada rota carrega a sua regra e não há como pedir
 * a resolução de visitante e cair no predicado de seguidores.
 *
 * A invariante que governa esta classe é a mesma do irmão, e é a regra travada
 * no CLAUDE.md: **a tela e o envio têm que concordar.** Se o envio aceitasse um
 * alvo que o painel esconde, o par 404/201 viraria oráculo para reconstruir o
 * que o k-anonimato e os pisos removem. Aqui a concordância é por construção —
 * quem resolve é ProfileVisitService::resolveVisitorHandle(), que consome
 * exatamente as mesmas linhas que o painel renderiza.
 */
class SendInterestToVisitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Handle opaco (App\Support\FanAlias), nunca o id: o id do membro
            // não chega ao front da performer, então também não volta dele.
            'member_handle' => ['required', 'string'],
        ];
    }

    /**
     * O alvo precisa ser um membro (consumer) ativo que apareça AGORA no painel
     * de visitantes desta performer.
     *
     * Tudo o que esconde alguém do painel esconde-o também aqui, sem uma linha
     * de código a mais, porque a fonte é a mesma:
     *
     *  - Ghost Mode e Modo Discreto nunca geraram linha em `profile_visits`
     *    (ProfileVisitService::record) — não há o que resolver, e a ausência de
     *    linha É o produto que o perk vende;
     *  - os dois pisos (seguidores e visitantes distintos elegíveis) travam o
     *    painel inteiro;
     *  - o k-anonimato por faixa remove a faixa incompleta;
     *  - o corte em `$limit` remove quem não coube na tela.
     *
     * 404 em todos os casos, e o mesmo 404 de um handle inventado: distinguir
     * "não existe" de "existe mas está escondido" devolveria o oráculo.
     */
    public function resolvedMember(): User
    {
        $profile = $this->user()->performerProfile;

        // Regra 1 do PO: só performer VERIFICADA envia por esta via. O 404 (e
        // não 403) mantém a resposta indistinguível das demais recusas — dizer
        // "seria possível se você fosse verificada" não vaza nada sobre o
        // membro, mas manter uma resposta só é mais barato do que auditar,
        // a cada mudança, se a nova mensagem passou a vazar.
        abort_unless($profile && $profile->is_verified, 404);

        $memberId = app(ProfileVisitService::class)->resolveVisitorHandle(
            $profile,
            (string) $this->validated('member_handle'),
        );

        abort_unless($memberId, 404);

        // O estado da conta é reconferido aqui, e não só na montagem do painel:
        // entre o render da tela e este POST o membro pode ter sido banido ou
        // ter encerrado a conta. `firstOrFail` devolve o mesmo 404.
        return User::where('id', $memberId)
            ->where('role', 'consumer')
            ->where('status', 'active')
            ->firstOrFail();
    }
}
