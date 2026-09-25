<?php

namespace App\Jobs;

use App\Mail\ReferralSignupMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Envia o aviso de "sua indicação se cadastrou" ao indicador (feat/referral-program).
 * Recebe só o id do indicador e o rótulo seguro do indicado — nenhum model com PII
 * é serializado na fila.
 */
class SendReferralSignupEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $referrerId,
        public string $referredLabel,
    ) {}

    public function handle(): void
    {
        $referrer = User::find($this->referrerId);
        if (! $referrer) {
            return;
        }

        Mail::to($referrer->email)->send(new ReferralSignupMail($referrer->name, $this->referredLabel));
    }
}
