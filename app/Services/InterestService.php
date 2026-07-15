<?php

namespace App\Services;

use App\Exceptions\InterestException;
use App\Models\AuditLog;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sistema de Interesse Controlado (Performer → Membro).
 * Ver docs/INTEREST_SYSTEM_SPEC.md.
 *
 * - A performer envia um sinal binário de interesse (sem texto).
 * - O membro paga tokens para desbloquear (revelar) quem enviou.
 * - O débito do desbloqueio é 100% plataforma via token_ledger (append-only);
 *   a performer NÃO é creditada aqui.
 */
class InterestService
{
    public function __construct(private TokenService $tokenService) {}

    private function unlockCost(): int
    {
        return (int) config('interest.unlock_cost');
    }

    private function dailyLimit(): int
    {
        return (int) config('interest.daily_limit');
    }

    private function cooldownDays(): int
    {
        return (int) config('interest.cooldown_days');
    }

    /**
     * A performer envia interesse a um membro.
     *
     * Opt-out do membro é silencioso: o envio percorre exatamente as mesmas
     * checagens e persiste a linha, mas com status 'suppressed' — invisível na
     * caixa do membro. Persistir é o que mantém o segredo: cooldown e limite
     * diário passam a contar igual ao de um membro comum. Retornando null (sem
     * linha), a performer detectava o opt-out reenviando e não tomando cooldown
     * (docs/INTEREST_SYSTEM_SPEC.md, seção 6).
     *
     * @throws InterestException target inválido, cooldown ativo ou limite diário
     */
    public function send(PerformerProfile $performerProfile, User $member): PerformerInterest
    {
        // Só é possível demonstrar interesse em um membro (consumer).
        if ($member->role !== 'consumer') {
            throw InterestException::invalidTarget();
        }

        return DB::transaction(function () use ($performerProfile, $member) {
            // Serializa os envios DESTA performer travando a linha do perfil
            // como primeira instrução da transação. Sends concorrentes da mesma
            // performer passam a esperar; e, por ser a 1ª leitura, o read-view
            // das checagens abaixo (cooldown/limite) só se forma após o commit
            // do envio anterior — tornando-as inescapáveis por corrida.
            PerformerProfile::where('id', $performerProfile->id)->lockForUpdate()->first();

            $cooldownDays = $this->cooldownDays();

            // Cooldown: nenhum interesse desta performer a este membro dentro
            // da janela, independentemente do status.
            $recent = PerformerInterest::where('performer_profile_id', $performerProfile->id)
                ->where('member_id', $member->id)
                ->where('sent_at', '>=', now()->subDays($cooldownDays))
                ->exists();

            if ($recent) {
                throw InterestException::cooldown($cooldownDays);
            }

            // Limite diário por performer (piso; escala por tier — follow-up).
            $sentToday = PerformerInterest::where('performer_profile_id', $performerProfile->id)
                ->where('sent_at', '>=', now()->startOfDay())
                ->count();

            if ($sentToday >= $this->dailyLimit()) {
                throw InterestException::dailyLimit($this->dailyLimit());
            }

            // Se o membro já desbloqueou esta performer em um interesse anterior,
            // o novo já nasce revelado (grátis): paga-se uma vez por performer.
            $alreadyUnlocked = PerformerInterest::where('performer_profile_id', $performerProfile->id)
                ->where('member_id', $member->id)
                ->where('status', 'unlocked')
                ->exists();

            // Releitura travada: o opt-out pode ter sido ligado entre a carga do
            // request e este ponto. Suprimir vence o auto-unlock — quem optou por
            // sair não recebe, mesmo tendo desbloqueado esta performer antes.
            $optOut = (bool) User::where('id', $member->id)->lockForUpdate()->value('interests_opt_out');

            $status = match (true) {
                $optOut => 'suppressed',
                $alreadyUnlocked => 'unlocked',
                default => 'sent',
            };

            $interest = PerformerInterest::create([
                'performer_profile_id' => $performerProfile->id,
                'member_id' => $member->id,
                'status' => $status,
                'sent_at' => now(),
                'unlocked_at' => $status === 'unlocked' ? now() : null,
            ]);

            AuditLog::create([
                'user_id' => $performerProfile->user_id,
                'action' => 'interest.sent',
                'subject_type' => PerformerInterest::class,
                'subject_id' => $interest->id,
                'ip' => request()->ip(),
                'metadata' => [
                    'member_id' => $member->id,
                    'auto_unlocked' => $status === 'unlocked',
                    'suppressed' => $optOut,
                ],
            ]);

            return $interest;
        });
    }

    /**
     * O membro paga para desbloquear (revelar) a performer.
     *
     * Idempotente: reprocessar nunca cobra em dobro — o débito só ocorre se a
     * linha ainda estiver 'sent' após travá-la. Um desbloqueio prévio do mesmo
     * par revela de graça (paga uma vez por performer).
     *
     * @throws \App\Exceptions\InsufficientBalanceException saldo insuficiente
     */
    public function unlock(User $member, PerformerInterest $interest): PerformerInterest
    {
        // Fast-path fora da transação (sem locks).
        if ($interest->isUnlocked()) {
            return $interest;
        }

        return DB::transaction(function () use ($member, $interest) {
            // Trava TODAS as linhas do par (performer, membro) numa única
            // leitura ordenada por id. Isso serializa desbloqueios concorrentes
            // da MESMA performer (dois interesses distintos) — sem o lock do par
            // ambos leriam priorUnlock=false e cobrariam 15 duas vezes. A ordem
            // determinística evita deadlock; a leitura travada é sempre fresca
            // (imune ao snapshot de REPEATABLE READ).
            $pairRows = PerformerInterest::where('performer_profile_id', $interest->performer_profile_id)
                ->where('member_id', $member->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // O interesse pertence a este membro? (filtro por member_id acima).
            $locked = $pairRows->firstWhere('id', $interest->id);
            if (! $locked) {
                throw new \InvalidArgumentException('Interest does not belong to this member.');
            }

            // Suprimido é invisível ao membro: para ele a linha não existe, então
            // não há o que desbloquear. Guarda de defesa — o controller já 404.
            if ($locked->isSuppressed()) {
                throw new \InvalidArgumentException('Interest is not visible to this member.');
            }

            // Re-checagem de idempotência após adquirir o lock.
            if ($locked->isUnlocked()) {
                return $locked;
            }

            // Já pagou por esta performer antes? Revela de graça. Avaliado sobre
            // o conjunto já travado — visão consistente e serializada.
            $priorUnlock = $pairRows->contains(fn (PerformerInterest $r) => $r->status === 'unlocked');

            $ledgerId = null;

            if (! $priorUnlock) {
                $cost = $this->unlockCost();

                $entry = $this->tokenService->debit(
                    $member,
                    $cost,
                    'spend_interest_unlock',
                    PerformerInterest::class,
                    $locked->id,
                    "Desbloqueio de interesse #{$locked->id}",
                );

                $ledgerId = $entry->id;
            }

            $locked->update([
                'status' => 'unlocked',
                'unlocked_at' => now(),
                'unlock_ledger_id' => $ledgerId,
            ]);

            AuditLog::create([
                'user_id' => $member->id,
                'action' => 'interest.unlocked',
                'subject_type' => PerformerInterest::class,
                'subject_id' => $locked->id,
                'ip' => request()->ip(),
                'metadata' => [
                    'performer_profile_id' => $locked->performer_profile_id,
                    'cost' => $priorUnlock ? 0 : $this->unlockCost(),
                    'free_reveal' => $priorUnlock,
                ],
            ]);

            return $locked;
        });
    }

    public function setOptOut(User $member, bool $optOut): void
    {
        $member->update(['interests_opt_out' => $optOut]);

        AuditLog::create([
            'user_id' => $member->id,
            'action' => 'interest.opt_out',
            'subject_type' => User::class,
            'subject_id' => $member->id,
            'ip' => request()->ip(),
            'metadata' => ['opt_out' => $optOut],
        ]);
    }
}
