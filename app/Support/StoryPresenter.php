<?php

namespace App\Support;

use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Services\PerformerStoryService;

/**
 * O payload que a PERFORMER recebe sobre os próprios Stories.
 *
 * Existe como classe própria porque tem dois consumidores — o painel
 * (`Performer\DashboardController`, props do Inertia) e o
 * `GET /performer/stories` que o componente chama para recarregar. Duas cópias
 * divergiriam, e a que divergisse provavelmente seria a que devolve o número em
 * vez da faixa: é a mesma disciplina de `ExpirySlot` e de
 * `FollowerVisibilityService::applyFloorEligibility()` (item 6 do CLAUDE.md).
 *
 * ── O que sai, e por quê ────────────────────────────────────────────────────
 * - `view_count`: a FAIXA de membros únicos, ou `null` no nível exclusivo
 *   (§ 2.2, decisão nº 3). Vem do `PerformerStoryService::viewCount()`, que é a
 *   dona da regra — aqui não há nem `if` sobre o nível, senão a decisão passaria
 *   a ter dois lugares e o segundo é o que esqueceria o `null`.
 * - `expires_in_hours`: o prazo restante em horas inteiras. **Isto é relógio, e
 *   aqui é permitido** — ao contrário do § 1.2 da foto efêmera. Lá o TTL vinha de
 *   um menu de três opções conhecido pela performer, então o timestamp devolvia
 *   `granted_at` ao minuto; aqui o TTL é ÚNICO e fixo (24h) e o objeto é a
 *   publicação dela própria, então o número só diz quando ELA postou. Nenhum dado
 *   de membro atravessa esta chave.
 * - `image_url`: a rota autenticada de thumbnail, resolvida por sessão a cada
 *   request. Nunca URL assinada (§ 2.3).
 *
 * O que NUNCA sai: `media_path` (o `$hidden` do model já o tira da serialização,
 * e aqui o payload é montado campo a campo, que é a segunda barreira) e qualquer
 * coisa derivada de `story_views` que não seja a faixa — nada de lista, nada de
 * "quem", nada de horário de view.
 */
class StoryPresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forOwner(PerformerProfile $profile, PerformerStoryService $stories): array
    {
        return $stories->activeFor($profile)
            ->map(fn (PerformerStory $story) => self::one($story, $stories))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(PerformerStory $story, PerformerStoryService $stories): array
    {
        return [
            'id' => $story->id,
            'visibility_level' => $story->visibility_level,
            // Faixa ou null. A tela renderiza ausência, nunca `0` — ver a decisão
            // nº 3: zero é um valor no mesmo domínio da faixa e afirmaria algo
            // falso sobre a audiência.
            'view_count' => $stories->viewCount($story),
            'expires_in_hours' => self::hoursLeft($story),
            'image_url' => route('performer.stories.image', $story->id),
            // Convite (Sprint 12): dado DELA sobre a própria publicação — a tela
            // marca quais stories são convites e conta as vagas usadas a partir
            // disto, sem prop extra. Nada de membro atravessa aqui: o convite não
            // guarda "quem recebeu".
            'is_invite' => $story->is_invite,
        ];
    }

    /**
     * Horas inteiras até o vencimento, arredondando para cima e nunca negativo.
     *
     * `ceil` para que a última hora apareça como "1h" em vez de "0h": um story
     * vivo nunca deve ser exibido como se já tivesse acabado. Story vencido não
     * chega aqui (a lista é do escopo `active()`), então o `max(0, …)` é só
     * fail-safe para um chamador futuro.
     */
    private static function hoursLeft(PerformerStory $story): int
    {
        return max(0, (int) ceil(now()->diffInMinutes($story->expires_at, false) / 60));
    }
}
