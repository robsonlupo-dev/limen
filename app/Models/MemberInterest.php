<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha da junção `member_interest`. Gêmeo do [[PerformerTag]]: não existe
 * entidade "Tag" no sistema — o slug é o próprio valor, e o conjunto válido vem
 * de PerformerProfile::TAGS pelo Form Request.
 *
 * O conjunto é o MESMO dos dois lados de propósito. O interesse do membro só
 * vale se casar com a tag da performer; duas listas divergiriam no primeiro
 * slug que existisse só de um lado e o cruzamento de afinidade (Sprint 10)
 * nasceria com buracos silenciosos.
 *
 * PRIVACIDADE — regra central, não detalhe de implementação: o interesse do
 * membro NUNCA é exposto à performer. A tag da performer é vitrine (perfil
 * público indexável); o interesse do membro é o inverso — é o que ele procura,
 * e numa plataforma adulta isso é dado sensível de vida sexual (LGPD art. 5º,
 * II), da mesma família do `preferred_world` que o Hard Delete já apaga.
 * Publicá-lo daria à performer um perfil de desejo associado a um alias que é
 * estável por par (ver FanAlias no CLAUDE.md) — o vínculo iria junto para as
 * gorjetas, os seguidores e o painel de visitantes. Este model não aparece em
 * nenhum resource que a performer consuma, e o guard é um teste.
 *
 * O model é fino de propósito — existe porque uma relação Eloquent precisa de
 * uma classe do outro lado. Quem escreve é User::syncInterests().
 */
class MemberInterest extends Model
{
    protected $table = 'member_interest';

    /** A junção não tem created_at/updated_at — o interesse não tem história própria. */
    public $timestamps = false;

    protected $fillable = ['tag_slug'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
