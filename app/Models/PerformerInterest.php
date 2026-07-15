<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformerInterest extends Model
{
    protected $fillable = [
        'performer_profile_id', 'member_id', 'status',
        'sent_at', 'unlocked_at', 'unlock_ledger_id',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'unlocked_at' => 'datetime',
        ];
    }

    public function isUnlocked(): bool
    {
        return $this->status === 'unlocked';
    }

    /**
     * Interesse enviado a um membro que optou por sair. Existe apenas para que
     * cooldown e limite diário contem (sem vazar o opt-out à performer) e é
     * invisível ao membro — nunca listar nem permitir desbloqueio.
     */
    public function isSuppressed(): bool
    {
        return $this->status === 'suppressed';
    }

    /** Interesses que o membro pode de fato ver na caixa dele. */
    public function scopeVisibleToMember(Builder $query): Builder
    {
        return $query->where('status', '!=', 'suppressed');
    }

    /**
     * "Existia um desbloqueio deste par ANTES deste envio?" — a condição que o
     * InterestService::send() avalia para decidir entre 'unlocked' (auto-revela,
     * grátis) e 'sent'. Reconstruí-la é o que permite mascarar uma linha
     * 'suppressed' na visão da performer: ela precisa ver exatamente o status
     * que a linha teria se o membro não tivesse optado por sair.
     *
     * Ponto-no-tempo (unlocked_at <= sent_at), nunca "desbloqueado hoje": um
     * membro que optou por sair continua enxergando (e podendo desbloquear) os
     * interesses anteriores ao opt-out. Um desbloqueio POSTERIOR a este envio
     * não teria auto-revelado nada, e contá-lo mudaria o status exibido de
     * 'sent' para 'revelado' no momento em que o membro pagasse — vazando o
     * opt-out justamente para quem se quis esconder.
     *
     * O empate (mesmo segundo) resolve como "havia desbloqueio", exibindo
     * 'unlocked'. É o lado seguro: errar para 'revelado' dá à performer um
     * falso positivo inócuo, enquanto errar para 'sent' é exatamente o sinal
     * que ela lê como opt-out.
     */
    private static function priorUnlockExists(): \Closure
    {
        return function ($query) {
            $query->selectRaw('1')
                ->from('performer_interests as prior')
                ->whereColumn('prior.performer_profile_id', 'performer_interests.performer_profile_id')
                ->whereColumn('prior.member_id', 'performer_interests.member_id')
                ->where('prior.status', 'unlocked')
                ->whereColumn('prior.unlocked_at', '<=', 'performer_interests.sent_at');
        };
    }

    /**
     * Interesses que a performer vê como revelados: os realmente desbloqueados
     * MAIS os suprimidos que teriam nascido auto-revelados se o membro não
     * tivesse optado por sair.
     *
     * É a ÚNICA fonte do status exibido à performer — contador do topo e badge
     * da lista saem daqui. Se cada um aplicasse a sua própria regra, bastaria a
     * discordância entre eles ("2 revelados" com 3 badges) para entregar o
     * opt-out: a inconsistência é, ela própria, o vazamento.
     *
     * Fora deste conjunto, tudo é exibido como 'sent' — inclusive o suprimido
     * comum. Assim 'suppressed' nunca escapa para a view.
     */
    public function scopeDisplayedAsUnlocked(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', 'unlocked')
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('status', 'suppressed')
                    ->whereExists(self::priorUnlockExists()));
        });
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function unlockLedger(): BelongsTo
    {
        return $this->belongsTo(TokenLedger::class, 'unlock_ledger_id');
    }
}
