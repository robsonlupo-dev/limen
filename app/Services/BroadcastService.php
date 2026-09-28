<?php

namespace App\Services;

use App\Exceptions\BroadcastException;
use App\Models\BroadcastMessage;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Canal de transmissão da performer (roadmap social, Onda 2). Dona única da regra.
 *
 * Direção performer → SEGUIDORES, 1-para-muitos, SEM resposta no canal: quem quer
 * falar cai no chat pago (o CTA da aba "Canais" leva ao chat). Grátis para o
 * seguidor — é motor de retenção, não cobrança; não mexe em token.
 *
 * ── Anti-contato é o ponto crítico ──────────────────────────────────────────
 * O `body` passa por `SafeProfileText` no Form Request (a porta de UI). Um
 * broadcast é o vetor perfeito de fuga de contato grátis — mandar o zap a todos os
 * seguidores de uma vez —, então o filtro público (o mesmo da bio/status) é o que
 * segura o modelo pago. O service é o guard do TETO diário e da propriedade.
 *
 * ── v1 é persistência + leitura por HTTP ────────────────────────────────────
 * A mensagem FICA e o seguidor lê na aba "Canais" (o offline vê depois). O push em
 * tempo real via Reverb fica para uma PR seguinte: o servidor atual (2 vCPU) não
 * comporta fan-out largo, e a retenção vem do persistido + o badge de não-visto.
 */
class BroadcastService
{
    /**
     * Publica um broadcast da performer. Teto diário conferido ANTES de gravar.
     *
     * @throws BroadcastException teto diário atingido (422)
     */
    public function send(PerformerProfile $profile, string $body): BroadcastMessage
    {
        $max = (int) config('broadcast.max_per_day', 10);

        $sentToday = BroadcastMessage::query()
            ->where('performer_profile_id', $profile->getKey())
            ->where('created_at', '>', now()->subDay())
            ->count();

        // Soft-cap: count-then-insert não é atômico, então dois envios concorrentes
        // podem passar juntos e estourar por 1 — aceito, como o soft-cap de convites/
        // Boost; o `throttle:10,1` da rota limita o burst e todo texto ainda passa
        // pelo filtro anti-contato. Fechar de vez exigiria lock por performer, caro
        // para um teto de negócio (não de dinheiro/segurança).
        if ($sentToday >= $max) {
            throw BroadcastException::dailyLimitReached($max);
        }

        // `body` pelo fillable (texto já validado no Form Request); a dona é
        // autoridade do servidor, gravada explícita — nunca de payload.
        $message = new BroadcastMessage(['body' => $body]);
        $message->performer_profile_id = $profile->getKey();
        $message->save();

        // Trilha: id da mensagem e da performer, nada de membro (o canal não guarda
        // "quem recebeu" — a entrega é derivada de `follows` na leitura).
        Audit::log('broadcast.sent', $message, [
            'broadcast_message_id' => $message->id,
            'performer_profile_id' => $profile->getKey(),
        ]);

        return $message;
    }

    /**
     * A aba "Canais" do membro: os broadcasts das performers que ele SEGUE e que
     * estão de pé (verificadas, ativas), mais recentes primeiro.
     *
     * A performer NÃO é anônima aqui (é o produto do "seguir"): nome artístico,
     * slug e avatar — identidade pública do catálogo, nada de PII. Nada de membro
     * atravessa: a entrega é derivada de `follows`, não de uma lista materializada.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function feedForMember(User $member): Collection
    {
        return $this->followedReachable($member)
            ->with('performerProfile:id,stage_name,slug,avatar_path')
            ->orderByDesc('id')
            ->limit((int) config('broadcast.feed_limit', 100))
            ->get()
            ->map(function (BroadcastMessage $message) {
                $profile = $message->performerProfile;

                return [
                    'id' => $message->id,
                    'body' => $message->body,
                    'performer' => [
                        'stage_name' => $profile->stage_name,
                        'slug' => $profile->slug,
                        // URL assinada e expirável, pelo profile_id (nunca user_id) —
                        // mesma regra do coração e do PerformerPublicResource.
                        'avatar_url' => $profile->avatar_path
                            ? URL::temporarySignedRoute(
                                'performer.media',
                                now()->addMinutes(60),
                                ['profile_id' => $profile->id, 'type' => 'avatar'],
                            )
                            : null,
                    ],
                    // Faixa de tempo, nunca relógio exato — disciplina de horário do
                    // projeto. É publicação DELA, mas a aba não precisa do minuto.
                    'sent_slot' => $message->created_at->isToday()
                        ? 'Hoje'
                        : $message->created_at->format('d/m/Y'),
                ];
            });
    }

    /**
     * Quantos broadcasts NÃO vistos o membro tem — o número do badge de "Canais".
     * Novo = `created_at` posterior ao watermark `broadcasts_seen_at` (null = nunca
     * abriu, tudo conta). Mesmo recorte do feed (só seguidas de pé), senão o badge
     * contaria o que a aba não mostra.
     */
    public function unseenCountForMember(User $member): int
    {
        $count = $this->followedReachable($member)
            ->when(
                $member->broadcasts_seen_at !== null,
                fn (Builder $q) => $q->where('broadcast_messages.created_at', '>', $member->broadcasts_seen_at),
            )
            ->count();

        // Casa com o teto da aba (`feed_limit`): o badge nunca promete mais do que a
        // tela mostra. O nav já corta em "99+", então isto é só alinhamento.
        return min($count, (int) config('broadcast.feed_limit', 100));
    }

    /**
     * Assenta o watermark de "canais vistos" em `now()` — zera o badge. Chamado ao
     * ABRIR a aba "Canais". Grava só a coluna, sem bumpar `updated_at` nem observers
     * (mesma disciplina do `hearts_seen_at`). Muta a instância em mãos para a nav,
     * reavaliada DEPOIS do controller, já ler zero.
     */
    public function markSeenForMember(User $member): void
    {
        $member->broadcasts_seen_at = now();
        $member->timestamps = false;
        $member->saveQuietly();
        $member->timestamps = true;
    }

    /**
     * Base comum de feed e badge: broadcasts de performers que o membro SEGUE e que
     * estão de pé. `whereExists` correlacionado em `follows` (não um `whereIn` de
     * ids) porque o membro pode seguir centenas — a subconsulta casa no banco sem
     * trazer a lista para a memória. "De pé" = perfil verificado + conta ativa,
     * mesmo recorte do coração: broadcast de conta suspensa/em KYC não aparece.
     */
    private function followedReachable(User $member): Builder
    {
        return BroadcastMessage::query()
            ->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('follows')
                ->whereColumn('follows.performer_profile_id', 'broadcast_messages.performer_profile_id')
                ->where('follows.user_id', $member->getKey())
            )
            ->whereHas('performerProfile', fn ($q) => $q
                ->where('is_verified', true)
                ->whereHas('user', fn ($u) => $u->where('status', 'active'))
            );
    }
}
