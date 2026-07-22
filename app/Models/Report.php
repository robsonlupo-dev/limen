<?php

namespace App\Models;

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
    ];

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
