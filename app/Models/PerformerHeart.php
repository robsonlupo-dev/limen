<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CORAÇÃO — o "interesse grátis" do catálogo de membros. Ver a migration e
 * PerformerHeartService. Direção performer → membro: `performer_profile_id`
 * curtiu, `member_id` recebeu.
 *
 * Diferente de PerformerInterest: sem `status`, sem cota, sem cooldown, sem
 * desbloqueio pago — é um like grátis e sempre visível ao membro. A linha nasce
 * só no serviço; as FKs ficam no fillable porque o serviço as escreve por
 * firstOrCreate (member_id vem do handle resolvido, nunca de payload cru).
 */
class PerformerHeart extends Model
{
    protected $fillable = [
        'performer_profile_id',
        'member_id',
    ];

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }
}
