<?php

namespace App\Http\Requests\Web\Concerns;

use App\Models\User;
use App\Services\FollowerVisibilityService;
use App\Services\ProfileVisitService;
use App\Support\FanAlias;

/**
 * Resolve o `member_handle` da rota de notas → membro, contra os mesmos
 * conjuntos que a performer VÊ: seguidores listáveis e visitantes reveláveis.
 * É o padrão do Interesse (SendInterestRequest / SendInterestToVisitorRequest),
 * unido numa fonte só porque a nota pode ser sobre qualquer membro que a
 * performer alcança por qualquer das duas telas.
 *
 * A invariante é a mesma travada no CLAUDE.md: **a tela e a ação concordam.**
 * Se a nota aceitasse um alvo que nenhuma tela mostra, o par 404/200 viraria
 * oráculo para reconstruir o que o Piso de Anonimato e o k-anonimato escondem.
 * Aqui a concordância é por construção — resolvemos contra `listableQuery` e
 * `resolveVisitorHandle`, exatamente as linhas que FollowersController e o
 * painel de visitantes renderizam. Abaixo do piso NADA resolve (a lista e o
 * painel estão escondidos), então também não se anota — mesmo corte do Interesse.
 *
 * 404 uniforme em todos os modos de falha (handle inventado, membro discreto,
 * abaixo do piso, conta inativa): distinguir "não existe" de "existe mas está
 * escondido" devolveria o oráculo.
 */
trait ResolvesMemberNoteTarget
{
    public function resolvedMember(): User
    {
        $profile = $this->user()->performerProfile;

        abort_unless($profile !== null, 404);

        $handle = (string) $this->route('member_handle');

        // Seguidor listável primeiro; se não casar, visitante revelável. O
        // `resolveVisitorHandle` já devolve null quando o painel está travado
        // (qualquer dos dois pisos) ou a faixa não reúne o k — não há segunda
        // regra a manter em sincronia.
        $memberId = $this->resolveFollowerHandle($profile->id, $handle)
            ?? app(ProfileVisitService::class)->resolveVisitorHandle($profile, $handle);

        abort_unless($memberId !== null, 404);

        // Reconferido aqui, e não só na montagem da tela: entre o render e este
        // PUT/DELETE o membro pode ter sido banido ou encerrado a conta.
        return User::where('id', $memberId)
            ->where('role', 'consumer')
            ->where('status', 'active')
            ->firstOrFail();
    }

    /**
     * Handle → id do membro contra os seguidores LISTÁVEIS deste perfil, e só
     * quando a lista é revelável (Piso de Anonimato). Mesmo predicado e mesma
     * guarda do SendInterestRequest: abaixo do piso a performer não conhece a
     * lista, então o handle não resolve.
     */
    private function resolveFollowerHandle(int $profileId, string $handle): ?int
    {
        $visibility = app(FollowerVisibilityService::class);

        if (! $visibility->canRevealList($profileId)) {
            return null;
        }

        return FanAlias::resolveHandle(
            $profileId,
            $visibility->listableQuery($profileId)->pluck('user_id'),
            $handle,
        );
    }
}
