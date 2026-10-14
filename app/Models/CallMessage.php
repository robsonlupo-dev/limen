<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensagem de TEXTO do chat da chamada 1:1 (feat/in-call-chat). Só `created_at`
 * (a mensagem é imutável; não há updated_at). O corpo fica retido para moderação.
 *
 * @property int $id
 * @property int $call_session_id
 * @property int $sender_id
 * @property string $body
 */
class CallMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['call_session_id', 'sender_id', 'body'];

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
