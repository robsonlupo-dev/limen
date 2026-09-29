<?php

namespace App\Services;

use App\Events\LiveReaction;
use App\Events\LiveTipGoalProgress;
use App\Models\Gift;
use App\Models\LiveSession;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\FanAlias;
use App\Support\MemberDisplayName;

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
    public function __construct(private TipGoalService $tipGoals) {}

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
            MemberDisplayName::for($member->nickname, $profile->id, $member->id),
        );

        // Meta de gorjeta (Onda 4 §4.2): a gorjeta acabou de somar → empurra o progresso
        // atualizado (agregado, sem "quem") para a barra no overlay subir em tempo real.
        $this->broadcastGoalProgress($profile);
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
            MemberDisplayName::for($member->nickname, $profile->id, $member->id),
        );
    }

    /** Empurra o progresso da meta ativa (se houver) para a barra do overlay. */
    private function broadcastGoalProgress(PerformerProfile $profile): void
    {
        $payload = $this->tipGoals->publicPayload($profile);
        if ($payload === null) {
            return;
        }

        LiveTipGoalProgress::dispatch(
            $profile->slug,
            $payload['title'],
            $payload['target'],
            $payload['raised'],
            $payload['pct'],
        );
    }

    private function liveActive(int $performerProfileId): bool
    {
        return LiveSession::live()->where('performer_profile_id', $performerProfileId)->exists();
    }
}
