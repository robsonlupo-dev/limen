<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visita ao perfil de uma performer. Só existe linha quando o visitante NÃO
 * tem Ghost Mode — ver ProfileVisitService.
 *
 * `visitor_id` é a chave interna e nunca sai daqui: a tela da performer mostra
 * FanAlias, como gorjetas e seguidores.
 */
class ProfileVisit extends Model
{
    // visitor_id vem do request autenticado, nunca do payload — mesma regra do
    // sender_id em Message. Fora do fillable para não se forjar visita alheia.
    protected $fillable = [
        'performer_profile_id',
        'visited_at',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
        ];
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'visitor_id');
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }
}
