<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Busca salva do membro (Sprint 12) — combinação de filtros do catálogo,
 * guardada para reaplicar depois.
 *
 * **É do MEMBRO e só dele.** A performer nunca vê a busca salva de ninguém: não
 * há relação inversa, contador, nem rota do lado dela — mesma assimetria de
 * `Favorite`, e pela mesma razão (o número/lista não é dela). Regra nova entra
 * no `SavedSearchService`, a dona única.
 *
 * FKs e conteúdo ficam FORA do `$fillable`: a linha nasce só no
 * `SavedSearchService::save()`, a partir de valores já validados (nome + o
 * allowlist de filtros) — nunca de um `create()` sobre array de request. Mesma
 * disciplina de `Favorite`/`member_photo_access`.
 */
class SavedSearch extends Model
{
    /** Teto de buscas salvas por membro. Imposto sob lock no SavedSearchService. */
    public const MAX_SAVED = 10;

    protected $fillable = [];

    /**
     * Defesa em profundidade: se um dia esta model for serializada por engano
     * para dentro de um prop, o id do membro não vai junto (ele é chave interna).
     */
    protected $hidden = ['user_id'];

    protected function casts(): array
    {
        return [
            // Os filtros voltam como array já desserializado para a tela repopular
            // os controles. O que ENTRA é filtrado pelo allowlist no service.
            'filters' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
