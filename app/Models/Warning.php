<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Advertência de moderação (feat/moderator-actions). Registro append-only: uma
 * linha por advertência emitida. O `$fillable` traz só o motivo; alvo, autor e
 * denúncia de origem são setados server-side pela ModeratorActionService — nunca
 * por mass assignment (mesmo padrão de reviewed_by das denúncias).
 */
class Warning extends Model
{
    protected $fillable = ['reason'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
