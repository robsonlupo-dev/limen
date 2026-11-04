<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link personalizado de indicação (feat/referral-custom-links):
 * thelimen.com.br/r/{slug} → indicação do dono. IMUTÁVEL por desenho (estilo
 * Airbnb): nenhum caminho de escrita altera ou apaga um slug criado — a única
 * escrita é a criação, e ela passa SÓ pelo ReferralLinkService ($fillable
 * vazio; forceFill no service). Ver a migration para o racional completo.
 */
class ReferralLink extends Model
{
    protected $fillable = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** URL pública do link, no host da requisição (nunca o app.url do alias dev). */
    public function url(): string
    {
        return url('/r/'.$this->slug);
    }
}
