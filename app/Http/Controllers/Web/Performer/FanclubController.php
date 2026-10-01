<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\ContentException;
use App\Exceptions\CsamDetectedException;
use App\Exceptions\FanclubException;
use App\Exceptions\ImageProcessingException;
use App\Exceptions\VideoProcessingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\PublishFanclubContentRequest;
use App\Http\Requests\Web\SaveFanclubSettingsRequest;
use App\Services\FanclubService;
use App\Services\PerformerContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Painel do Fã-Clube da performer (Onda 4 — fork da assinatura, docs/FORK_ASSINATURA.md).
 * Abrir/editar o clube (preço público + VIP opcional), publicar no set, e ver a lista de
 * assinantes com o SINAL DE BALEIA (anônimo — FanAlias + selo de tier + faixa). A remoção
 * e o preview das peças do set reusam as rotas de conteúdo (owner-scoped). Dono único das
 * regras: FanclubService/PerformerContentService.
 */
class FanclubController extends Controller
{
    public function __construct(
        private FanclubService $fanclub,
        private PerformerContentService $content,
    ) {}

    public function page(Request $request): InertiaResponse|RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        if (! $profile) {
            return redirect()->route('performer.onboarding');
        }

        return Inertia::render('Performer/Fanclub/Index', [
            'settings' => $this->fanclub->settingsFor($profile),
            'set' => $this->content->fanclubForOwner($profile),
            'roster' => $this->fanclub->rosterFor($profile),
            'priceConfig' => [
                'min' => (int) config('monetization.fanclub.min_price'),
                'step' => (int) config('monetization.fanclub.price_step'),
                'max' => (int) config('monetization.fanclub.max_price'),
            ],
            // Para o display honesto do spread na tela (membro paga X token → ela saca R$Y).
            'payoutRatePerToken' => (float) config('monetization.payout_rate_per_token'),
            'performerSharePct' => (int) config('monetization.split_rates.content.rate'),
            // Piso de anonimato (texto do roster) — vem do config, nunca hardcoded na tela.
            'anonymityFloor' => (int) config('interest.anonymity_floor', 5),
        ]);
    }

    public function saveSettings(SaveFanclubSettingsRequest $request): RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        if (! $profile) {
            return redirect()->route('performer.onboarding');
        }

        $pricePublic = $request->input('price_public_tokens');
        $priceVip = $request->input('price_vip_tokens');

        try {
            $this->fanclub->saveSettings(
                $profile,
                $request->boolean('is_open'),
                $pricePublic !== null ? (int) $pricePublic : null,
                $request->boolean('vip_enabled'),
                $priceVip !== null ? (int) $priceVip : null,
            );
        } catch (FanclubException $e) {
            return back()->withErrors(['fanclub' => $e->getMessage()]);
        }

        return back()->with('status', 'Fã-clube atualizado.');
    }

    public function publishContent(PublishFanclubContentRequest $request): JsonResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        if (! $profile) {
            return response()->json(['message' => 'Complete seu cadastro antes de publicar.', 'reason' => 'no_profile'], 422);
        }

        $file = $request->file('arquivo');

        try {
            $piece = $this->content->publishToFanclub($profile, $file);
        } catch (ContentException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        } catch (ImageProcessingException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'invalid_image'], 422);
        } catch (CsamDetectedException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'rejected'], 422);
        } catch (VideoProcessingException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json([
            'message' => $piece->isVideo()
                ? 'Vídeo recebido. Ele ficará disponível no set após o processamento.'
                : 'Publicado no fã-clube.',
            'id' => $piece->id,
            'kind' => $piece->kind,
            'status' => $piece->status,
        ], 201);
    }
}
