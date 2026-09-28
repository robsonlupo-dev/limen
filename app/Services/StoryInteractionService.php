<?php

namespace App\Services;

use App\Exceptions\StoryException;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\StoryInteraction;
use App\Models\StoryPollVote;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Enquete no story (roadmap social, Onda 1b). Dona única da regra.
 *
 * ── Votar usa a MESMA porta do serving (§ 2.3) ──────────────────────────────
 * `vote()` pergunta ao `StoryVisibilityService::denialFor` antes de gravar — votar
 * numa enquete de story fora de alcance seria oráculo de existência e escrita no
 * conteúdo pago por fora do paywall. Mesma porta do `readForMember`.
 *
 * ── Exclusivo não tem enquete (decisão nº 3) ────────────────────────────────
 * O que a performer vê é a DISTRIBUIÇÃO (agregado). No exclusivo, participação
 * revelaria o tamanho do público Black — o mesmo oráculo que tira o contador do
 * Nível 3. `attach()` recusa exclusivo; `vote()` também, por defesa.
 *
 * ── Agregado, nunca "quem votou" ────────────────────────────────────────────
 * `resultsFor`/`ownerView` devolvem só contagem por opção. Anonimato do membro é
 * piso (princípio 1). E Ghost Mode/Modo Discreto NÃO entram no agregado: o voto de
 * quem tem o perk não é gravado (write-time guard, § 2.7), senão votos > views
 * fingerprinta o tier que comprou invisibilidade.
 */
class StoryInteractionService
{
    public function __construct(private StoryVisibilityService $visibility) {}

    /**
     * Prende (ou substitui) a enquete de um story da própria performer.
     *
     * @param  array<int, string>  $options
     *
     * @throws StoryException não é dela / vencido / exclusivo (não tem enquete)
     * @throws InvalidArgumentException número de opções fora do intervalo
     */
    public function attach(PerformerProfile $profile, PerformerStory $story, string $prompt, array $options): StoryInteraction
    {
        if ($story->performer_profile_id !== $profile->getKey()) {
            throw StoryException::notOwner();
        }

        if ($story->trashed() || $story->isExpired()) {
            throw StoryException::expired();
        }

        // Exclusivo não tem superfície de audiência — ver o docblock.
        if ($story->visibility_level === PerformerStory::VISIBILITY_EXCLUSIVE) {
            throw StoryException::forbidden();
        }

        $options = array_values(array_map('trim', $options));

        $min = (int) config('stories.polls.min_options', 2);
        $max = (int) config('stories.polls.max_options', 4);

        if (count($options) < $min || count($options) > $max) {
            throw new InvalidArgumentException("Enquete precisa de {$min} a {$max} opções.");
        }

        // Uma interação por story. Recriar do zero quando já existe ZERA os votos de
        // propósito: editar a enquete muda o significado dos índices (a "opção 2"
        // passa a ser outra coisa), então um voto antigo não pode ser reaproveitado —
        // apagamos a interação (votos vão por cascade) e criamos a nova. `type/prompt/
        // options` vêm do $fillable; `performer_story_id` é autoridade do servidor,
        // gravado explícito (nunca de payload).
        $story->interaction()->delete();

        $interaction = new StoryInteraction([
            'type' => StoryInteraction::TYPE_POLL,
            'prompt' => $prompt,
            'options' => $options,
        ]);
        $interaction->performer_story_id = $story->getKey();
        $interaction->save();

        return $interaction;
    }

    /** Remove a enquete do story (votos vão junto por cascade da FK). */
    public function remove(PerformerProfile $profile, PerformerStory $story): void
    {
        if ($story->performer_profile_id !== $profile->getKey()) {
            throw StoryException::notOwner();
        }

        $story->interaction()->delete();
    }

    /**
     * O membro vota numa opção. Voto IMUTÁVEL (uma vez, o índice único garante).
     * Devolve a visão de resultado do membro: `{my_vote, results, total}`.
     *
     * @throws StoryException vencido/fora do ar (404) ou sem alcance/exclusivo/sem
     *                        enquete (403)
     * @throws InvalidArgumentException opção fora do intervalo (o Form Request já recusa)
     *
     * @return array{my_vote:int, results:array<int,int>, total:int}
     */
    public function vote(User $member, PerformerStory $story, int $optionIndex): array
    {
        $denial = $this->visibility->denialFor($story, $member);

        if ($denial === StoryException::EXPIRED) {
            throw StoryException::expired();
        }

        if ($denial !== null) {
            throw StoryException::forbidden();
        }

        $interaction = $story->interaction;

        // Sem enquete (ou tipo diferente), ou exclusivo (defesa): nada a votar.
        if ($interaction === null || ! $interaction->isPoll()
            || $story->visibility_level === PerformerStory::VISIBILITY_EXCLUSIVE) {
            throw StoryException::forbidden();
        }

        $options = $interaction->options ?? [];

        if ($optionIndex < 0 || $optionIndex >= count($options)) {
            throw new InvalidArgumentException("Opção de enquete inválida: {$optionIndex}");
        }

        // Ghost Mode / Modo Discreto: o voto NÃO é gravado (write-time guard,
        // § 2.7). Remove também um voto ANTERIOR (feito antes de ligar o perk), como
        // a reação faz — assim quem fica invisível também retira o sinal antigo do
        // agregado. Devolve o índice tocado (otimista, para o membro ver o resultado)
        // e a distribuição SEM ele — a resposta é a mesma em forma, ele só não deixa
        // rastro, que é o que o perk vende.
        if ($member->discrete_mode || $member->hasGhostMode()) {
            StoryPollVote::query()
                ->where('story_interaction_id', $interaction->getKey())
                ->where('member_id', $member->getKey())
                ->delete();

            return $this->resultView($interaction, $optionIndex);
        }

        $existing = StoryPollVote::query()
            ->where('story_interaction_id', $interaction->getKey())
            ->where('member_id', $member->getKey())
            ->first();

        // Imutável: já votou → devolve o voto existente, não troca nem duplica.
        if ($existing !== null) {
            return $this->resultView($interaction, (int) $existing->option_index);
        }

        try {
            $vote = new StoryPollVote;
            $vote->story_interaction_id = $interaction->getKey();
            $vote->member_id = $member->getKey();
            $vote->option_index = $optionIndex;
            $vote->save();
            $recorded = $optionIndex;
        } catch (UniqueConstraintViolationException) {
            // Corrida de duplo-clique: o índice único segurou; lê o que ficou.
            $recorded = (int) StoryPollVote::query()
                ->where('story_interaction_id', $interaction->getKey())
                ->where('member_id', $member->getKey())
                ->value('option_index');
        }

        return $this->resultView($interaction, $recorded);
    }

    /**
     * A visão da PERFORMER sobre a enquete do story (painel): pergunta, opções e a
     * distribuição — agregado, nunca "quem votou". `null` se o story não tem
     * enquete.
     *
     * @return array{type:string, prompt:string, options:array<int,string>, results:array<int,int>, total:int}|null
     */
    public function ownerView(PerformerStory $story): ?array
    {
        $interaction = $story->interaction;

        if ($interaction === null || ! $interaction->isPoll()) {
            return null;
        }

        $results = $this->resultsFor($interaction);

        return [
            'type' => StoryInteraction::TYPE_POLL,
            'prompt' => $interaction->prompt,
            'options' => array_values($interaction->options ?? []),
            'results' => $results,
            'total' => array_sum($results),
        ];
    }

    /**
     * A visão do MEMBRO por story, em lote (feed/faixa). `[story_id => payload|null]`.
     *
     * Payload: `{type, prompt, options, my_vote, results, total}`. Os resultados só
     * vão quando o membro JÁ votou (revela depois do voto, como o Instagram) — antes
     * disso `results`/`total` vêm null e a tela mostra só as opções tocáveis. Tudo
     * em poucas queries (uma dos interactions, uma dos votos do membro, uma das
     * contagens), nunca por card.
     *
     * @param  array<int, int>  $storyIds
     * @return array<int, array<string, mixed>|null>
     */
    public function batchMemberView(array $storyIds, ?User $member): array
    {
        if ($storyIds === []) {
            return [];
        }

        $interactions = StoryInteraction::query()
            ->whereIn('performer_story_id', $storyIds)
            ->where('type', StoryInteraction::TYPE_POLL)
            ->get();

        if ($interactions->isEmpty()) {
            return [];
        }

        $interactionIds = $interactions->pluck('id')->all();

        // Voto do próprio membro por interação (dado dele — nunca de outro).
        $myVotes = $member !== null
            ? StoryPollVote::query()
                ->where('member_id', $member->getKey())
                ->whereIn('story_interaction_id', $interactionIds)
                ->pluck('option_index', 'story_interaction_id')
                ->map(fn ($i) => (int) $i)
                ->all()
            : [];

        // Contagens agregadas por interação+opção, numa query.
        $countsByInteraction = $this->countsForMany($interactionIds);

        $out = [];

        foreach ($interactions as $interaction) {
            $options = array_values($interaction->options ?? []);
            $myVote = $myVotes[$interaction->id] ?? null;

            $results = $myVote !== null
                ? $this->alignCounts($countsByInteraction[$interaction->id] ?? [], count($options))
                : null;

            $out[(int) $interaction->performer_story_id] = [
                'type' => StoryInteraction::TYPE_POLL,
                'prompt' => $interaction->prompt,
                'options' => $options,
                'my_vote' => $myVote,
                'results' => $results,
                'total' => $results !== null ? array_sum($results) : null,
            ];
        }

        return $out;
    }

    /**
     * Contagem por opção desta enquete, alinhada ao array de opções.
     *
     * @return array<int, int>
     */
    public function resultsFor(StoryInteraction $interaction): array
    {
        $counts = $this->countsForMany([$interaction->getKey()]);

        return $this->alignCounts($counts[$interaction->getKey()] ?? [], count($interaction->options ?? []));
    }

    /**
     * `{my_vote, results, total}` para a resposta do voto.
     *
     * @return array{my_vote:int, results:array<int,int>, total:int}
     */
    private function resultView(StoryInteraction $interaction, int $myVote): array
    {
        $results = $this->resultsFor($interaction);

        return [
            'my_vote' => $myVote,
            'results' => $results,
            'total' => array_sum($results),
        ];
    }

    /**
     * Contagens `[interactionId => [optionIndex => count]]` para várias enquetes
     * numa query só (o feed não pode disparar uma por card).
     *
     * @param  array<int, int>  $interactionIds
     * @return array<int, array<int, int>>
     */
    private function countsForMany(array $interactionIds): array
    {
        if ($interactionIds === []) {
            return [];
        }

        $rows = StoryPollVote::query()
            ->whereIn('story_interaction_id', $interactionIds)
            ->select('story_interaction_id', 'option_index', DB::raw('count(*) as c'))
            ->groupBy('story_interaction_id', 'option_index')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->story_interaction_id][(int) $row->option_index] = (int) $row->c;
        }

        return $out;
    }

    /**
     * Densifica o mapa esparso de contagens num array indexado por opção (0..N-1),
     * com zero onde ninguém votou.
     *
     * @param  array<int, int>  $sparse
     * @return array<int, int>
     */
    private function alignCounts(array $sparse, int $optionCount): array
    {
        $counts = array_fill(0, max(0, $optionCount), 0);

        foreach ($sparse as $index => $count) {
            if ($index >= 0 && $index < $optionCount) {
                $counts[$index] = $count;
            }
        }

        return $counts;
    }
}
