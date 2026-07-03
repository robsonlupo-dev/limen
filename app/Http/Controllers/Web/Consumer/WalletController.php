<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\WalletPurchaseRequest;
use App\Models\Payment;
use App\Models\TokenLedger;
use App\Models\TokenPackage;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class WalletController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private TokenService $tokenService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $packages = TokenPackage::where('active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (TokenPackage $package) => [
                'id' => $package->id,
                'slug' => $package->slug,
                'name' => $package->name,
                'tokens' => $package->tokens,
                'bonus' => $package->bonus,
                'price_cents' => $package->price_cents,
                'price_formatted' => 'R$ ' . number_format($package->price_cents / 100, 2, ',', '.'),
            ]);

        return Inertia::render('Consumer/Wallet/Index', [
            'balance' => $this->tokenService->balance($user),
            'packages' => $packages,
            'recent' => $this->recentLedger($user, 5),
            'needsCpf' => ! $user->asaas_customer_id,
        ]);
    }

    public function purchase(WalletPurchaseRequest $request, TokenPackage $package): JsonResponse
    {
        if (! $package->active) {
            abort(404);
        }

        $user = $request->user();

        try {
            $payment = Cache::lock("wallet-purchase:{$user->id}:{$package->id}", 10)->block(5, function () use ($user, $package, $request) {
                $existing = Payment::where('user_id', $user->id)
                    ->where('token_package_id', $package->id)
                    ->where('status', 'pending')
                    ->where('created_at', '>=', now()->subHours(2))
                    ->latest('id')
                    ->first();

                return $existing ?? $this->paymentService->createPayment($user, $package, $request->input('cpf'));
            });
        } catch (\RuntimeException $e) {
            // The Asaas gateway rejected or was unreachable. Surface a clean error
            // instead of a 500 — a raw exception page could leak the CPF/name in a
            // stack trace, and the frontend needs a JSON body it can consume.
            Log::warning('Wallet purchase failed at gateway', [
                'user_id' => $user->id,
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível gerar a cobrança PIX agora. Verifique o CPF informado e tente novamente.',
            ], 422);
        }

        return response()->json([
            'payment_id' => $payment->id,
            'pix_code' => $payment->pix_copy_paste,
            'pix_qr_base64' => $payment->pix_qr_code,
            'expires_at' => $payment->expires_at,
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        $payment = Payment::where('id', (int) $request->query('payment_id'))
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $payment) {
            abort(404);
        }

        return response()->json([
            'status' => $this->mapStatus($payment->status),
            'balance' => $this->tokenService->balance($request->user()),
        ]);
    }

    public function history(Request $request): Response
    {
        $wallet = TokenWallet::where('user_id', $request->user()->id)->first();

        $entries = TokenLedger::query()
            ->when($wallet, fn ($query) => $query->where('wallet_id', $wallet->id), fn ($query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('id')
            ->paginate(15)
            ->through(fn (TokenLedger $entry) => [
                'entry_type' => $entry->entry_type,
                'amount' => $entry->amount,
                'balance_after' => $entry->balance_after,
                'created_at' => $entry->created_at->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Consumer/Wallet/History', [
            'entries' => $entries,
        ]);
    }

    private function recentLedger(User $user, int $limit): array
    {
        $wallet = TokenWallet::where('user_id', $user->id)->first();

        if (! $wallet) {
            return [];
        }

        return TokenLedger::where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (TokenLedger $entry) => [
                'entry_type' => $entry->entry_type,
                'amount' => $entry->amount,
                'balance_after' => $entry->balance_after,
                'created_at' => $entry->created_at->format('d/m/Y H:i'),
            ])
            ->toArray();
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'confirmed' => 'paid',
            'pending' => 'pending',
            'failed' => 'failed',
            default => 'expired',
        };
    }
}
