<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma foto da galeria do perfil da performer (Sprint 10).
 *
 * Nasceu como vitrine pública (na categoria de `avatar_path`/`cover_path`). No
 * Sprint 13 ganhou VISIBILIDADE por foto: `is_private = true` a esconde do
 * público (blur, sem bytes) e a libera só para quem tem `PhotoGrant` aprovado —
 * ou a própria dona. O paywall NÃO é CSS: a tela desenha o blur, mas quem decide
 * se os bytes saem é o `PhotoAccessService` (dona única), lido pelo serving E
 * pelo presenter — as duas superfícies não podem divergir, senão o par
 * "borrado na tela / 200 no serving" vira oráculo (precedente do Story, § 2.3).
 *
 * O ciclo de vida (cap de 6, ordem, upload higienizado, delete) mora no
 * PerformerPhotoService; a visibilidade e os grants, no PhotoAccessService. Este
 * model é só a linha. `performer_profile_id` E `is_private` ficam FORA do
 * $fillable: a foto nasce pela relação `$profile->photos()` (sempre pública), e a
 * privacidade muda só pelo endpoint dedicado, por atribuição direta — mesma
 * disciplina anti mass-assignment do resto do projeto.
 */
class PerformerPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'path',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_private' => 'boolean',
        ];
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    /**
     * Pedidos e concessões de acesso a esta foto (só faz sentido quando privada).
     * Linha com `granted_at` nulo é pedido pendente; preenchido, acesso concedido.
     */
    public function grants(): HasMany
    {
        return $this->hasMany(PhotoGrant::class);
    }
}
