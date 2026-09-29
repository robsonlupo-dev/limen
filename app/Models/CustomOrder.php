<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encomenda de conteúdo sob medida com ESCROW (Onda 4, §4.3). O membro pede (descrição +
 * valor); a performer aceita/recusa; no aceite o membro é debitado (escrow); a performer
 * entrega (peça do cofre); depois de aprovação OU do fim da janela de contestação o
 * dinheiro libera 80/20; recusa/expiração/não-entrega/disputa-a-favor estorna 100%.
 *
 * Dona ÚNICA do ciclo + contabilidade do escrow: App\Services\CustomOrderService. Campos
 * de máquina de estado (status, *_at, escrow_settled, performer_content_id, ledger ids)
 * ficam FORA do $fillable — só o service escreve por forceFill após decisão de domínio.
 *
 * `member_id` é chave INTERNA (FK users), como no ledger; a exposição à performer é
 * SEMPRE via FanAlias (M.13.10). `escrow_settled` é o invariante de idempotência: todo
 * movimento one-time (release/refund) só ocorre com escrow_settled=false e o marca true,
 * sob lockForUpdate — reprocessar o cron nunca duplica saldo.
 */
class CustomOrder extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_RELEASED = 'released';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /** Estados "ativos" (contam para o teto por membro/par e ocupam a fila). */
    public const ACTIVE_STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_ACCEPTED,
        self::STATUS_DELIVERED, self::STATUS_DISPUTED,
    ];

    /** Só o que a criação legítima precisa; estado é do service (forceFill). */
    protected $fillable = [
        'member_id', 'performer_profile_id', 'description', 'offered_price_tokens',
    ];

    protected function casts(): array
    {
        return [
            'offered_price_tokens' => 'integer',
            'escrow_settled' => 'boolean',
            'accepted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'dispute_deadline_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function deliveredContent(): BelongsTo
    {
        return $this->belongsTo(PerformerContent::class, 'performer_content_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isRequested(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function isDisputed(): bool
    {
        return $this->status === self::STATUS_DISPUTED;
    }

    /**
     * A peça entregue está PRONTA para o membro ver? Foto fica ready na hora; vídeo passa
     * pelo job de sanitização (pode estar processing ou ter falhado). É o invariante que
     * impede liberar o escrow por conteúdo que o membro nunca conseguiu ver — o mesmo que
     * o cron (autoReleaseDelivered) já respeita ao estornar mídia não-pronta.
     */
    public function deliveredIsReady(): bool
    {
        return $this->deliveredContent?->isReady() === true;
    }
}
