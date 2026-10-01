<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Config do Fã-Clube de uma performer (Onda 4, docs/FORK_ASSINATURA.md §3/§9): se está
 * aberto e os preços em token (público e, opcional, VIP para Black/FC). Uma linha por
 * performer. Dona única da escrita: App\Services\FanclubService (forceFill após validar);
 * $fillable vazio de propósito — preço/estado não entram por mass-assignment.
 *
 * Invariante de negócio (garantida no service/Form Request, não no banco): se
 * `vip_enabled`, então `price_vip_tokens <= price_public_tokens` (público ≥ VIP).
 */
class FanclubSettings extends Model
{
    protected $table = 'fanclub_settings';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'price_public_tokens' => 'integer',
            'vip_enabled' => 'boolean',
            'price_vip_tokens' => 'integer',
        ];
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    /** Aberto para assinatura? (aberto E com preço público configurado). */
    public function isSubscribable(): bool
    {
        return $this->is_open === true && $this->price_public_tokens !== null;
    }

    /**
     * Preço (em token) que SE APLICA a este membro, e qual faixa. O VIP só vale se a
     * performer o ligou E o membro é Black/FC. Retorna [price, 'public'|'vip'].
     *
     * @return array{0:int,1:string}
     */
    public function priceFor(?User $member): array
    {
        $public = (int) $this->price_public_tokens;

        if ($this->vip_enabled && $this->price_vip_tokens !== null && $member !== null) {
            $slug = $member->activeCircle()?->slug;
            if (in_array($slug, ['black', 'founders_circle'], true)) {
                return [(int) $this->price_vip_tokens, 'vip'];
            }
        }

        return [$public, 'public'];
    }
}
