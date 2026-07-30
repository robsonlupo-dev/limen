<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Story da performer — conteúdo publicado com prazo de 24h.
 * Ver docs/SECURITY_ISSUES.md § 2.1 a § 2.10.
 *
 * O estado é POR TEMPO, não por flag: `isExpired()` e o escopo `active()` olham
 * `expires_at`, e é isso que faz a expiração valer na LEITURA (§ 2.8). O
 * `stories:purge` só recolhe — um story vencido já está fora de qualquer
 * resposta antes de o GC rodar, e um GC parado não vira acesso indefinido.
 * Mesma disciplina de `MemberPhoto` e de `ChatAccess`.
 */
class PerformerStory extends Model
{
    use SoftDeletes;

    /**
     * Prazo fixo, decidido pelo PO: 24h para todo story, sem menu.
     *
     * Diferente da foto do membro (24h/72h/7d), e a ausência de escolha é o que
     * torna o prazo publicável na tela sem virar sinal: com um menu, "expira
     * hoje" contaria qual opção a pessoa escolheu (é o § 1.2 inteiro). Com um
     * único valor não há o que inferir.
     */
    public const TTL_HOURS = 24;

    /**
     * Os três níveis de visibilidade (§ 2.2, decisão nº 3 do PO).
     *
     * `public` (nível 1) — qualquer membro autenticado.
     * `subscribers` (nível 2) — assinante de Círculo ativo, qualquer tier.
     * `exclusive` (nível 3) — Black/FC.
     *
     * A checagem de quem pode VER cada nível é o PR 3. O que já vale aqui é a
     * consequência do nível no sinal de audiência: `exclusive` não tem contador,
     * nem zero — ver `PerformerStoryService::viewCount()`.
     */
    public const VISIBILITY_LEVELS = ['public', 'subscribers', 'exclusive'];

    /** Nível que não devolve contagem de audiência de forma alguma (§ 2.2). */
    public const VISIBILITY_EXCLUSIVE = 'exclusive';

    /**
     * Só o nível vem de formulário.
     *
     * `performer_profile_id` (a dona vem sempre do request autenticado),
     * `media_path` e `content_hash` (vêm sempre do Store, calculados sobre os
     * bytes que ele gravou) e `expires_at` (é DERIVADO de TTL_HOURS — aceitá-lo
     * por payload deixaria o cliente escolher o prazo que o produto fixou) ficam
     * fora, na mesma regra de `discrete_mode`, do segredo de 2FA e do `user_id`
     * de `member_photos`. Um `content_hash` vindo de payload seria evidência
     * escolhida pelo denunciado.
     */
    protected $fillable = [
        'visibility_level',
    ];

    /**
     * `media_path` nunca sai em serialização — que é o caminho para as props do
     * Inertia. Não é cifrado no banco (§ 2.5), então a única barreira entre ele e
     * o cliente é esta linha: vazá-lo entregaria o layout do disco e o alvo para
     * qualquer traversal futuro na camada de serving.
     *
     * `expires_at` NÃO é escondido, e a diferença com `MemberPhoto` é de
     * conteúdo, não de descuido: lá o timestamp devolvia `granted_at` ao minuto
     * porque o TTL vinha de um menu de três opções conhecido pela performer
     * (§ 1.2). Aqui o prazo é único e fixo (24h) e o objeto é a publicação da
     * própria performer — o relógio revela quando ELA postou, que é o que um
     * story exibe por definição. Nada sobre nenhum membro atravessa esta coluna.
     */
    protected $hidden = [
        'media_path',
        // O hash é PROVA, não conteúdo de tela (§ 2.4, parte 1). Fora da
        // serialização por duas razões independentes: ninguém do lado do cliente
        // tem uso para ele, e publicá-lo daria a quem já tem o arquivo uma forma
        // barata de CONFIRMAR que é exatamente aquele — inclusive para checar se
        // um re-upload evasivo mudou o suficiente para escapar do matching.
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    /**
     * As views deste story. Existe para o `DISTINCT` do contador e para o
     * expurgo — **nunca** para virar lista na tela (§ 2.1).
     */
    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class, 'performer_story_id');
    }

    /**
     * Vencido pelo relógio. É a checagem que corta o acesso (§ 2.8) — ver o
     * docblock da classe. `>=` e não `>`: no instante exato do vencimento o
     * story já morreu.
     */
    public function isExpired(): bool
    {
        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * Os stories que contam como vivos: dentro do prazo e não removidos.
     *
     * Uma dona só para o critério, pela mesma razão do `activeForUser()` de
     * `MemberPhoto` e da elegibilidade do piso (item 6 do CLAUDE.md): o feed do
     * membro, o indicador do catálogo (PR 3), o serving dos bytes e o contador
     * precisam concordar sobre o que é "story vivo". Duas versões divergiriam, e
     * divergiriam no sentido permissivo.
     *
     * O `whereNull('deleted_at')` vem de graça pelo SoftDeletes.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
