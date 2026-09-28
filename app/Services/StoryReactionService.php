<?php

namespace App\Services;

use App\Exceptions\StoryException;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\StoryReaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Reações rápidas a stories (roadmap social, Onda 1b). Dona única da regra.
 *
 * ── A autorização é a MESMA do serving (§ 2.3) ──────────────────────────────
 * Reagir a um story que o membro não alcança seria um oráculo de existência (ele
 * confirmaria que o story está lá) e uma escrita no conteúdo pago de fora do
 * paywall. Por isso `react()` pergunta ao `StoryVisibilityService::denialFor`
 * ANTES de gravar — a mesma porta do `PerformerStoryService::readForMember()`,
 * nunca uma checagem paralela que divergiria no sentido permissivo.
 *
 * ── O nível `exclusive` não tem reação (decisão nº 3 do PO) ──────────────────
 * O que sai para a performer é AGREGADO (faixa + emojis). No exclusivo, qualquer
 * sinal de audiência prova que aqueles membros são Black/FC — é o mesmo oráculo
 * que tira o contador do Nível 3. Então o exclusivo não recebe reação: o serving
 * recusa (403) e a UI nem mostra a botoeira. O canal do exclusivo continua sendo
 * o "responder ao story" (chat pago), que é troca explícita de identidade.
 *
 * ── O que a performer vê é agregado, nunca "quem reagiu" ─────────────────────
 * `summaryForOwner` devolve a FAIXA de membros únicos (reusando
 * `PerformerProfile::followersLabelFor`, a dona da faixa) e o CONJUNTO de emojis
 * usados — jamais uma lista por membro. Anonimato do membro é piso (princípio 1);
 * uma lista por FanAlias é decisão de produto adiada, não o default.
 */
class StoryReactionService
{
    public function __construct(private StoryVisibilityService $visibility) {}

    /**
     * O membro reage a um story (ou troca/remove a própria reação).
     *
     * Toggle: a MESMA reação remove; outra troca; nenhuma cria. Devolve a reação
     * resultante, ou `null` quando removeu — é o estado que o front usa para
     * destacar o botão ativo.
     *
     * @throws StoryException vencido/fora do ar (404) ou sem alcance/exclusivo (403)
     * @throws InvalidArgumentException reação fora do conjunto (erro de chamador; o
     *                                  Form Request já recusa antes)
     */
    public function react(User $member, PerformerStory $story, string $reaction): ?string
    {
        // Mesma porta do serving: o motivo vem da regra, não daqui.
        $denial = $this->visibility->denialFor($story, $member);

        if ($denial === StoryException::EXPIRED) {
            throw StoryException::expired();
        }

        if ($denial !== null) {
            throw StoryException::forbidden();
        }

        // Exclusivo não tem superfície de audiência — ver o docblock da classe.
        if ($story->visibility_level === PerformerStory::VISIBILITY_EXCLUSIVE) {
            throw StoryException::forbidden();
        }

        if (! in_array($reaction, self::allowedReactions(), true)) {
            throw new InvalidArgumentException("Reação desconhecida: {$reaction}");
        }

        $existing = StoryReaction::query()
            ->where('performer_story_id', $story->getKey())
            ->where('member_id', $member->getKey())
            ->first();

        // ── Ghost Mode / Modo Discreto: o perk compra NÃO aparecer em contador
        // nenhum, nem agregado (§ 2.7 e a decisão nº 3, citada no viewCount). A
        // reação alimenta o agregado da performer, então — como a `story_view` — ela
        // NÃO É GRAVADA para quem tem o perk. Não gravar (em vez de gravar e filtrar
        // na leitura) é a disciplina do § 2.7: a linha oculta a um JOIN de distância
        // vira o vazamento exato que o perk vende no primeiro bug de query. Sem isso
        // haveria um oráculo: `reações > views` no mesmo story só pode vir de quem
        // viu sem ser contado — ou seja, o tier que comprou invisibilidade.
        //
        // Uma reação anterior (feita antes de ligar o perk) é REMOVIDA aqui: assim o
        // membro sai do agregado assim que interage já invisível. A resposta é a
        // mesma em forma (`null`); ele só deixa de deixar rastro, que é o que comprou
        // — mesma consequência aceita do "seen" que nunca acende para ele.
        if ($member->discrete_mode || $member->hasGhostMode()) {
            $existing?->delete();

            return null;
        }

        if ($existing !== null) {
            // Tocar a mesma reação desfaz; trocar atualiza a MESMA linha (o índice
            // único garante uma por par, então nunca há duas a reconciliar).
            if ($existing->reaction === $reaction) {
                $existing->delete();

                return null;
            }

            $existing->reaction = $reaction;
            $existing->save();

            return $reaction;
        }

        return $this->create($story, $member, $reaction);
    }

    /**
     * A reação ATUAL deste membro neste story, ou `null`. Dado do próprio membro —
     * é o que destaca o botão ativo no viewer.
     */
    public function currentFor(User $member, PerformerStory $story): ?string
    {
        return StoryReaction::query()
            ->where('performer_story_id', $story->getKey())
            ->where('member_id', $member->getKey())
            ->value('reaction');
    }

    /**
     * O agregado que a PERFORMER vê no painel, ou `null` quando não há o que
     * mostrar (nível exclusivo, ou nenhuma reação ainda).
     *
     * `null` e não zero, pela mesma razão do `viewCount()`: zero é um valor no
     * mesmo domínio da faixa e afirmaria algo falso; ausência é ausência. E o
     * exclusivo devolve `null` SEM sequer contar — é a regra da decisão nº 3.
     *
     * @return array{label: string, kinds: array<int, string>}|null
     */
    public function summaryForOwner(PerformerStory $story): ?array
    {
        if ($story->visibility_level === PerformerStory::VISIBILITY_EXCLUSIVE) {
            return null;
        }

        // `member_id` já é único por par (o índice), mas `DISTINCT` explícito
        // espelha `viewCount()` e deixa a intenção no código: membros ÚNICOS.
        $count = StoryReaction::query()
            ->where('performer_story_id', $story->getKey())
            ->distinct()
            ->count('member_id');

        if ($count === 0) {
            return null;
        }

        return [
            'label' => PerformerProfile::followersLabelFor($count),
            'kinds' => $this->kindsFor($story),
        ];
    }

    /** O conjunto de reações permitido (fonte única em config). */
    public static function allowedReactions(): array
    {
        return (array) config('stories.reactions.set', []);
    }

    /**
     * Os emojis (slugs) presentes neste story, na ordem do conjunto do config —
     * conjunto, sem contagem por tipo (o que revelaria audiência fina por emoji).
     *
     * @return array<int, string>
     */
    private function kindsFor(PerformerStory $story): array
    {
        $present = StoryReaction::query()
            ->where('performer_story_id', $story->getKey())
            ->distinct()
            ->pluck('reaction')
            ->all();

        $order = self::allowedReactions();

        return array_values(array_filter($order, fn (string $slug) => in_array($slug, $present, true)));
    }

    /**
     * Grava a linha da reação. Escrita campo a campo porque o `$fillable` é vazio:
     * as FKs e o slug são autoridade do servidor. O catch fecha a corrida de dois
     * cliques (o índice único do par garante uma linha) — mesma disciplina do
     * `PerformerHeartService` e do `writeView` de story.
     */
    private function create(PerformerStory $story, User $member, string $reaction): string
    {
        try {
            $row = new StoryReaction;
            $row->performer_story_id = $story->getKey();
            $row->member_id = $member->getKey();
            $row->reaction = $reaction;
            $row->save();
        } catch (UniqueConstraintViolationException) {
            $row = StoryReaction::query()
                ->where('performer_story_id', $story->getKey())
                ->where('member_id', $member->getKey())
                ->firstOrFail();

            $row->reaction = $reaction;
            $row->save();
        }

        return $reaction;
    }
}
