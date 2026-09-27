<?php

namespace App\Services;

use App\Exceptions\HighlightException;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\StoryHighlight;
use App\Models\StoryHighlightItem;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Destaques (Highlights) da performer (roadmap social, Onda 1a). Dona única:
 * criar coleção, adicionar/remover item, apagar coleção e montar as visões
 * (owner/pública) passam só por aqui.
 *
 * MVP: só stories PÚBLICOS entram (vitrine pública; o paywall dos stories fica
 * intacto). VIP em destaque vem junto com o PR de Stories VIP por tier.
 *
 * Ao adicionar, a mídia é COPIADA (o story some em 24h; o destaque é permanente):
 * os bytes vêm pela porta autorizada da dona (PerformerStoryService::readForOwner,
 * que confere propriedade + prazo) e são regravados no disco permanente pelo
 * HighlightStore (com re-scan CSAM).
 */
class StoryHighlightService
{
    public function __construct(
        private HighlightStore $store,
        private PerformerStoryService $stories,
    ) {}

    private function maxCollections(): int
    {
        return (int) config('stories.highlights.max_collections_per_performer', 10);
    }

    private function maxItems(): int
    {
        return (int) config('stories.highlights.max_items_per_collection', 30);
    }

    /** Cria uma coleção vazia. Respeita o teto de coleções. */
    public function createCollection(PerformerProfile $profile, string $title): StoryHighlight
    {
        if ($profile->highlights()->count() >= $this->maxCollections()) {
            throw HighlightException::collectionLimit($this->maxCollections());
        }

        $highlight = new StoryHighlight(['title' => trim($title)]);
        $highlight->forceFill([
            'performer_profile_id' => $profile->getKey(),
            'sort_order' => (int) $profile->highlights()->max('sort_order') + 1,
        ])->save();

        return $highlight;
    }

    /** Renomeia uma coleção da própria performer. */
    public function rename(PerformerProfile $profile, StoryHighlight $highlight, string $title): StoryHighlight
    {
        $this->assertOwnsCollection($profile, $highlight);
        $highlight->update(['title' => trim($title)]);

        return $highlight;
    }

    /**
     * Adiciona um story PÚBLICO da própria performer a uma coleção dela. Copia a
     * mídia para o disco permanente. Respeita o teto de itens.
     */
    public function addStory(PerformerProfile $profile, StoryHighlight $highlight, PerformerStory $story): StoryHighlightItem
    {
        $this->assertOwnsCollection($profile, $highlight);

        if ($story->performer_profile_id !== $profile->getKey()) {
            throw HighlightException::notOwner();
        }

        // MVP: só público vira destaque — mantém o paywall dos stories intacto.
        if ($story->visibility_level !== 'public') {
            throw HighlightException::notPublic();
        }

        if ($highlight->items()->count() >= $this->maxItems()) {
            throw HighlightException::itemLimit($this->maxItems());
        }

        // Bytes pela porta autorizada da dona (confere propriedade + prazo do story).
        $bytes = $this->stories->readForOwner($profile, $story);

        ['path' => $path, 'hash' => $hash] = $this->store->putBytes($bytes, $profile->getKey(), $profile->user);

        try {
            $item = new StoryHighlightItem(['visibility_level' => 'public']);
            $item->forceFill([
                'story_highlight_id' => $highlight->getKey(),
                'media_path' => $path,
                'content_hash' => $hash,
                'source_story_id' => $story->getKey(),
                'sort_order' => (int) $highlight->items()->max('sort_order') + 1,
            ])->save();

            Audit::log('highlight.item_added', $highlight, [
                'story_highlight_id' => $highlight->id,
                'source_story_id' => $story->id,
            ]);

            return $item;
        } catch (Throwable $e) {
            // Compensação: linha falhou → não deixa bytes órfãos.
            try {
                $this->store->delete($path);
            } catch (Throwable $cleanupFailed) {
                Log::warning('highlights: bytes órfãos após falha ao adicionar item', [
                    'exception' => $cleanupFailed->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /** Remove um item (bytes + linha). */
    public function removeItem(PerformerProfile $profile, StoryHighlightItem $item): void
    {
        $highlight = $item->highlight;
        if (! $highlight || $highlight->performer_profile_id !== $profile->getKey()) {
            throw HighlightException::notOwner();
        }

        $this->store->delete($item->media_path);
        $item->delete();
    }

    /** Apaga a coleção inteira: bytes de cada item + linhas. */
    public function deleteCollection(PerformerProfile $profile, StoryHighlight $highlight): void
    {
        $this->assertOwnsCollection($profile, $highlight);

        foreach ($highlight->items as $item) {
            // Falha de disco de UM item não deve deixar a coleção meio-apagada com
            // linhas vivas: apaga bytes best-effort e segue; a linha sai no cascade.
            try {
                $this->store->delete($item->media_path);
            } catch (Throwable $e) {
                Log::warning('highlights: falha ao apagar bytes de item ao remover coleção', [
                    'story_highlight_item_id' => $item->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        DB::transaction(function () use ($highlight) {
            $highlight->items()->delete();
            $highlight->delete();
        });
    }

    /**
     * Visão da própria performer (gerência): coleções com itens e a URL de imagem.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ownerView(PerformerProfile $profile): array
    {
        return $profile->highlights()->with('items')->get()
            ->map(fn (StoryHighlight $h) => [
                'id' => $h->id,
                'title' => $h->title,
                'items' => $h->items->map(fn (StoryHighlightItem $i) => [
                    'id' => $i->id,
                    'url' => route('highlights.image', $i->id),
                ])->values()->all(),
            ])->values()->all();
    }

    /**
     * Visão pública (perfil): coleções não-vazias, com capa (1º item) e itens.
     * Tudo é público (só stories públicos viram destaque), então tudo tem URL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function publicView(PerformerProfile $profile): array
    {
        return $profile->highlights()->with('items')->get()
            ->filter(fn (StoryHighlight $h) => $h->items->isNotEmpty())
            ->map(fn (StoryHighlight $h) => [
                'id' => $h->id,
                'title' => $h->title,
                'cover_url' => route('highlights.image', $h->items->first()->id),
                'items' => $h->items->map(fn (StoryHighlightItem $i) => [
                    'id' => $i->id,
                    'url' => route('highlights.image', $i->id),
                ])->values()->all(),
            ])->values()->all();
    }

    private function assertOwnsCollection(PerformerProfile $profile, StoryHighlight $highlight): void
    {
        if ($highlight->performer_profile_id !== $profile->getKey()) {
            throw HighlightException::notOwner();
        }
    }
}
