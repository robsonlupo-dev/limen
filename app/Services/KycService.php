<?php

namespace App\Services;

use App\Jobs\SendKycApprovedEmail;
use App\Jobs\SendKycRejectedEmail;
use App\Models\IdentityVerification;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class KycService
{
    public function approve(IdentityVerification $verification, ?int $reviewedBy = null): void
    {
        DB::transaction(function () use ($verification, $reviewedBy) {
            $verification->update([
                'status' => 'approved',
                'age_confirmed' => true,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
            ]);

            $user = $verification->user;

            $user->status = 'active';
            $user->age_verified_at = now();
            $user->save();

            $user->performerProfile?->update(['is_verified' => true]);

            Audit::log('kyc.approved', $verification, [
                'reviewed_by' => $reviewedBy,
            ]);

            SendKycApprovedEmail::dispatch($user);
        });
    }

    public function reject(IdentityVerification $verification, ?string $reason = null, ?int $reviewedBy = null): void
    {
        DB::transaction(function () use ($verification, $reason, $reviewedBy) {
            $verification->update([
                'status' => 'rejected',
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
            ]);

            Audit::log('kyc.rejected', $verification, [
                'reason' => $reason,
                'reviewed_by' => $reviewedBy,
            ]);

            SendKycRejectedEmail::dispatch($verification->user, $reason);
        });
    }
}
