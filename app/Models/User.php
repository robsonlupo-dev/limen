<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\PrivacyPerkService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'birthdate',
        'lgpd_consent_at', 'terms_version', 'last_login_at', 'asaas_customer_id',
        'interests_opt_out',
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
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'birthdate' => 'date',
            'age_verified_at' => 'datetime',
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

    /** Interesses recebidos por este usuário enquanto membro (consumer). */
    public function receivedInterests(): HasMany
    {
        return $this->hasMany(PerformerInterest::class, 'member_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
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
