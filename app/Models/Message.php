<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    // Retenção: ao vencer a carência do acesso, a mensagem é soft-deletada
    // (oculta na UI, retida no servidor p/ trilha de abuso/legal). Nunca
    // hard-delete — ver docs e a decisão de retenção do PO.
    use SoftDeletes;

    // Só o corpo vem de input do usuário. sender_id é setado pelo ChatService
    // (forceFill), nunca por mass assignment — fora do fillable p/ não forjar autor.
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
