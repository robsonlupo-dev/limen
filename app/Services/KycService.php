<?php

namespace App\Services;

use App\Jobs\SendKycApprovedEmail;
use App\Jobs\SendKycRejectedEmail;
use App\Jobs\SendWelcomeEmail;
use App\Models\IdentityVerification;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class KycService
{
    public function approve(IdentityVerification $verification, ?int $reviewedBy = null): void
    {
        DB::transaction(function () use ($verification, $reviewedBy) {
            $verification->update([
                'status' => 'approved',
                'age_confirmed' => true,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
            ]);

            $user = $verification->user;

            $user->status = 'active';
            $user->age_verified_at = now();
            $user->save();

            $user->performerProfile?->update(['is_verified' => true]);

            Audit::log('kyc.approved', $verification, [
                'reviewed_by' => $reviewedBy,
            ]);

            // afterCommit: o dispatch acontece dentro da transação (que pode
            // estar aninhada na do chamador, como no painel admin) — sem isso
            // um worker rápido leria o performer ainda 'pending', ou o e-mail
            // sairia mesmo com rollback da transação externa.
            SendKycApprovedEmail::dispatch($user)->afterCommit();

            // Carta dos fundadores. Aqui, e não em dois lugares, porque este é o
            // ÚNICO ponto de aprovação de KYC do produto — vale para a performer
            // (documento + selfie pelo Didit) e para o membro, que nasce
            // `pending_kyc` e só vira `active` quando a selfie passa por aqui
            // (ver AuthService::registerConsumer). Não é preciso pendurar nada
            // na verificação de e-mail: o KYC do membro é obrigatório.
            //
            // Quem decide se envia é o job (idempotência + exclusão de admin);
            // este dispatch é incondicional de propósito, para a regra ter uma
            // dona só. Mesmo `afterCommit` do e-mail acima: sem ele um worker
            // rápido leria o usuário ainda `pending`, e a carta sairia mesmo se
            // a transação externa (painel admin) desse rollback.
            SendWelcomeEmail::dispatch($user)->afterCommit();
        });
    }

    public function reject(IdentityVerification $verification, ?string $reason = null, ?int $reviewedBy = null): void
    {
        DB::transaction(function () use ($verification, $reason, $reviewedBy) {
            $verification->update([
                'status' => 'rejected',
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
            ]);

            Audit::log('kyc.rejected', $verification, [
                'reason' => $reason,
                'reviewed_by' => $reviewedBy,
            ]);

            // Mesma razão do afterCommit do approve.
            SendKycRejectedEmail::dispatch($verification->user, $reason)->afterCommit();
        });
    }
}
