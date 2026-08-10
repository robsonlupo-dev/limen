<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visita de uma PERFORMER ao perfil de um MEMBRO (sentido inverso de
 * ProfileVisit — ver a migration e ProfileVisitService).
 *
 * `performer_profile_id` (quem visitou) e `member_id` (quem foi visitado) vêm
 * do request autenticado, nunca do payload — a performer só age a partir do
 * catálogo, e o alvo é resolvido contra os membros visíveis (ResolvesCatalogMember).
 * Por isso ficam fora do $fillable: não se forja visita alheia.
 */
class MemberProfileVisit extends Model
{
    protected $fillable = [
        'visited_at',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
        ];
    }

    /** A performer que visitou. Identidade PÚBLICA — exposta ao membro. */
    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    /** O membro visitado. Chave interna — nunca sai numa superfície da performer. */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }
}
