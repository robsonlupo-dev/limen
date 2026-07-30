<?php

namespace App\Models;

use App\Support\ExpirySlot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Foto efêmera enviada por um membro. Ver docs/SECURITY_ISSUES.md § 1.1–§ 1.11.
 *
 * O estado é POR TEMPO, não por flag: `isExpired()` e o escopo `activeForUser()`
 * olham `expires_at`, e é isso que faz a expiração valer na LEITURA (§ 1.3). O
 * job `member-photos:purge` só recolhe — uma foto vencida já está fora de
 * qualquer resposta antes de o GC rodar, e um GC parado não vira acesso
 * indefinido. Mesma disciplina de ChatAccess, que confere a janela no acesso e
 * deixa o carimbo para o job.
 */
class MemberPhoto extends Model
{
    use SoftDeletes;

    /**
     * Teto de fotos ATIVAS simultâneas por membro (§ 1.6, decidido pelo PO).
     *
     * "Ativas" = não expiradas e não removidas, contadas pelo MESMO critério do
     * serving. Contar linhas já vencidas mas ainda presentes no disco travaria o
     * membro em 5 fotos mortas esperando o GC — o cap viraria bug de produto em
     * vez de defesa. Quem aplica é MemberPhotoService::create(), no submit e sob
     * lock; verificar no job seria contabilidade tardia, não autorização.
     */
    public const ACTIVE_LIMIT = 5;

    /**
     * TTLs que o membro pode escolher, em horas: 24h / 72h / 7 dias.
     *
     * **O valor escolhido nunca é exibido à performer** (§ 1.2): 24h vs 7 dias é
     * postura do membro e é sinal por si só. O que a tela mostra é a FAIXA de
     * MemberPhotoAccess::timeRemainingSlot(), nunca este número nem um countdown.
     */
    public const TTL_HOURS = [24, 72, 168];

    /**
     * `user_id`, `path_encrypted` e `original_filename` ficam FORA do fillable,
     * na mesma regra de `discrete_mode` e do segredo de 2FA: o dono vem sempre
     * do request autenticado e o caminho vem sempre do Store, nunca de payload.
     */
    protected $fillable = [
        'expires_at',
        'size_bytes',
    ];

    /**
     * O que NUNCA sai em serialização — que é o caminho para as props do Inertia.
     *
     * Além do caminho no disco e do nome original (que costuma ser tão descritivo
     * quanto a foto), os dois que a revisão de segurança apontou:
     *
     * - `user_id`: é o `member_id` cru. Qualquer tela que passasse o model da
     *   foto à performer entregaria o id que o FanAlias existe para tirar de
     *   circulação — e o alias é estável por par, então o vínculo iria junto
     *   para gorjetas, seguidores e visitantes.
     * - `expires_at`: é relógio. Com o TTL vindo de um menu de três opções, o
     *   timestamp exato devolve `granted_at` ao minuto — o mesmo oráculo que o
     *   `visited_at` do painel de visitantes já custou fechar (item 12 do
     *   CLAUDE.md). O que pode ser exibido é a FAIXA de
     *   MemberPhotoAccess::timeRemainingSlot().
     *
     * `isExpired()` e o escopo `activeForUser()` continuam funcionando: `$hidden`
     * esconde da serialização, não do código.
     */
    protected $hidden = [
        'path_encrypted',
        'original_filename',
        'user_id',
        'expires_at',
        // SHA-256 dos bytes processados. `$hidden` e fora do `$fillable` pela
        // mesma razão do `content_hash` do story: é EVIDÊNCIA, e evidência
        // escolhida pelo acusado não é evidência. Nenhuma tela precisa dele —
        // quem o consome é a preservação por denúncia no encerramento de conta
        // e, no futuro, o matching contra listas de hash conhecidas.
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            // APP_KEY. `config/app.php` já configura `previous_keys`, então uma
            // rotação de chave não perde as fotos (§ 1.9) — ao contrário do
            // FanAlias, que não tem esse fallback.
            'path_encrypted' => 'encrypted',
            'original_filename' => 'encrypted',
            'size_bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(MemberPhotoAccess::class);
    }

    /**
     * O que o TITULAR vê sobre a própria foto.
     *
     * Em faixa, e não em relógio, mesmo sendo a foto dele: o valor da faixa é o
     * mesmo que a performer recebe, e manter as duas telas no mesmo vocabulário
     * evita que alguém "melhore" a do membro para um countdown e depois reuse o
     * componente do outro lado. O TTL escolhido ele já conhece — foi ele quem
     * escolheu no envio.
     *
     * `shared_with` é o agregado que o § 1.1 pede: **"você compartilhou sua foto
     * com N performers"**. Não previne nada — põe na frente de quem carrega o
     * risco o número que ele não teria como estimar sozinho. Conta só acesso
     * VIVO, pelo mesmo critério do serving.
     *
     * @return array{id:int,expires_slot:string,shared_with:int}
     */
    public function presentForMember(): array
    {
        return [
            'id' => $this->id,
            'expires_slot' => ExpirySlot::for($this->expires_at),
            'shared_with' => $this->accesses()->where('expires_at', '>', now())->count(),
        ];
    }

    /**
     * Vencida pelo relógio. É a checagem que corta o acesso — ver o docblock da
     * classe. `>=` e não `>`: no instante exato do vencimento a foto já morreu.
     */
    public function isExpired(): bool
    {
        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * As fotos que contam como ativas: dentro do prazo e não removidas.
     *
     * Uma dona só para o critério, pela mesma razão que a elegibilidade do piso
     * tem uma (item 6 do CLAUDE.md): o cap do submit, a lista do membro e o
     * contador de "com quantas performers você compartilhou" precisam concordar.
     * Duas versões divergiriam, e divergiriam no sentido permissivo.
     *
     * O `whereNull('deleted_at')` vem de graça pelo SoftDeletes.
     */
    public function scopeActiveForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)->where('expires_at', '>', now());
    }
}
