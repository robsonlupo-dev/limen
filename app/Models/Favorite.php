<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Favorito do membro — "salvar para ver depois".
 *
 * **A performer NUNCA sabe que foi favoritada.** Não é uma preferência de UI: é
 * o produto. Follow é o gesto público (ela conta seguidores, e a partir do Piso
 * de Anonimato vê a lista); favorito é o gesto privado, e a diferença entre os
 * dois é a única razão de esta tabela existir ao lado de `follows`.
 *
 * Consequências que valem para quem mexer aqui:
 *
 *  - **Não existe relação inversa em `PerformerProfile`** (ver o comentário
 *    lá). `$profile->favorites` seria a porta pela qual um `withCount` entraria
 *    num resource dela sem ninguém notar.
 *  - **Nenhum contador, em superfície nenhuma.** Nem no perfil dela, nem no
 *    painel, nem em faixa. Faixa resolve k-anonimato de PERTENCIMENTO e aqui o
 *    problema não é esse — é que o número em si não é dela.
 *  - **Nada disto entra em `audit_logs`.** `audit_logs` é a única tabela que o
 *    DeletionService preserva intacta, com o IP do membro em claro ao lado:
 *    uma linha `favorite.added` com `subject_id = performer_profile_id` seria a
 *    cópia permanente do mapa de interesses que esta tabela apaga no Hard
 *    Delete — o mesmo raciocínio que mantém o SLUG do Estilo de Vida fora do
 *    audit (ver `App\Support\LifestyleTier` e `DeletionService`, onde o evento
 *    grava só `disclosed`, nunca o slug) e que apaga `profile_visits` inteira.
 *
 * FKs fora do `$fillable`: a linha só nasce no FavoriteService, a partir de
 * objetos `User`/`PerformerProfile` já resolvidos — nunca de um array de
 * request. Mesma disciplina de `member_photo_access`.
 */
class Favorite extends Model
{
    /**
     * Sem `updated_at`: a linha nasce e morre, nunca é editada. O toggle é
     * DELETE + INSERT — ver FavoriteService::toggle().
     */
    public const UPDATED_AT = null;

    protected $fillable = [];

    /**
     * Defesa em profundidade: se um dia esta model for serializada por engano
     * para dentro de um prop, o id do membro não vai junto.
     */
    protected $hidden = ['user_id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }
}
