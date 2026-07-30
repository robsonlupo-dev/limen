<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha por (story, membro): o membro abriu aquele story.
 * Ver docs/SECURITY_ISSUES.md § 2.1, § 2.6 e § 2.7.
 *
 * **Não existe lista de viewers, e não é omissão de UI** (§ 2.1): a única saída
 * desta tabela é a FAIXA de membros únicos do `PerformerStoryService::viewCount()`.
 * Uma lista aqui seria exposição membro→performer sem piso nenhum — o buraco que
 * o painel de visitantes existiu para tapar, e pior, porque ver story não exige
 * seguir, então o Piso de Anonimato (contado sobre follows) ficaria fora do
 * circuito. Se um dia a lista for pedida, ela passa por `canRevealList()` +
 * `FanAlias` + faixa + k-anonimato, igual ao painel — não por um `->get()` daqui.
 *
 * Reabrir não gera linha nova: o índice único do par garante o "cada membro
 * conta 1 vez" da decisão nº 1 do PO, e `viewed_at` guarda a PRIMEIRA vez. Não
 * reescrevemos no reabrir — a coluna viraria um log de quando o membro volta a
 * olhar, que é comportamento dele e nenhuma tela pode mostrar (mesma decisão do
 * `viewed_at` de `MemberPhotoAccess`).
 */
class StoryView extends Model
{
    /**
     * `viewed_at` é o instante do fato e substitui os timestamps do Eloquent —
     * `created_at` ao lado seria segunda cópia da mesma informação. A migration
     * não cria as colunas.
     */
    public $timestamps = false;

    /**
     * Vazio, e é a forma mais forte da regra: NADA aqui vem de payload.
     *
     * As duas FKs e o `viewed_at` são autoridade do servidor — o membro vem do
     * request autenticado e o story do route-model binding, ambos escolhidos por
     * `PerformerStoryService::viewStory()`. Mesma regra de `discrete_mode`, do
     * segredo de 2FA e das FKs de `member_photo_access`: um
     * `StoryView::create($request->validated())` futuro forjaria os três, e o
     * mais perigoso é `user_id` — gravar view no nome de outro membro adulteraria
     * o `DISTINCT` que sustenta a faixa (e, com Ghost Mode, plantaria justamente
     * a linha que o perk existe para não ter).
     *
     * Vazio junto com o `$guarded = ['*']` do padrão deixa o model TOTALMENTE
     * guardado, e isso é melhor que uma lista estreita: a tentativa de mass
     * assignment não é descartada em silêncio, ela estoura em
     * `MassAssignmentException` — quem escrever `StoryView::create($validated)`
     * descobre no primeiro teste, não em produção.
     */
    protected $fillable = [];

    /**
     * `user_id` é o `member_id` cru: o id que o `FanAlias` existe para tirar de
     * circulação, e a serialização é o caminho para as props do Inertia. A
     * performer nunca recebe linha desta tabela (§ 2.1), mas `$hidden` é a rede
     * para o dia em que alguém serializar o model num payload de admin ou de
     * métricas — o alias é estável por par, então um id vazado aqui ligaria o
     * pseudônimo às gorjetas, aos seguidores e aos visitantes de uma vez.
     */
    protected $hidden = [
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(PerformerStory::class, 'performer_story_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
