<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
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

    protected $hidden = [
        'password', 'remember_token',
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
            'interests_opt_out' => 'boolean',
            'discrete_mode' => 'boolean',
        ];
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification());
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
}
