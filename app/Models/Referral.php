<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma indicação = uma pessoa indicada (feat/referral-program). O vínculo é gravado
 * no cadastro e é imutável; conversão e hold evoluem o `status`. Ver
 * `docs/PROGRAMA_INDICACAO.md`.
 */
class Referral extends Model
{
    // Escrita só pelo ReferralService (nunca payload de request) — mesma disciplina
    // dos campos de autoridade do projeto. $guarded vazio seria mass-assignment.
    protected $fillable = [
        'referrer_user_id',
        'referred_user_id',
        'referred_role_at_signup',
        'status',
        'kyc_approved_at',
        'qualified_at',
        'hold_until',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'kyc_approved_at' => 'datetime',
            'qualified_at' => 'datetime',
            'hold_until' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(ReferralReward::class);
    }
}
