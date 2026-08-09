<?php

namespace App\Http\Requests\Web\Concerns;

use App\Models\User;
use App\Services\MemberCatalogService;
use App\Support\FanAlias;

/**
 * Resolve o `member_handle` de uma ação do catálogo de membros (interesse,
 * coração, mensagem) contra EXATAMENTE os membros que o catálogo mostraria a
 * esta performer.
 *
 * Fonte única da resolução, pela razão travada no CLAUDE.md: a lista e a ação
 * têm que concordar. `MemberCatalogService::visibleMemberIds()` alimenta as duas,
 * então tudo o que esconde alguém do catálogo (invisível/`visible_to_performers`,
 * Modo Discreto, conta não verificada ou inativa) o esconde também aqui, sem
 * código a mais — e o par 404/sucesso nunca vira oráculo de quem a lista esconde.
 *
 * Uma cópia divergente da resolução por porta (interesse, coração, mensagem) era
 * exatamente o que abriria a brecha no sentido permissivo; por isso as três
 * portas passam por aqui.
 */
trait ResolvesCatalogMember
{
    protected function resolveCatalogMember(string $handle): User
    {
        $profile = $this->user()->performerProfile;

        // Só performer VERIFICADA age (regra do PO, como as outras portas). 404 e
        // não 403: indistinguível das demais recusas.
        abort_unless($profile && $profile->is_verified, 404);

        $memberId = FanAlias::resolveHandle(
            $profile->id,
            app(MemberCatalogService::class)->visibleMemberIds(),
            $handle,
        );

        abort_unless($memberId, 404);

        // Reconfere o estado da conta neste POST (banido/encerrado entre o render
        // e o clique). Mesmo 404.
        return User::where('id', $memberId)
            ->where('role', 'consumer')
            ->where('status', 'active')
            ->firstOrFail();
    }
}
