<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    // Só o corpo vem de input do usuário. sender_id e os vínculos de ledger são
    // sempre setados pelo ChatService (forceFill), nunca por mass assignment —
    // deixá-los fora do fillable fecha a porta para forjar autor ou cobrança.
    protected $fillable = [
        'conversation_id',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
