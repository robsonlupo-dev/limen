<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ChatwootClient;
use App\Services\ReferralService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Empurra para o Chatwoot o contato de quem fez opt-in de WhatsApp para o aviso de
 * go-live (feat/signup-email-gate-and-whatsapp-optin). Enfileirado após o opt-in.
 *
 * - No-op quando o Chatwoot está desligado (`chatwoot.enabled=false`): o opt-in já
 *   ficou gravado no banco (fonte da verdade); o push é só uma cópia para o time
 *   operar. Assim a feature funciona hoje e o sync liga quando o PO preencher as
 *   credenciais no `.env`.
 * - Idempotente: só empurra quem ainda tem consentimento e não foi sincronizado
 *   (`whatsapp_synced_at` nulo); marca o carimbo ao concluir.
 * - NUNCA envia dado real sensível: manda o nome (já auto-declarado) + o número que
 *   a própria pessoa deu; o rótulo marca o segmento (optin-golive).
 */
class PushContactToChatwoot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $userId) {}

    public function handle(ChatwootClient $chatwoot, ReferralService $referrals): void
    {
        if (! $chatwoot->isEnabled()) {
            return; // desligado: o opt-in no banco basta por enquanto
        }

        $user = User::find($this->userId);

        if (! $user || blank($user->whatsapp) || blank($user->whatsapp_consent_at)) {
            return; // sem consentimento vigente: nada a empurrar
        }

        if (filled($user->whatsapp_synced_at)) {
            return; // já sincronizado
        }

        // Rótulos: segmento do opt-in + papel (membro/criadora) para o time filtrar.
        $labels = [
            (string) config('chatwoot.optin_label', 'optin-golive'),
            $user->role === 'performer' ? 'criadora' : 'membro',
        ];

        $contactId = $chatwoot->upsertContact(
            $referrals->displayLabelFor($user) ?: 'Pré-cadastro Limen',
            $user->whatsapp,
            $labels,
        );

        if ($contactId !== null) {
            $user->forceFill(['whatsapp_synced_at' => now()])->save();
        }
    }
}
