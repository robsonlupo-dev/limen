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

/**
 * Confirmation sent when someone joins the pre-launch waitlist. Implements
 * ShouldQueue so it rides the (database) queue when a worker is active, and
 * never blocks or breaks the landing submission if mail delivery is slow.
 * The from address falls back to MAIL_FROM_ADDRESS configured in the .env.
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
        return new Envelope(subject: 'Você está na lista — Limen');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.waitlist.confirmation',
            with: [
                'name' => $this->entry->name,
                'position' => $this->position,
                // Absolute links so they resolve from an inbox. The unsubscribe
                // link opens a confirmation page (GET is side-effect-free); the
                // token is opaque and carries the email, so no PII hits the log.
                'landingUrl' => URL::route('landing', ['ref' => 'waitlist']),
                'unsubscribeUrl' => URL::route('waitlist.unsubscribe', [
                    't' => $this->entry->unsubscribeToken(),
                ]),
            ],
        );
    }
}
