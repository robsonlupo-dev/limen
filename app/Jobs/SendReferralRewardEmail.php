<?php

namespace App\Jobs;

use App\Mail\ReferralRewardMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Envia o aviso de "bônus creditado" a um dos lados (feat/referral-program). Recebe
 * só escalares — id do beneficiário, papel, valor e o rótulo seguro da contraparte.
 */
class SendReferralRewardEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $beneficiaryId,
        public string $role,
        public int $amount,
        public string $counterpartyLabel = '',
    ) {}

    public function handle(): void
    {
        $beneficiary = User::find($this->beneficiaryId);
        if (! $beneficiary) {
            return;
        }

        Mail::to($beneficiary->email)->send(
            new ReferralRewardMail($beneficiary->name, $this->role, $this->amount, $this->counterpartyLabel),
        );
    }
}
