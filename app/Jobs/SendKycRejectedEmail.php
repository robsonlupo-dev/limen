<?php

namespace App\Jobs;

use App\Mail\KycRejectedMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendKycRejectedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public User $user, public ?string $reason = null) {}

    public function handle(): void
    {
        Mail::to($this->user->email)->send(new KycRejectedMail($this->user, $this->reason));
    }
}
