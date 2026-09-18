<?php

namespace App\Jobs;

use App\Mail\MemberPhotoRejectedMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa o membro que uma foto da galeria foi recusada pela moderação
 * (feat/member-gallery-and-profile). Espelha SendVoiceIntroRejectedEmail.
 */
class SendMemberPhotoRejectedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public User $user, public ?string $reason = null) {}

    public function handle(): void
    {
        Mail::to($this->user->email)->send(new MemberPhotoRejectedMail($this->user, $this->reason));
    }
}
