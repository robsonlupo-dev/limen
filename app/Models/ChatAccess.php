<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acesso ao chat comprado por um membro sem assinatura (50 tokens/janela por
 * performer). Assinantes têm chat livre e não geram linha aqui.
 *
 * Os métodos de estado são POR TEMPO, não pelo enum `status`: o status é
 * carimbado pelo job diário, então entre uma execução e outra uma linha pode
 * estar 'active' com expires_at já vencido. As checagens de acesso em tempo real
 * têm de olhar os prazos.
 */
class ChatAccess extends Model
{
    protected $table = 'chat_access';

    protected $fillable = [
        'member_id',
        'performer_profile_id',
        'unlocked_at',
        'expires_at',
        'grace_ends_at',
        'renewed_at',
        'status',
        'last_idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'unlocked_at' => 'datetime',
            'expires_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'renewed_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    /** Acesso total (envio + leitura): dentro da janela paga. */
    public function hasFullAccess(): bool
    {
        return $this->status !== 'deleted' && now()->lessThan($this->expires_at);
    }

    /** Carência: vencido mas ainda dentro do grace — leitura bloqueada, sem envio. */
    public function isInGrace(): bool
    {
        return $this->status !== 'deleted'
            && now()->greaterThanOrEqualTo($this->expires_at)
            && now()->lessThan($this->grace_ends_at);
    }

    /** Passou a carência: o job deve soft-deletar as mensagens e marcar deleted. */
    public function isPurgeable(): bool
    {
        return $this->status !== 'deleted' && now()->greaterThanOrEqualTo($this->grace_ends_at);
    }
}
