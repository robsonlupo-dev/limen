<?php

namespace App\Services;

use App\Jobs\PushContactToChatwoot;
use App\Models\User;
use App\Support\BrazilPhone;
use Illuminate\Support\Facades\Log;

/**
 * Opt-in de WhatsApp para o aviso de go-live (feat/signup-email-gate-and-whatsapp-optin).
 * Dona única da escrita de `whatsapp` / `whatsapp_consent_at` (via forceFill — essas
 * colunas ficam fora do $fillable, como o carimbo de consentimento exige).
 *
 * O consentimento é LGPD: a presença de `whatsapp_consent_at` é o registro de que a
 * pessoa marcou a caixa (desmarcada por padrão) e informou o número. O push ao Chatwoot
 * é enfileirado e no-op quando o CRM está desligado.
 */
class WhatsappOptInService
{
    /**
     * Registra o opt-in. Devolve o E.164 gravado, ou null se o número for inválido
     * (o chamador já validou no Form Request; esta é a última linha de defesa).
     * Resetar o sync (`whatsapp_synced_at`) garante que um número NOVO seja
     * reempurrado ao Chatwoot.
     */
    public function optIn(User $user, string $rawPhone): ?string
    {
        $e164 = BrazilPhone::toE164($rawPhone);

        if ($e164 === null) {
            return null;
        }

        $changedNumber = $user->whatsapp !== $e164;

        $user->forceFill([
            'whatsapp' => $e164,
            'whatsapp_consent_at' => now(),
            'whatsapp_synced_at' => $changedNumber ? null : $user->whatsapp_synced_at,
        ])->save();

        Log::info('whatsapp.optin', ['user_id' => $user->id]);

        PushContactToChatwoot::dispatch($user->id)->afterCommit();

        return $e164;
    }

    /**
     * Revoga o opt-in (a pessoa pediu para sair / "Agora não" depois de ter aceitado).
     * Apaga o número e o consentimento; o carimbo de sync é zerado.
     */
    public function optOut(User $user): void
    {
        $user->forceFill([
            'whatsapp' => null,
            'whatsapp_consent_at' => null,
            'whatsapp_synced_at' => null,
        ])->save();

        Log::info('whatsapp.optout', ['user_id' => $user->id]);
    }
}
