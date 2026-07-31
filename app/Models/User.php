<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\PrivacyPerkService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Teto de interesses por membro (Sprint 9). Espelha
     * PerformerProfile::MAX_TAGS pelo mesmo motivo do conjunto de slugs ser
     * compartilhado: um lado com teto maior desequilibraria o cruzamento de
     * afinidade do Sprint 10, onde a interseção é a medida.
     */
    public const MAX_INTERESTS = 8;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'birthdate',
        'lgpd_consent_at', 'terms_version', 'last_login_at', 'asaas_customer_id',
        'interests_opt_out',
        // Texto livre auto-declarado pelo próprio titular, da mesma natureza de
        // `name` e `phone` — não é privilégio (`discrete_mode`, `role`,
        // `status`) nem material de autenticação, então o $fillable serve. O
        // conteúdo passa por NoProhibitedOffer no Form Request.
        'seeking',
    ];

    // Colunas de exclusão ficam FORA do $fillable de propósito (mesma regra do
    // discrete_mode): agendar o próprio encerramento — ou o de outro — nunca
    // pode vir por mass assignment. A troca passa pelo DeletionService.
    protected $hidden = [
        'password', 'remember_token', 'deletion_token_hash',
        // Segredo TOTP e recovery codes. O cast `encrypted` protege o REPOUSO;
        // isto protege a SAÍDA — decifrado, `two_factor_secret` é o suficiente
        // para gerar códigos válidos indefinidamente, e um recovery code é um
        // bypass de uso único do segundo fator. Nem um nem outro volta ao
        // cliente fora do fluxo de setup, que os monta explicitamente.
        'two_factor_secret',
        'two_factor_recovery_codes',
        // Digest do IP de cadastro. Hoje nenhuma serialização é automática (os
        // resources montam array explícito), então não vaza — mas um
        // `response()->json($user)` futuro exporia o identificador que permite
        // dizer "esta conta veio do mesmo IP que aquela". Uma linha fecha a
        // classe inteira de regressão.
        'registration_ip_hash',
        // Estilo de Vida (Sprint 10). É o único auto-declarado do membro que
        // VOLTA para a performer, e por isso ele sai por UM caminho só: o
        // rótulo montado por LifestyleTier::labelsFor() nas três telas dela.
        // Escondê-lo da serialização é o que impede o slug cru de pegar carona
        // num prop de Inertia genérico — e o prop perigoso é o do CATÁLOGO,
        // superfície pública onde não há nem o piso de anonimato para segurar a
        // correlação entre perfis. Mesma razão do registration_ip_hash acima.
        'lifestyle_tier',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'birthdate' => 'date',
            'age_verified_at' => 'datetime',
            // Carta dos fundadores. Fora do $fillable de propósito — é trava de
            // idempotência interna, escrita só pelo SendWelcomeEmail.
            'welcome_email_sent_at' => 'datetime',
            'lgpd_consent_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            // 2FA TOTP. Cifrado em repouso pela APP_KEY: um dump do banco não
            // pode render segundo fator. Rotacionar a APP_KEY invalida os dois
            // (mesma ressalva já registrada para os documentos de KYC) — a
            // performer cai no fluxo de re-cadastro do autenticador.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'interests_opt_out' => 'boolean',
            'discrete_mode' => 'boolean',
            // Sinal antifraude (fora do $fillable — set explícito no cadastro/KYC).
            'blacklist_hit' => 'boolean',
            // Perks de privacidade Black/FC. NULL é significativo aqui ("nunca
            // escolheu" → vale o padrão do tier), e o cast preserva o null.
            'ghost_mode' => 'boolean',
            'invisible_status' => 'boolean',
            'read_receipts_enabled' => 'boolean',
            'deletion_requested_at' => 'datetime',
            'deletion_scheduled_at' => 'datetime',
            'deletion_confirmed_at' => 'datetime',
            'deletion_token_expires_at' => 'datetime',
        ];
    }

    public function deletionLogs(): HasMany
    {
        return $this->hasMany(DeletionLog::class);
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function performerProfile(): HasOne
    {
        return $this->hasOne(PerformerProfile::class);
    }

    public function identityVerifications(): HasMany
    {
        return $this->hasMany(IdentityVerification::class);
    }

    /** Aceites de Política de Conteúdo / Contrato de Performance (append-only). */
    public function documentAcceptances(): HasMany
    {
        return $this->hasMany(DocumentAcceptance::class);
    }

    public function tokenWallet(): HasOne
    {
        return $this->hasOne(TokenWallet::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function follows(): HasMany
    {
        return $this->hasMany(Follow::class);
    }

    // ─── Favoritos do membro (Sprint 10) ─────────────────────────────────────
    //
    // Bookmark PRIVADO — a performer nunca sabe que foi favoritada. As duas
    // relações abaixo vivem só neste lado: `PerformerProfile` não tem a inversa,
    // e isso é deliberado (ver o comentário lá e o cabeçalho de Favorite).

    /** As linhas de favorito deste membro. */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * Os perfis que este membro salvou.
     *
     * belongsToMany aqui, ao contrário de `interests()`: existe uma entidade do
     * outro lado (`performer_profiles`) para dar JOIN — é exatamente o caso que
     * a relação many-to-many resolve.
     *
     * **Sem `withTimestamps()`**: a junção não tem `updated_at` (ver a migration
     * e a const UPDATED_AT de Favorite), e o helper tentaria escrevê-lo.
     * A ordenação por "salvo mais recentemente" fica no
     * FavoriteService::paginateFor(), junto do recorte de catálogo público.
     */
    public function favoritedProfiles(): BelongsToMany
    {
        return $this->belongsToMany(PerformerProfile::class, 'favorites')
            ->withPivot('created_at');
    }

    /** Interesses recebidos por este usuário enquanto membro (consumer). */
    public function receivedInterests(): HasMany
    {
        return $this->hasMany(PerformerInterest::class, 'member_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // ─── Interesses do membro (Sprint 9) ─────────────────────────────────────
    //
    // Espelho exato das tags da performer (PerformerProfile::tags/syncTags), e
    // sobre o MESMO conjunto de slugs — ver o cabeçalho de MemberInterest para
    // por quê, e para a regra de privacidade que proíbe expô-los à performer.

    /**
     * Os interesses do membro, na junção `member_interest`.
     *
     * hasMany e não belongsToMany pela mesma razão das tags: uma relação
     * many-to-many precisa de uma tabela `tags` para dar JOIN, e aqui o slug É
     * o valor — não há entidade a referenciar. A escrita é syncInterests(), no
     * lugar do sync() do BelongsToMany.
     */
    public function interests(): HasMany
    {
        return $this->hasMany(MemberInterest::class);
    }

    /**
     * Os slugs dos interesses deste membro.
     *
     * Lê da relação já carregada quando houver — mesma razão do tagSlugs() da
     * performer: uma query por linha numa listagem devolveria o N+1 que a
     * junção veio evitar.
     *
     * @return array<int, string>
     */
    public function interestSlugs(): array
    {
        return $this->relationLoaded('interests')
            ? $this->interests->pluck('tag_slug')->all()
            : $this->interests()->pluck('tag_slug')->all();
    }

    /**
     * Substitui o conjunto de interesses do membro, no espírito do sync() do
     * BelongsToMany: idempotente, e mexe só no que mudou.
     *
     * O diff não é otimização prematura — apagar tudo e reinserir faria toda
     * gravação de perfil (inclusive as que nem tocam em interesse) queimar ids
     * e sujar o binlog. Ficar só no delta também mantém o índice único como
     * rede: reenviar a mesma seleção não tenta reinserir nada.
     *
     * @param  array<int, string>  $slugs  já validados contra allTags()
     */
    public function syncInterests(array $slugs): void
    {
        $slugs = array_values(array_unique(array_filter($slugs, 'is_string')));
        $current = $this->interests()->pluck('tag_slug')->all();

        $toAdd = array_diff($slugs, $current);
        $toRemove = array_diff($current, $slugs);

        if ($toAdd === [] && $toRemove === []) {
            return;
        }

        DB::transaction(function () use ($toAdd, $toRemove) {
            if ($toRemove !== []) {
                $this->interests()->whereIn('tag_slug', $toRemove)->delete();
            }

            if ($toAdd !== []) {
                $this->interests()->createMany(
                    array_map(fn (string $slug) => ['tag_slug' => $slug], array_values($toAdd)),
                );
            }
        });

        // A relação em memória ficou velha: quem leu $user->interests antes do
        // sync veria a lista anterior no mesmo request (a resposta do update).
        $this->unsetRelation('interests');
    }

    /** The user's live subscription (active + inside the paid period), or null. */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->where('status', 'active')
            ->where('current_period_end', '>', now())
            ->with('circle')
            ->latest('id')
            ->first();
    }

    /** The Círculo the user is currently subscribed to, or null. */
    public function activeCircle(): ?Circle
    {
        return $this->activeSubscription()?->circle;
    }

    /** Slug of the active Círculo (for Inertia / gates), or null. */
    public function activeCircleSlug(): ?string
    {
        return $this->activeCircle()?->slug;
    }

    // ─── Estado da conta ─────────────────────────────────────────────────────
    //
    // `banned` é encerramento PERMANENTE por moderação — distinto de `suspended`
    // (temporário, reversível). Nenhum dos dois consegue logar (AuthService::
    // attemptLogin). `status` NÃO está no $fillable: a troca é ato de autoridade
    // do servidor (forceFill no endpoint admin), nunca payload — mesma regra de
    // `role` e `discrete_mode`.

    /** @param  Builder<User>  $query */
    public function scopeBanned($query)
    {
        return $query->where('status', 'banned');
    }

    public function isBanned(): bool
    {
        return $this->status === 'banned';
    }

    // ─── Perks de privacidade (Black / Founders Circle) ──────────────────────
    //
    // Atalhos de leitura sobre o PrivacyPerkService, que é quem decide de fato
    // (elegibilidade por rank de tier + padrão por tier + escolha explícita).
    // Ficam no model porque os pontos de aplicação são espalhados — catálogo,
    // chat, painel — e um `$user->hasGhostMode()` na condição é mais difícil de
    // esquecer do que injetar o service em cada controller. A regra continua
    // tendo uma dona só: mudar o critério é mudar o service, não isto aqui.

    /** A visita deste membro a um perfil NÃO deve ser registrada. */
    public function hasGhostMode(): bool
    {
        return app(PrivacyPerkService::class)->effective($this, PrivacyPerkService::GHOST_MODE);
    }

    /** A presença deste membro NÃO deve ser exposta a terceiros. */
    public function hasInvisibleStatus(): bool
    {
        return app(PrivacyPerkService::class)->effective($this, PrivacyPerkService::INVISIBLE_STATUS);
    }

    /**
     * A LEITURA deste membro pode ser confirmada para quem enviou a mensagem.
     * False = ele lê sem que o remetente saiba (perk Black/FC).
     */
    public function hasReadReceipts(): bool
    {
        return app(PrivacyPerkService::class)->effective($this, PrivacyPerkService::READ_RECEIPTS);
    }
}
