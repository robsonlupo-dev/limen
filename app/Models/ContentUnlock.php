<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Desbloqueio permanente de uma peça por um membro (M.4). Nasce só no
 * ContentUnlockService, sob os locks das duas carteiras — $fillable vazio.
 *
 * $hidden user_id: o membro nunca vaza para a performer (M.13.10). As superfícies
 * dela usam FanAlias (handle/label); a única revelação de tier permitida é
 * fc_only → FC, e vem do NÍVEL da peça, não desta linha.
 */
class ContentUnlock extends Model
{
    protected $fillable = [];

    protected $hidden = ['user_id'];

    protected function casts(): array
    {
        return [
            'tokens_paid' => 'integer',
            'spend_ledger_id' => 'integer',
            'credit_ledger_id' => 'integer',
            'unlocked_at' => 'datetime',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(PerformerContent::class, 'performer_content_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
