<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma peça de conteúdo permanente (M.4/M.13.13). Nível de acesso + preço em
 * tokens; desbloqueio permanente via ContentUnlock. A regra de "quem alcança"
 * mora no ContentVisibilityService (dona única), NUNCA aqui nem no controller.
 *
 * $fillable vazio de propósito: nível e preço são escolha da performer mas
 * server-validados e gravados no PerformerContentService (disciplina de
 * is_private/is_invite/discrete_mode); path e content_hash vêm do Store. Nada
 * nasce de array de request.
 */
class PerformerContent extends Model
{
    protected $table = 'performer_content';

    public const KIND_PHOTO = 'photo';

    // Níveis (M.13.13). O tier mínimo por nível vive no ContentVisibilityService.
    public const LEVEL_OPEN = 'open';

    public const LEVEL_PREMIUM = 'premium';

    public const LEVEL_EXCLUSIVE = 'exclusive';

    public const LEVEL_FC_ONLY = 'fc_only';

    public const LEVELS = [self::LEVEL_OPEN, self::LEVEL_PREMIUM, self::LEVEL_EXCLUSIVE, self::LEVEL_FC_ONLY];

    protected $fillable = [];

    // path é o layout do disco; content_hash é prova, não conteúdo — nenhum sai no JSON.
    protected $hidden = ['path', 'content_hash'];

    protected function casts(): array
    {
        return [
            'price_tokens' => 'integer',
        ];
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function unlocks(): HasMany
    {
        return $this->hasMany(ContentUnlock::class);
    }
}
