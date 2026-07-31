<?php

namespace App\Services;

use App\Models\Favorite;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Favoritos do membro (Sprint 10) — dona única da regra.
 *
 * "Salvar para ver depois", e **privado**: a performer nunca sabe que foi
 * favoritada. Toda a assimetria com o follow está aqui e no model — este
 * serviço não tem, e não pode ganhar, um método que responda a partir do lado
 * dela ("quem me favoritou", "quantos me favoritaram"). Regra nova sobre
 * favorito entra neste arquivo; o dia em que uma segunda superfície precisar da
 * lista, ela pergunta aqui — é a mesma disciplina do
 * FollowerVisibilityService, e pelo mesmo motivo: duas cópias divergem, e a
 * divergência é o vazamento.
 *
 * Nada deste fluxo entra em `audit_logs`. Ver o cabeçalho de Favorite: aquela
 * tabela sobrevive ao Hard Delete com o IP em claro, então uma trilha de
 * favoritos seria a cópia permanente do mapa que o Hard Delete apaga.
 */
class FavoriteService
{
    /**
     * Adiciona ou remove — um clique no coração.
     *
     * @return bool o estado NOVO: true = favoritado, false = não favoritado.
     *
     * Sob transação com `lockForUpdate` porque o toggle é a operação mais
     * exposta a duplo-submit do produto: o coração fica num card de grid, e dois
     * cliques rápidos (ou dois requests em voo) chegariam juntos. Sem o lock, os
     * dois leem "não existe" e os dois tentam INSERT — o UNIQUE da migration
     * transforma o segundo num 500 em vez de num no-op.
     *
     * O catch do UNIQUE fica como segunda linha de defesa: o gap lock do InnoDB
     * serializa os dois caminhos, mas o resultado correto para "tentou criar o
     * que já existe" é o estado final (favoritado), nunca uma exceção subindo
     * até o membro. É este par — lock + catch — que faz o duplo-submit ser
     * idempotente em vez de duplicar linha ou explodir.
     *
     * O gate de "perfil no ar" mora AQUI, não no controller. Antes, quem barrava
     * um perfil suspenso ou soft-deletado era o `findBySlug()` do controller (via
     * `publicCatalog()`), e todo chamador direto do serviço nascia sem essa
     * proteção — bastava passar um `PerformerProfile` já resolvido (recarregado
     * com `withTrashed`, ou cuja conta suspendeu depois do load) para gravar um
     * favorito apontando para um perfil morto. `assertProfileIsLive()` fecha isso
     * na fonte: o gate deixou de depender de por qual porta o serviço foi
     * chamado.
     */
    public function toggle(User $user, PerformerProfile $profile): bool
    {
        $this->assertProfileIsLive($profile);

        return DB::transaction(function () use ($user, $profile) {
            $existing = Favorite::query()
                ->where('user_id', $user->id)
                ->where('performer_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->delete();

                return false;
            }

            try {
                // FKs fora do $fillable (ver Favorite): atribuição explícita, e
                // nunca um create() sobre array vindo de request.
                $favorite = new Favorite;
                $favorite->user_id = $user->id;
                $favorite->performer_profile_id = $profile->id;
                $favorite->save();
            } catch (UniqueConstraintViolationException) {
                // Corrida perdida: a outra requisição já criou a linha. O estado
                // pedido foi alcançado — devolver "favoritado" é a resposta
                // correta, não um erro.
            }

            return true;
        });
    }

    /**
     * O perfil precisa estar NO AR para receber favorito: a linha existe (não
     * está soft-deletada) E a conta da performer está `active`.
     *
     * Reconsulta pela chave em vez de confiar no objeto recebido — o `$profile`
     * pode ter sido carregado com `withTrashed`, ou a conta pode ter suspendido
     * entre o load e este ponto. O escopo global do SoftDeletes já exclui as
     * linhas com `deleted_at`, então um perfil soft-deletado nem aparece no
     * `exists()`; o `whereHas` cobre a suspensão da conta.
     *
     * Lança `ModelNotFoundException` — mesma resposta (404) do `findBySlug()`, de
     * modo que favoritar um perfil fora do ar é indistinguível de um slug que
     * nunca existiu, e o par não vira oráculo de existência.
     */
    private function assertProfileIsLive(PerformerProfile $profile): void
    {
        $isLive = PerformerProfile::query()
            ->whereKey($profile->getKey())
            ->whereHas('user', fn (Builder $q) => $q->where('status', 'active'))
            ->exists();

        if (! $isLive) {
            throw (new ModelNotFoundException)
                ->setModel(PerformerProfile::class, [$profile->getKey()]);
        }
    }

    public function isFavorited(?User $user, PerformerProfile $profile): bool
    {
        if ($user === null) {
            return false;
        }

        return Favorite::query()
            ->where('user_id', $user->id)
            ->where('performer_profile_id', $profile->id)
            ->exists();
    }

    /**
     * Quais destes perfis o membro já salvou — UMA query para a página inteira.
     *
     * Mesmo molde do `is_following` e do `has_unseen_stories` no
     * CatalogController: por card seria N+1 na tela mais visitada do produto.
     *
     * @param  array<int, int>  $profileIds
     * @return array<int, int>
     */
    public function favoritedProfileIds(?User $user, array $profileIds): array
    {
        if ($user === null || $profileIds === []) {
            return [];
        }

        return Favorite::query()
            ->where('user_id', $user->id)
            ->whereIn('performer_profile_id', $profileIds)
            ->pluck('performer_profile_id')
            ->all();
    }

    /**
     * A lista do membro, paginada, mais recentes primeiro.
     *
     * Passa por `publicCatalog()` como as três superfícies de listagem: uma
     * performer que saiu do ar (conta suspensa, perfil despublicado) some da
     * lista em vez de virar um card que leva a 404. O favorito em si continua
     * guardado — se ela voltar, o card volta —, o que evita apagar a escolha do
     * membro por um estado do lado dela que ele não controla.
     *
     * JOIN e não whereIn: a ordem é "salvo mais recentemente", que mora em
     * `favorites.created_at` e não existe do lado do perfil. Colunas
     * qualificadas porque as duas tabelas têm `id`, `user_id` e `created_at`.
     */
    public function paginateFor(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return PerformerProfile::query()
            ->publicCatalog()
            ->select('performer_profiles.*')
            ->join('favorites', 'favorites.performer_profile_id', '=', 'performer_profiles.id')
            ->where('favorites.user_id', $user->id)
            ->orderByDesc('favorites.created_at')
            // Desempate estável: vários favoritos salvos no mesmo segundo
            // embaralhariam entre páginas e um card apareceria duas vezes.
            ->orderByDesc('favorites.id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
