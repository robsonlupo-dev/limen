<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma mensagem do chat da live (feat/live-room-console). Persistida para a
 * moderação resolver o autor (a performer silencia sem que o handle do FanAlias
 * seja exposto aos espectadores). Efêmera: some quando a live encerra
 * (LiveSessionService) e no Hard Delete (DeletionService).
 *
 * `sender_id` é a chave interna (nunca sai ao front); a exibição é sempre por
 * FanAlias label (membro) ou stage_name (performer) — resolvido no LiveChatService.
 */
class LiveChatMessage extends Model
{
    protected $fillable = [
        'live_session_id', 'sender_id', 'is_performer', 'body',
    ];

    protected function casts(): array
    {
        return [
            'is_performer' => 'boolean',
        ];
    }

    public function liveSession(): BelongsTo
    {
        return $this->belongsTo(LiveSession::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
