<?php

namespace App\Mail;

use App\Models\WaitlistEntry;
use App\Services\Waitlist\FounderPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Confirmation + activation email for the Founding Members program. Sent on a
 * new signup: asks the person to confirm (double opt-in), shows their per-role
 * founder position and tier, and hands them their unique invite link to climb
 * tiers. ShouldQueue so it never blocks the landing submission; from address
 * falls back to MAIL_FROM_ADDRESS.
 */
class WaitlistConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public WaitlistEntry $entry) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirme seu lugar — Limen Founding Members');
    }

    public function content(): Content
    {
        $view = app(FounderPresenter::class)->for($this->entry);

        return new Content(
            view: 'emails.waitlist.confirmation',
            with: [
                'firstName' => $view['firstName'],
                'founderTitle' => $view['founderTitle'],
                'position' => $view['position'],
                'tierLabel' => $view['tierLabel'],
                'nextTier' => $view['nextTier'],
                'inviteUrl' => $view['inviteUrl'],
                'confirmUrl' => URL::route('waitlist.confirm', ['t' => $this->entry->invite_token]),
                'panelUrl' => URL::route('waitlist.founder', ['invite_code' => $this->entry->invite_code]),
                'unsubscribeUrl' => URL::route('waitlist.unsubscribe', ['t' => $this->entry->invite_token]),
            ],
        );
    }
}
