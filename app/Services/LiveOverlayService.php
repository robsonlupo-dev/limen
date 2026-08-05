<?php

namespace App\Services;

use App\Events\LiveReaction;
use App\Models\Gift;
use App\Models\LiveSession;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\FanAlias;

/**
 * Dispara a animação de prova social do <LiveOverlay> quando uma gorjeta/presente
 * é enviada DURANTE uma live pública ativa (Sprint 15, PR #142). Dona única do
 * gate "só durante a live" e da montagem do payload não-sensível.
 *
 * O gate é `live_sessions status=live` (a LIVE PÚBLICA). O group show usa
 * `call_sessions` e NÃO tem live_session, então nada dispara durante um group —
 * de propósito: a feature é da live pública, que é onde vivem os botões de
 * gorjeta/presente (PR #139). Chamado PÓS-COMMIT pelos TipService/GiftService,
 * SÓ quando a transação criou um registro novo (nunca num retorno idempotente,
 * que dobraria a animação).
 */
class LiveOverlayService
{
    public function tip(PerformerProfile $profile, User $member, int $amount): void
    {
        if (! $this->liveActive($profile->id)) {
            return;
        }

        LiveReaction::dispatch(
            $profile->slug,
            'tip',
            null,
            $amount,
            FanAlias::label($profile->id, $member->id),
        );
    }

    public function gift(PerformerProfile $profile, User $member, Gift $gift): void
    {
        if (! $this->liveActive($profile->id)) {
            return;
        }

        LiveReaction::dispatch(
            $profile->slug,
            'gift',
            $gift->slug,
            (int) $gift->price_tokens,
            FanAlias::label($profile->id, $member->id),
        );
    }

    private function liveActive(int $performerProfileId): bool
    {
        return LiveSession::live()->where('performer_profile_id', $performerProfileId)->exists();
    }
}
