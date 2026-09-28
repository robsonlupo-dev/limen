<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma mensagem do canal de transmissão da performer (roadmap social, Onda 2).
 *
 * 1-para-muitos para os seguidores; sem resposta no canal (quem quer falar cai no
 * chat pago). `$fillable` só o `body` (texto validado); `performer_profile_id` é
 * autoridade do servidor — vem do perfil autenticado, gravado explícito no service.
 */
class BroadcastMessage extends Model
{
    protected $fillable = [
        'body',
    ];

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }
}
