<?php

namespace App\Support;

use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\ContentVisibilityService;
use Illuminate\Support\Facades\URL;

/**
 * O que um espectador recebe de uma peça, em JSON. Decisão POR ESPECTADOR, com o
 * MESMO predicado do serving (ContentVisibilityService::canView) — presenter e
 * serving não podem divergir (lição do Story/Photo).
 *
 * ── `image_url` é null quando bloqueado, e isso é o paywall ──────────────────
 * Blur em CSS não é paywall: peça bloqueada NÃO recebe URL de bytes nenhuma — a
 * tela desenha um placeholder com o preço e o nível (o upsell do Modelo C). Só
 * quem alcança (dona / grátis / desbloqueado) recebe a URL.
 */
class ContentPresenter
{
    public static function one(PerformerContent $content, ?User $viewer): array
    {
        $visibility = app(ContentVisibilityService::class);

        $canView = $visibility->canView($viewer, $content);
        $state = $visibility->stateFor($viewer, $content);
        $canUnlock = $viewer !== null && $viewer->role === 'consumer'
            ? $visibility->canUnlock($viewer, $content)
            : false;

        return [
            'id' => $content->id,
            'kind' => $content->kind,
            'access_level' => $content->access_level,
            'price_tokens' => (int) $content->price_tokens,
            'locked' => ! $canView,
            'state' => $state, // owner | unlocked | free | locked (dado do próprio membro)
            'can_unlock' => $canUnlock,
            // Bloqueado → sem URL (o paywall vive aqui, não num blur de CSS).
            'image_url' => $canView ? route('content.image', $content->id) : null,
        ];
    }

    /**
     * Item do FEED do membro: a MESMA peça de `one()` (mesmo paywall, mesma
     * decisão por espectador) mais o contexto da performer que o feed cross-perfil
     * precisa — nome + link para o perfil + avatar — e a data de publicação para
     * a data relativa na tela. `published_at` é ISO (o cliente formata em faixa);
     * é dado PÚBLICO da publicação da performer, não presença de membro.
     */
    public static function feedItem(PerformerContent $content, ?User $viewer): array
    {
        $profile = $content->performerProfile;

        return array_merge(self::one($content, $viewer), [
            'performer' => [
                'stage_name' => $profile?->stage_name,
                'slug' => $profile?->slug,
                'avatar_url' => self::avatarUrl($profile),
            ],
            'published_at' => optional($content->created_at)->toIso8601String(),
        ]);
    }

    /** URL assinada e curta do avatar (mesma forma do PerformerPublicResource). */
    private static function avatarUrl(?PerformerProfile $profile): ?string
    {
        if ($profile === null || ! $profile->avatar_path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'performer.media',
            now()->addMinutes(60),
            ['profile_id' => $profile->id, 'type' => 'avatar'],
        );
    }
}
