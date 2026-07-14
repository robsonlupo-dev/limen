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

    /** Per-step, per-role subject line. Role key: 'membro' | 'performer'. */
    private const SUBJECTS = [
        'nurture_1' => ['membro' => 'Você está dentro.',              'performer' => 'Sua candidatura está guardada.'],
        'nurture_2' => ['membro' => 'Silêncio tem valor.',           'performer' => 'Quem cria, merece mais.'],
        'nurture_3' => ['membro' => 'O que separa você dos outros.', 'performer' => 'Discrição não é opcional aqui.'],
        'nurture_4' => ['membro' => 'Conexão real é rara.',          'performer' => 'Verificação significa poder.'],
        'nurture_5' => ['membro' => 'Quem indica, eleva.',           'performer' => 'Quem você traz, reflete quem você é.'],
        'nurture_6' => ['membro' => 'Um mês.',                       'performer' => 'Trinta dias de paciência.'],
        'nurture_7' => ['membro' => 'Você vai entender.',            'performer' => 'O portal abre para quem esperou.'],
    ];

    public function __construct(
        public WaitlistEntry $entry,
        public string $stepKey,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->resolveSubject());
    }

    public function content(): Content
    {
        return new Content(
            view: "emails.waitlist.nurture.{$this->stepKey}",
            with: [
                // First name only — never the full/composite name.
                'firstName' => Str::of($this->entry->name)->trim()->explode(' ')->first(),
                'isPerformer' => $this->entry->isPerformer(),
                'subject' => $this->resolveSubject(),
                'ctaUrl' => $this->ctaUrl(),
                'unsubscribeUrl' => URL::route('waitlist.unsubscribe', ['t' => $this->entry->invite_token]),
            ],
        );
    }

    /** 'membro' for members, 'performer' for performers — the UTM/subject key. */
    private function role(): string
    {
        return $this->entry->isPerformer() ? 'performer' : 'membro';
    }

    private function resolveSubject(): string
    {
        return self::SUBJECTS[$this->stepKey][$this->role()] ?? 'Limen Founding Members';
    }

    /**
     * The single CTA target: the founder panel (/f/{code}) — never a direct
     * invite link (Limen discretion rule; referral lives only on the panel),
     * tagged with a role- and day-specific UTM campaign (e.g. membro_dia3,
     * performer_dia7). The day is read from the configured cadence so the label
     * always matches when the email actually goes out.
     */
    private function ctaUrl(): string
    {
        return URL::route('waitlist.founder', [
            'invite_code' => $this->entry->invite_code,
            'utm_source' => 'nurturing',
            'utm_medium' => 'email',
            'utm_campaign' => "{$this->role()}_dia{$this->stepDay()}",
        ]);
    }

    /** The cadence day (after_days) this step is scheduled for; 0 if unknown. */
    private function stepDay(): int
    {
        foreach (config('waitlist.nurture', []) as $step) {
            if (($step['key'] ?? null) === $this->stepKey) {
                return (int) $step['after_days'];
            }
        }

        return 0;
    }
}
