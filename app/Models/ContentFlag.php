<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sinalização automática de conteúdo (feat/flagged-content-queue, Fase 4c).
 *
 * Uma linha por evento de conduta bloqueada. O `$fillable` é vazio de propósito:
 * TODA escrita é server-side (ContentFlagService / moderação), nunca por mass
 * assignment — mesma disciplina de Warning/Report. O corpo do conteúdo NUNCA
 * entra aqui; só o HMAC da regra.
 */
class ContentFlag extends Model
{
    protected $fillable = [];

    /** Fontes conhecidas do sinal. */
    public const SOURCE_CHAT = 'chat';
    public const SOURCE_LIVE_CHAT = 'live_chat';
    // Sinais NÃO-chat (feat/flagged-content-more-sources, Fase 4c-b): a bio pública
    // do membro e o apelido, quando tropeçam na CONDUTA do filtro.
    public const SOURCE_PROFILE_TEXT = 'profile_text';
    public const SOURCE_NICKNAME = 'nickname';

    /** Categorias do filtro — só conduta é sinalizada por ora. */
    public const CATEGORY_CONDUCT = 'conduct';

    /** Estados do flag na fila. */
    public const STATUS_PENDING = 'pending';
    public const STATUS_DISMISSED = 'dismissed';

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Pendentes de revisão — o que a fila mostra. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
