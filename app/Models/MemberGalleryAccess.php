<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acesso de UMA performer às fotos PRIVADAS de UM membro
 * (feat/member-gallery-access-requests, Etapa 2). Espelho invertido do
 * `PhotoGrant`: uma linha por par (membro, performer). `granted_at` nulo = pedido
 * pendente; preenchido = liberado. O acesso é POR PAR (não por foto) — liberar
 * abre todas as privadas do membro para aquela performer.
 *
 * $fillable vazio de propósito: as FKs e `granted_at` são escritas só pelo
 * MemberGalleryAccessService, por atribuição direta, nunca de um array de request
 * (mesma disciplina de PhotoGrant/favorites).
 *
 * Sem FanAlias aqui: ao contrário do PhotoGrant (onde o MEMBRO é pseudonimizado
 * para a performer), aqui quem pede é a PERFORMER — figura pública (nome de
 * palco, slug). O membro vê quem pediu; a identidade DELE segue escondida atrás
 * do FanAlias no serving, como em todo o resto.
 */
class MemberGalleryAccess extends Model
{
    protected $table = 'member_gallery_access';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
        ];
    }

    public function isGranted(): bool
    {
        return $this->granted_at !== null;
    }

    /** Pedidos ainda não decididos. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('granted_at');
    }

    /** Acessos já liberados. */
    public function scopeGranted(Builder $query): Builder
    {
        return $query->whereNotNull('granted_at');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }
}
