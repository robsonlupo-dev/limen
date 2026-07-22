<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\AlreadySubscribedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\SubscribeRequest;
use App\Models\Circle;
use App\Services\Asaas\AsaasRequestException;
use App\Services\Asaas\AsaasUnavailableException;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptionService) {}

    public function index(Request $request): Response
    {
        $circles = Circle::where('active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Circle $circle) => [
                'slug' => $circle->slug,
                'name' => $circle->name,
                'price_cents' => $circle->price_cents,
                'price_formatted' => 'R$ '.number_format($circle->price_cents / 100, 2, ',', '.'),
                'monthly_tokens' => $circle->monthly_tokens,
                'discount_pct' => $circle->discount_pct,
                'invite_only' => $circle->invite_only,
            ]);

        $subscription = $request->user()->activeSubscription();

        return Inertia::render('Subscription/Index', [
            'circles' => $circles,
            'subscription' => $subscription ? [
                'circle' => $subscription->circle->slug,
                'status' => $subscription->status,
                'current_period_end' => $subscription->current_period_end?->format('d/m/Y'),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
                'is_in_trial' => $subscription->isInTrial(),
            ] : null,
        ]);
    }

    public function store(SubscribeRequest $request): RedirectResponse
    {
        $circle = Circle::where('slug', $request->validated('circle_slug'))->firstOrFail();

        // Founders Circle é apenas por convite — não há sistema de convites na
        // Fase A, então rejeitamos aqui (a UI já mostra "por convite").
        if ($circle->invite_only) {
            return back()->with('error', 'Este Círculo é apenas por convite.');
        }

        try {
            $this->subscriptionService->subscribe($request->user(), $circle, $request->cardData());
        } catch (AlreadySubscribedException) {
            return back()->with('error', 'Você já tem um Círculo ativo. Cancele o atual antes de trocar.');
        } catch (AsaasRequestException) {
            // Rejeição definitiva do gateway (ex.: cartão recusado). Não expõe
            // detalhe do gateway ao usuário nem loga dado de cartão.
            return back()->with('error', 'Não foi possível processar o cartão. Verifique os dados e tente novamente.');
        } catch (AsaasUnavailableException $e) {
            Log::error('Asaas unavailable on subscribe', ['error' => $e->getMessage()]);

            return back()->with('error', 'Pagamento temporariamente indisponível. Tente novamente em instantes.');
        }

        return redirect()->route('consumer.dashboard')
            ->with('success', "Assinatura do {$circle->name} ativada! Seus tokens do mês já estão na carteira.");
    }

    public function cancel(Request $request): RedirectResponse
    {
        $subscription = $request->user()->activeSubscription();

        if (! $subscription) {
            return back()->with('error', 'Você não tem uma assinatura ativa.');
        }

        $this->subscriptionService->cancel($subscription);

        return redirect()->route('consumer.dashboard')
            ->with('success', 'Sua assinatura será encerrada ao fim do período atual.');
    }
}
