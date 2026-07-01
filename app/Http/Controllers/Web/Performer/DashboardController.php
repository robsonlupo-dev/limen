<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Models\PerformerProfile;
use App\Models\Tip;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('performer-active');

        $user = $request->user();
        $profile = $user->performerProfile;

        return Inertia::render('Performer/Dashboard', [
            'wallet' => $this->walletBalance($user),
            'totalEarned' => $this->totalEarned($user),
            'tips' => $this->recentTips($profile),
            'followers' => $profile->followers_count,
            'kycStatus' => $this->kycStatus($user),
            'isLive' => $profile->is_live,
        ]);
    }

    private function walletBalance(User $user): int
    {
        return TokenWallet::where('user_id', $user->id)->value('balance') ?? 0;
    }

    private function totalEarned(User $user): int
    {
        $walletId = TokenWallet::where('user_id', $user->id)->value('id');

        if (! $walletId) {
            return 0;
        }

        return (int) TokenLedger::where('wallet_id', $walletId)
            ->where('entry_type', 'tip_credit')
            ->sum('amount');
    }

    private function recentTips(PerformerProfile $profile): array
    {
        return Tip::where('performer_profile_id', $profile->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['consumer_id', 'performer_amount', 'created_at'])
            ->map(fn (Tip $tip) => [
                'fan' => 'Fã #' . str_pad((string) ($tip->consumer_id % 10000), 4, '0', STR_PAD_LEFT),
                'amount' => $tip->performer_amount,
                'created_at' => $tip->created_at->format('d/m/Y H:i'),
            ])
            ->toArray();
    }

    private function kycStatus(User $user): string
    {
        $status = IdentityVerification::where('user_id', $user->id)
            ->latest()
            ->value('status');

        return match ($status) {
            'approved' => 'active',
            'rejected' => 'rejected',
            default => 'pending',
        };
    }
}
