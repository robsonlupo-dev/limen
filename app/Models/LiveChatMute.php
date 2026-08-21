<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um espectador silenciado pela performer numa sessão de live
 * (feat/live-room-console). Enquanto existe a linha, o membro não envia mensagens
 * na sala. UNIQUE por (live_session_id, member_id) — silenciar de novo é no-op.
 * Efêmero: some com a live (LiveSessionService) e no Hard Delete.
 */
class LiveChatMute extends Model
{
    protected $fillable = [
        'live_session_id', 'member_id',
    ];
}
