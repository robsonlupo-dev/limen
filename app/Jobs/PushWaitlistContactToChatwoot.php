<?php

namespace App\Jobs;

use App\Models\WaitlistEntry;
use App\Services\ChatwootClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Empurra para o Chatwoot o contato da lista de espera que fez opt-in de WhatsApp
 * (feat/waitlist-continue-screen). Espelha o PushContactToChatwoot dos usuários,
 * mas lê do WaitlistEntry (pré-lançamento ainda não tem conta).
 *
 * - No-op quando o Chatwoot está desligado (config): o opt-in já está gravado no
 *   banco (fonte da verdade); o push é só cópia para o time operar.
 * - Idempotente: só empurra quem ainda tem consentimento e não foi sincronizado.
 * - Rótulos: optin-golive + papel (membro/criadora) + lista-de-espera.
 */
class PushWaitlistContactToChatwoot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $entryId) {}

    public function handle(ChatwootClient $chatwoot): void
    {
        if (! $chatwoot->isEnabled()) {
            return;
        }

        $entry = WaitlistEntry::find($this->entryId);

        if (! $entry || blank($entry->whatsapp) || blank($entry->whatsapp_consent_at)) {
            return;
        }

        if (filled($entry->whatsapp_synced_at)) {
            return;
        }

        $labels = [
            (string) config('chatwoot.optin_label', 'optin-golive'),
            $entry->isPerformer() ? 'criadora' : 'membro',
            'lista-de-espera',
        ];

        $name = (string) Str::of($entry->name)->trim()->explode(' ')->first();

        $contactId = $chatwoot->upsertContact($name !== '' ? $name : 'Lista de espera Limen', $entry->whatsapp, $labels);

        if ($contactId !== null) {
            $entry->forceFill(['whatsapp_synced_at' => now()])->save();
        }
    }
}
