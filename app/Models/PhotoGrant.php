<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido/concessão de acesso a uma foto PRIVADA da galeria (Sprint 13).
 *
 * Uma linha por par (foto, membro). `granted_at` discrimina os dois estados:
 * nulo = pedido pendente, preenchido = acesso concedido. Ver a migration.
 *
 * PRIVACIDADE — as invariantes do FanAlias e do Hard Delete:
 *  - `user_id` é chave interna e NUNCA sai para o front. `$hidden`, e as duas
 *    telas da performer (dashboard, gestão) só falam por `FanAlias`
 *    (label + handle). Expor o id reabriria a correlação que o FanAlias fecha.
 *  - As FKs ficam FORA do `$fillable`: a linha nasce só no PhotoAccessService,
 *    por atribuição direta (mesma disciplina de `favorites`/2FA), nunca de um
 *    array de request.
 *  - `granted_at` não é `$fillable` tampouco: quem aprova é o service, sob a
 *    resolução do FanAlias, não um payload.
 */
class PhotoGrant extends Model
{
    use HasFactory;

    /** Vazio de propósito: FKs e `granted_at` são escritos pelo service. */
    protected $fillable = [];

    protected $hidden = [
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
        ];
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(PerformerPhoto::class, 'performer_photo_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Acessos JÁ concedidos (o membro vê a foto). */
    public function scopeGranted(Builder $query): Builder
    {
        return $query->whereNotNull('granted_at');
    }

    /** Pedidos ainda pendentes (a performer decide). */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('granted_at');
    }
}
