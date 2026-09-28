<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Salvo" do membro — uma PEÇA de conteúdo guardada para ver depois (roadmap
 * social, Onda 3 §3.1). É o irmão de `Favorite` para conteúdo em vez de perfil, e
 * herda a MESMA assimetria — que é o produto, não detalhe de UI:
 *
 *  - **A performer NUNCA sabe que a peça dela foi salva.** Não há relação inversa
 *    em `PerformerContent`/`PerformerProfile` (o `$profile->contentSaves` seria a
 *    porta por onde um `withCount` entraria num resource dela sem ninguém notar) e
 *    NÃO há contador em superfície nenhuma. Salvar exige poder VER a peça
 *    (decisão do PO) — a regra vive no `ContentSaveService`.
 *  - **Nada deste fluxo entra em `audit_logs`.** Aquela tabela sobrevive ao Hard
 *    Delete com o IP do membro em claro; uma trilha `content.saved` seria a cópia
 *    permanente do mapa de interesses que o Hard Delete apaga (ver Favorite).
 *
 * FKs fora do `$fillable`: a linha só nasce no service, de `User`/`PerformerContent`
 * já resolvidos — nunca de um array de request.
 */
class ContentSave extends Model
{
    /** Sem `updated_at`: a linha nasce e morre; o toggle é DELETE + INSERT. */
    public const UPDATED_AT = null;

    protected $fillable = [];

    /** Defesa em profundidade: se serializada por engano, o id do membro não vai junto. */
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

    public function performerContent(): BelongsTo
    {
        return $this->belongsTo(PerformerContent::class);
    }
}
