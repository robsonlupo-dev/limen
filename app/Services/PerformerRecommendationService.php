<?php

namespace App\Services;

use App\Models\ContentSave;
use App\Models\Favorite;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Parecidas com quem você salvou" (roadmap Fatal Model, Onda A).
 *
 * Recomendação por afinidade, DERIVADA e só-leitura. As SEMENTES são as
 * performers que o membro FAVORITOU e as donas das peças que ele SALVOU; a
 * afinidade é mundo + tags em comum. É o MEMBRO vendo sugestões para si: a
 * performer recomendada nunca sabe que foi recomendada nem para quem, e nada do
 * que ele salvou/favoritou é exposto — mesma disciplina de anonimato do
 * Favorito e do Salvos. Sem tabela nova, sem persistência: recalcula na leitura.
 */
class PerformerRecommendationService
{
    /** Teto de sementes lidas de cada fonte (caminho quente da listagem). */
    private const SEED_CAP = 60;

    /**
     * @return Collection<int, PerformerProfile>
     */
    public function similarToSaved(?User $viewer, int $limit = 9): Collection
    {
        if ($viewer === null) {
            return collect();
        }

        $seedIds = $this->seedProfileIds($viewer);

        if ($seedIds === []) {
            return collect();
        }

        $seeds = PerformerProfile::with('tags')
            ->whereIn('id', $seedIds)
            ->get(['id', 'category', 'worlds']);

        $seedTags = $seeds
            ->flatMap(fn (PerformerProfile $p) => $p->tags->pluck('tag_slug'))
            ->unique()->values()->all();

        $seedWorlds = $seeds
            ->flatMap(fn (PerformerProfile $p) => $p->worlds ?: [$p->category])
            ->filter()->unique()->values()->all();

        if ($seedWorlds === [] && $seedTags === []) {
            return collect();
        }

        $query = PerformerProfile::publicCatalog()
            ->whereIn('category', PerformerCatalogService::PUBLIC_WORLDS)
            // Nunca recomendar quem ele já salvou/favoritou (as sementes).
            ->whereNotIn('performer_profiles.id', $seedIds)
            // Pontuação de afinidade: quantas tags em comum. (Lista vazia nunca
            // casa — o placeholder evita um whereIn([]) que o MySQL recusa.)
            ->withCount(['tags as shared_tags' => fn (Builder $q) => $q->whereIn('tag_slug', $seedTags ?: ['__sem_tag__'])]);

        // Entra quem tem tag em comum OU está num mundo semente.
        $query->where(function (Builder $q) use ($seedTags, $seedWorlds) {
            if ($seedTags !== []) {
                $q->whereHas('tags', fn (Builder $t) => $t->whereIn('tag_slug', $seedTags));
            }
            foreach ($seedWorlds as $world) {
                $q->orWhere(fn (Builder $inner) => $inner->inWorld($world));
            }
        });

        // Mais tags em comum primeiro; o boost/live/atividade do scopePublicCatalog
        // seguem como primários e desempatam dentro de cada nível de afinidade.
        $query->orderByDesc('shared_tags');

        return $query->limit($limit)->get();
    }

    /**
     * Ids das performers semente: favoritadas pelo membro + donas das peças que
     * ele salvou. Teto por fonte para não varrer um histórico grande.
     *
     * @return list<int>
     */
    private function seedProfileIds(User $viewer): array
    {
        $favorited = Favorite::where('user_id', $viewer->id)
            ->latest('id')->limit(self::SEED_CAP)
            ->pluck('performer_profile_id')->all();

        $saved = ContentSave::query()
            ->where('content_saves.user_id', $viewer->id)
            ->join('performer_content', 'performer_content.id', '=', 'content_saves.performer_content_id')
            ->latest('content_saves.id')->limit(self::SEED_CAP)
            ->pluck('performer_content.performer_profile_id')->all();

        return collect($favorited)->merge($saved)
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
