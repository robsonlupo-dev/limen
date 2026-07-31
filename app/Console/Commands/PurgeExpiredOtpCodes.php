<?php

namespace App\Console\Commands;

use App\Services\OtpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * GC dos códigos OTP de login vencidos (Sprint 11).
 *
 * O código é credencial efêmera (TTL de 5 min) e fica em claro na coluna. A
 * expiração já vale na LEITURA (OtpService::verifyCode / isConsumable) — isto é
 * só faxina, para material de autenticação vencido não se acumular no banco.
 * Mesmo precedente de `stories:purge` e `visits:purge`.
 */
class PurgeExpiredOtpCodes extends Command
{
    protected $signature = 'otp:purge';

    protected $description = 'Apaga códigos OTP de login já vencidos.';

    public function handle(OtpService $otp): int
    {
        $deleted = $otp->purgeExpired();

        $this->info("deleted={$deleted}");

        // Só a contagem: o dígito é credencial e não entra em log (nem aqui).
        Log::info('otp:purge', ['deleted' => $deleted]);

        return self::SUCCESS;
    }
}
