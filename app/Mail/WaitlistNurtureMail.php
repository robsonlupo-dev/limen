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
 * One step of the Founding Members nurturing drip, sent only to CONFIRMED
 * entries (the scheduler enforces that). A single Mailable renders any step via
 * $stepKey → the emails.waitlist.nurture.{stepKey} view, so all seven share one
 * class. Like the confirmation email it carries NO founder position and NO
 * invite/referral link — the referral mechanic lives only on the panel. Copy
 * differs by role. ShouldQueue so delivery never blocks the sender command.
 */
class WaitlistNurtureMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * If the entry is deleted between the claim and the queued job running (the
     * person unsubscribed), drop the job silently instead of failing loudly —
     * we don't want to email someone who left.
     */
    public bool $deleteWhenMissingModels = true;

    /** Per-step subject line (PLACEHOLDER — PO replaces with final copy). */
    private const SUBJECTS = [
        'nurture_1' => 'Bem-vindo ao Limen Founding Members',
        'nurture_2' => 'Por que verificamos os dois lados',
        'nurture_3' => 'Como o Limen funciona por dentro',
        'nurture_4' => 'Uma comunidade selecionada',
        'nurture_5' => 'Suas vantagens de fundador',
        'nurture_6' => 'Traga quem você confia',
        'nurture_7' => 'A abertura está chegando',
    ];

    public function __construct(
        public WaitlistEntry $entry,
        public string $stepKey,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: self::SUBJECTS[$this->stepKey] ?? 'Limen Founding Members',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: "emails.waitlist.nurture.{$this->stepKey}",
            with: [
                // First name only — never the full/composite name.
                'firstName' => Str::of($this->entry->name)->trim()->explode(' ')->first(),
                'isPerformer' => $this->entry->isPerformer(),
                'panelUrl' => URL::route('waitlist.founder', ['invite_code' => $this->entry->invite_code]),
                'unsubscribeUrl' => URL::route('waitlist.unsubscribe', ['t' => $this->entry->invite_token]),
            ],
        );
    }
}
