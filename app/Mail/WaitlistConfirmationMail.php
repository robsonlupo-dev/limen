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
 * Confirmation + activation email for the Founding Members program. Sent on a
 * new signup: asks the person to confirm (double opt-in), shows their waitlist
 * position and tier, and hands them their unique invite link to climb tiers.
 * ShouldQueue so it never blocks the landing submission; from address falls back
 * to MAIL_FROM_ADDRESS.
 */
class WaitlistConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  int  $position  1-based place in the waitlist, frozen at signup time.
     */
    public function __construct(public WaitlistEntry $entry, public int $position) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirme seu lugar — Limen Founding Members');
    }

    public function content(): Content
    {
        $tier = $this->entry->tier;
        $next = $tier->next();

        return new Content(
            view: 'emails.waitlist.confirmation',
            with: [
                'firstName' => Str::of($this->entry->name)->trim()->explode(' ')->first(),
                'position' => $this->position,
                'tierLabel' => $tier->label(),
                'nextTier' => $next ? [
                    'label' => $next->label(),
                    'benefit' => $next->benefit(),
                    'remaining' => max(1, $next->threshold() - $this->entry->referral_count),
                ] : null,
                'inviteUrl' => URL::route('convite.show', ['invite_code' => $this->entry->invite_code]),
                'confirmUrl' => URL::route('waitlist.confirm', ['t' => $this->entry->invite_token]),
                'panelUrl' => URL::route('waitlist.founder', ['invite_code' => $this->entry->invite_code]),
                'unsubscribeUrl' => URL::route('waitlist.unsubscribe', ['t' => $this->entry->invite_token]),
            ],
        );
    }
}
