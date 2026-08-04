<?php

namespace App\Models;

use App\Services\ContentVisibilityService;
use App\Services\MemberPhotoService;
use App\Services\StoryVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Denúncia de conteúdo/conduta. Canal mínimo de compliance: sem ele um membro
 * que vê conteúdo ilegal (menor de idade, coerção, não-consensual) não tem
 * caminho para reportar, e a plataforma não tem prova de ter sido notificada.
 *
 * O registro é append-only na prática: a revisão só escreve status/reviewed_*.
 * Nada apaga uma denúncia — é o lastro da notificação.
 */
class Report extends Model
{
    /**
     * Tipos denunciáveis, por APELIDO público.
     *
     * O request nunca traz o nome da classe: aceitar `reportable_type` cru
     * deixaria o cliente apontar para qualquer Model do app (enumerar
     * IdentityVerification, Payout, ...) e ainda vazaria a estrutura interna
     * no HTML. O apelido é a fronteira — fora deste mapa, 422.
     *
     * @var array<string, class-string<Model>>
     */
    public const REPORTABLE_TYPES = [
        'performer' => PerformerProfile::class,
        'message' => Message::class,
        // Story da performer (Sprint 9C, § 2.4 parte 2). Entra pela MESMA porta
        // dos outros dois em vez de ganhar rota própria: o dedup por janela, o
        // lock anti-duplo-submit, a recusa de autodenúncia e a resposta uniforme
        // para "não existe"/"não pode ver" já vivem no ReportController, e uma
        // segunda rota nasceria sem alguma delas. Denunciar um story É o gatilho
        // que congela o GC e a deleção manual daquele story.
        'performer_story' => PerformerStory::class,
        // Foto efêmera do membro (Sprint 9B — o 1º dos 4 bloqueadores de go-live
        // da feature, ver MASTER_HANDOFF_FINAL, seção "Sprint 9B").
        // Aqui quem denuncia é a PERFORMER que recebeu, e o denunciado é o
        // membro que enviou: é a única direção do produto em que o conteúdo
        // chega sem ter sido pedido. Mesma porta, pelas mesmas razões do story.
        //
        // ⚠️ O HANDLE DESTE TIPO NÃO É A CHAVE. O payload traz o `access_id`
        // (MemberPhotoAccess), não o id da foto — a performer nunca vê o id da
        // foto, e não deveria: ele é comum às várias performers com quem o mesmo
        // membro compartilhou, então aceitá-lo entregaria um identificador
        // correlacionável entre perfis, que é exatamente o que o FanAlias existe
        // para impedir. Quem traduz handle → alvo é resolveFromHandle().
        'member_photo' => MemberPhoto::class,
        // Conteúdo permanente pago (Sprint 14, M.4). É a peça mais DURADOURA do
        // produto (sem TTL) e des-anonimizada por pagamento — se for ilegal, fica
        // servível para sempre. Por isso é denunciável desde o dia 1 (princípio nº
        // 1), pela mesma porta dos outros. "Quem viu" = "quem pode denunciar", como
        // o story: a resposta vem do ContentVisibilityService::canView (visibleTo).
        // Denúncia em aberto congela a remoção manual (PerformerContentService).
        'performer_content' => PerformerContent::class,
    ];

    /**
     * Denúncias que ainda não foram concluídas pelo admin — as que CONGELAM a
     * destruição do alvo (story e foto efêmera).
     *
     * Vive aqui e não em cada service porque a pergunta "isto está em análise?"
     * já tem dois donos diferentes (`PerformerStoryService`, `MemberPhotoService`)
     * e ganharia um terceiro na próxima superfície. Duas cópias divergiriam, e a
     * que divergisse no sentido permissivo é a que destrói a prova.
     */
    public const OPEN_STATUSES = ['pending', 'reviewed'];

    /** Janela em que um mesmo denunciante não repete o mesmo par alvo+motivo. */
    public const DEDUP_WINDOW_HOURS = 24;

    /**
     * Só o que de fato vem do formulário. Denunciante, alvo e status são
     * autoridade do servidor — entram por forceFill em Report::open(). Um
     * `Report::create($request->validated())` futuro forjaria os três.
     */
    protected $fillable = ['reason', 'details'];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'reportable_id' => 'integer',
        ];
    }

    /**
     * Única porta de criação. Denunciante, alvo e status vêm daqui — nunca do
     * payload — e é isso que mantém o $fillable estreito acima honesto.
     */
    public static function open(User $reporter, Model $reportable, string $reason, ?string $details = null): self
    {
        $report = new self(['reason' => $reason, 'details' => $details]);

        $report->forceFill([
            'reporter_id' => $reporter->getKey(),
            'reportable_type' => $reportable->getMorphClass(),
            'reportable_id' => $reportable->getKey(),
            'status' => 'pending',
        ])->save();

        return $report;
    }

    /** Apelido público → classe. Null quando o apelido não é denunciável. */
    public static function classForAlias(string $alias): ?string
    {
        return self::REPORTABLE_TYPES[$alias] ?? null;
    }

    /**
     * Handle público → modelo alvo, **na visão daquele denunciante**. É a
     * tradução que o controller usa no lugar de um `$class::find($id)` cru.
     *
     * Para quase todo tipo o handle É a chave, e o método não faz nada de
     * especial. A exceção é `member_photo`, e é por causa dela que este método
     * existe: o handle ali é o `MemberPhotoAccess` (o par foto↔performer), e um
     * `MemberPhoto::find($id)` sobre aquele número acharia **outra foto** — a de
     * id igual ao do acesso. Não seria erro visível: devolveria uma foto de
     * verdade, de outro membro, e a checagem de visibilidade a recusaria com a
     * resposta uniforme de "não encontrado". Falharia em silêncio e para sempre.
     *
     * ── Por que o denunciante entra aqui, e não só no `visibleTo()` ─────────
     * O handle é do PAR, então resolvê-lo sem olhar de quem ele é abre um furo
     * que o `visibleTo()` não fecha: a performer B, que também recebeu a foto P,
     * mandaria o `access_id` de A (outra performer que recebeu P) e levaria 200,
     * porque `visibleTo()` procura o acesso DELA à mesma foto e o encontra. O
     * 200 num id que não é dela prova que **aquele mesmo rosto foi mostrado a
     * mais alguém** — e os ids são sequenciais, então a posição ainda dá a ordem
     * aproximada dos envios. É exatamente a correlação entre perfis que o
     * `FanAlias` existe para impedir, e o custo por sonda é baixo demais para
     * confiar no throttle. Achado da revisão de segurança de 30/07.
     *
     * Amarrando o handle ao perfil do denunciante, "não é seu acesso" e "não
     * existe" viram a mesma coisa antes de qualquer outra checagem. O
     * `visibleTo()` continua conferindo os PRAZOS — as duas perguntas são
     * complementares, não redundantes.
     *
     * Sem `withTrashed` em lugar nenhum, e não por esquecimento: a foto sai em
     * HARD delete e o acesso morre junto (a tabela não tem soft delete). Handle
     * de foto já destruída devolve null, que o controller trata como "não
     * encontrado" — a mesma resposta de "não pode ver", que é o que fecha o
     * oráculo de enumeração.
     */
    public static function resolveFromHandle(string $alias, int $handle, ?User $reporter = null): ?Model
    {
        if ($alias === 'member_photo') {
            $profileId = $reporter?->performerProfile?->getKey();

            if ($profileId === null) {
                return null;
            }

            return MemberPhotoAccess::query()
                ->whereKey($handle)
                ->where('performer_profile_id', $profileId)
                ->first()
                ?->photo;
        }

        $class = self::classForAlias($alias);

        return $class ? $class::find($handle) : null;
    }

    /** Classe → apelido público (para exibir sem revelar o namespace). */
    public static function aliasForClass(string $class): ?string
    {
        return array_search($class, self::REPORTABLE_TYPES, true) ?: null;
    }

    /**
     * Dono do alvo — quem estaria sendo denunciado. É o que a checagem de
     * autodenúncia compara com o reporter, e cada tipo guarda isso numa coluna
     * diferente (perfil pelo user_id, mensagem pelo autor).
     */
    public static function ownerIdOf(Model $reportable): int
    {
        return match (true) {
            $reportable instanceof PerformerProfile => (int) $reportable->user_id,
            $reportable instanceof Message => (int) $reportable->sender_id,
            // O dono do story é a PERFORMER, pelo perfil. `withTrashed()` porque
            // o perfil pode ter sido encerrado entre a publicação e a denúncia, e
            // devolver 0 aqui desligaria a checagem de autodenúncia em silêncio —
            // exatamente o que o `default` abaixo existe para impedir.
            $reportable instanceof PerformerStory => (int) PerformerProfile::withTrashed()
                ->whereKey($reportable->performer_profile_id)
                ->value('user_id'),
            // A foto é do MEMBRO que a enviou — é ele quem está sendo denunciado.
            // `user_id` é `$hidden` (não sai em serialização), mas continua
            // legível aqui: $hidden esconde do JSON, não do código.
            $reportable instanceof MemberPhoto => (int) $reportable->user_id,
            // A peça de conteúdo é da PERFORMER, pelo perfil (como o story).
            // `withTrashed()` pela mesma razão: perfil encerrado entre a publicação
            // e a denúncia não pode zerar a checagem de autodenúncia.
            $reportable instanceof PerformerContent => (int) PerformerProfile::withTrashed()
                ->whereKey($reportable->performer_profile_id)
                ->value('user_id'),
            // Fail-closed: devolver null aqui faria a comparação com o reporter
            // dar false e DESLIGARIA silenciosamente a checagem de autodenúncia
            // no dia em que alguém adicionar um tipo em REPORTABLE_TYPES e
            // esquecer deste match.
            default => throw new \LogicException(
                'Tipo denunciável sem dono mapeado: '.$reportable::class
            ),
        };
    }

    /**
     * O denunciante consegue VER este alvo? Denunciar exige ter visto — e sem
     * esta porta o POST vira oráculo de existência: variando o reportable_id,
     * "denúncia recebida" vs "não encontrado" enumera mensagens de conversas
     * alheias (e a mensagem é o objeto mais sensível do produto).
     *
     * O controller responde igual para "não existe" e "não pode ver", senão a
     * distinção reabre o mesmo oráculo pela porta dos fundos.
     */
    public static function visibleTo(Model $reportable, User $user): bool
    {
        return match (true) {
            // Perfil de performer é superfície pública (catálogo sem auth) —
            // não há o que enumerar aqui que o /performers já não liste.
            $reportable instanceof PerformerProfile => true,
            // Mensagem só existe para os dois participantes da conversa. Mesma
            // regra da ConversationPolicy::view — não reimplementar.
            $reportable instanceof Message => (bool) $reportable
                ->conversation()
                ->with('performerProfile')
                ->first()
                ?->hasParticipant($user),
            // Story: "viu" é literalmente a mesma pergunta do serving, então a
            // resposta vem de quem já a responde (§ 2.3). Reimplementá-la aqui
            // criaria a segunda dona que o StoryVisibilityService recusa — e a
            // divergência apareceria no pior sentido: um `true` largo demais
            // deixaria o POST de denúncia virar oráculo de EXISTÊNCIA para
            // stories que o membro não alcança (varrendo ids, "recebida" vs
            // "não encontrado" reconstrói o que a performer publicou e quando).
            //
            // Consequência assumida: story VENCIDO não é mais denunciável, porque
            // `canView()` já o nega. A janela real de denúncia é a de exibição, e
            // é a janela em que o membro de fato viu o conteúdo.
            $reportable instanceof PerformerStory => app(StoryVisibilityService::class)
                ->canView($reportable, $user),
            // Foto efêmera: "viu" é a mesma pergunta do serving da performer, e a
            // resposta vem de quem já a responde (`readForPerformer`). Vale aqui
            // tudo o que vale para o story — reimplementar criaria a segunda dona,
            // e um `true` largo demais deixaria o POST virar oráculo: varrendo
            // handles de acesso, "recebida" vs "não encontrado" diria quantas
            // fotos existem e quais estão vivas.
            //
            // É também o que substitui um `role:performer` na rota: quem não tem
            // perfil de performer não tem acesso vivo a foto nenhuma, então não
            // passa por aqui. A rota é compartilhada com o membro (que denuncia
            // perfil, mensagem e story) e não pode carregar aquele middleware.
            //
            // Consequência assumida, idêntica à do story: foto VENCIDA não é mais
            // denunciável. A janela de denúncia é a de exibição — que aqui é mais
            // curta (o TTL mínimo é 24h) e fica registrada como limitação, não
            // como descuido.
            $reportable instanceof MemberPhoto => app(MemberPhotoService::class)
                ->performerCanView($user, $reportable),
            // Conteúdo permanente: "viu" é a mesma pergunta do serving, então a
            // resposta vem da dona única (ContentVisibilityService::canView) — não
            // reimplementar. Um `true` largo demais deixaria o POST de denúncia
            // virar oráculo de existência para peças que o membro não alcança.
            // Consequência assumida (como story): quem NÃO desbloqueou/não alcança
            // não denuncia — mas quem viu o conteúdo (pagou/grátis) pode.
            $reportable instanceof PerformerContent => app(ContentVisibilityService::class)
                ->canView($user, $reportable),
            default => false,
        };
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeReviewed(Builder $query): Builder
    {
        return $query->where('status', 'reviewed');
    }
}
