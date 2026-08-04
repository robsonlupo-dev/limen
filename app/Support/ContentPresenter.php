<?php

namespace App\Support;

use App\Models\PerformerContent;
use App\Models\User;
use App\Services\ContentVisibilityService;

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
}
