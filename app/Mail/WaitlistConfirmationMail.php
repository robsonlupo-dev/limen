<?php

namespace App\Mail;

use App\Models\WaitlistEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Double opt-in confirmation email for the Founding Members program. Sent on a
 * new signup: it asks the person to confirm and offers a discreet link to their
 * founder panel — nothing else. It deliberately carries NO founder position and
 * NO invite/referral link: the position is private (nobody learns how many have
 * signed up) and the referral mechanic lives only on the panel (/f/{code}).
 * The copy differs by role (member vs performer). ShouldQueue so it never blocks
 * the landing submission; from address falls back to MAIL_FROM_ADDRESS.
 */
class WaitlistConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public WaitlistEntry $entry) {}

    public function envelope(): Envelope
    {
        $subject = $this->entry->isPerformer()
            ? 'Confirme sua reserva — Limen Founding Members'
            : 'Confirme seu lugar — Limen Founding Members';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.waitlist.confirmation',
            with: [
                // First name only — never the full/composite name.
                'firstName' => Str::of($this->entry->name)->trim()->explode(' ')->first(),
                'isPerformer' => $this->entry->isPerformer(),
                'founderTitle' => $this->entry->founderTitle(),
                'confirmUrl' => URL::route('waitlist.confirm', ['t' => $this->entry->invite_token]),
                'panelUrl' => URL::route('waitlist.founder', ['invite_code' => $this->entry->invite_code]),
                'unsubscribeUrl' => URL::route('waitlist.unsubscribe', ['t' => $this->entry->invite_token]),
            ],
        );
    }
}
