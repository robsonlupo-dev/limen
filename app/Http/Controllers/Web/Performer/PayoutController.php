<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\PayoutNotAllowedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\PayoutRequest;
use App\Models\Payout;
use App\Models\User;
use App\Services\PayoutService;
use App\Services\TokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PayoutController extends Controller
{
    public function __construct(
        private PayoutService $payoutService,
        private TokenService $tokenService,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('performer-active');

        $user = $request->user();
        $profile = $user->performerProfile;

        return Inertia::render('Performer/Payouts/Index', [
            'balance' => $this->tokenService->balance($user),
            'splitPct' => $profile->split_pct,
            'kycOk' => (bool) $profile->is_verified,
            'recent' => $this->recentPayouts($user, 5),
        ]);
    }

    public function store(PayoutRequest $request): RedirectResponse
    {
        Gate::authorize('performer-active');

        try {
            $payout = $this->payoutService->requestPayout(
                $request->user(),
                $request->validated('tokens'),
                $request->validated('pix_key'),
                $request->validated('pix_key_type'),
            );
        } catch (PayoutNotAllowedException $e) {
            return back()->withErrors(['kyc' => $e->getMessage()]);
        } catch (InsufficientBalanceException) {
            return back()->withErrors(['tokens' => 'Saldo insuficiente para este saque.']);
        }

        if ($payout->status === 'failed') {
            return back()->with('error', 'Não foi possível processar seu saque. Os tokens foram estornados.');
        }

        return back()->with('success', 'Saque solicitado com sucesso! Em processamento.');
    }

    public function history(Request $request): Response
    {
        Gate::authorize('performer-active');

        $payouts = Payout::where('performer_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate(15)
            ->through(fn (Payout $payout) => $this->formatPayout($payout));

        return Inertia::render('Performer/Payouts/History', [
            'payouts' => $payouts,
        ]);
    }

    private function recentPayouts(User $user, int $limit): array
    {
        return Payout::where('performer_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Payout $payout) => $this->formatPayout($payout))
            ->toArray();
    }

    private function formatPayout(Payout $payout): array
    {
        return [
            'id' => $payout->id,
            'tokens' => $payout->tokens,
            'amount_brl' => (float) $payout->amount_brl,
            'pix_key_masked' => $this->maskPixKey($payout->pix_key, $payout->pix_key_type),
            'status' => $payout->status,
            'created_at' => $payout->created_at->format('d/m/Y H:i'),
        ];
    }

    private function maskPixKey(string $key, string $type): string
    {
        return match ($type) {
            'cpf' => $this->maskCpf($key),
            'email' => $this->maskEmail($key),
            'phone' => $this->maskPhone($key),
            default => $this->maskGeneric($key),
        };
    }

    private function maskCpf(string $key): string
    {
        $digits = preg_replace('/\D/', '', $key);

        if (strlen($digits) !== 11) {
            return $this->maskGeneric($key);
        }

        return substr($digits, 0, 3).'.***.***-'.substr($digits, -2);
    }

    private function maskEmail(string $key): string
    {
        [$local, $domain] = array_pad(explode('@', $key, 2), 2, '');

        if ($domain === '') {
            return $this->maskGeneric($key);
        }

        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.'***@'.$domain;
    }

    private function maskPhone(string $key): string
    {
        $digits = preg_replace('/\D/', '', $key);
        $len = strlen($digits);

        if ($len < 4) {
            return $this->maskGeneric($key);
        }

        return str_repeat('*', $len - 4).substr($digits, -4);
    }

    private function maskGeneric(string $key): string
    {
        $len = strlen($key);

        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).substr($key, -4);
    }
}
