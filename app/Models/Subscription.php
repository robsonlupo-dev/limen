<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'circle_id', 'asaas_subscription_id', 'status',
        'current_period_start', 'current_period_end', 'next_due_date',
        'cancel_at_period_end', 'trial_ends_at', 'price_cents',
        'card_token', 'card_last4', 'card_brand', 'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_due_date' => 'date',
            'canceled_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'price_cents' => 'integer',
            // Token reusável do Asaas: cifrado em repouso. Nunca é o PAN.
            'card_token' => 'encrypted',
        ];
    }

    /**
     * Mantém active_lock = user_id enquanto a assinatura está ativa, senão NULL.
     * Com o índice único em active_lock, isso garante no banco: no máximo uma
     * assinatura ativa por usuário (o guard no service é a primeira linha; este
     * é o backstop). active_lock não está em $fillable — é sempre derivado aqui.
     */
    protected static function booted(): void
    {
        static::saving(function (Subscription $subscription) {
            $subscription->active_lock = $subscription->status === 'active'
                ? $subscription->user_id
                : null;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(SubscriptionCharge::class);
    }

    /**
     * Dentro do trial de 7 dias do Founding Member: o Asaas ainda não cobrou nada
     * (a primeira cobrança está agendada para trial_ends_at), embora os tokens do
     * primeiro mês já estejam na carteira.
     */
    public function isInTrial(): bool
    {
        return $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /** Active and still inside the paid period. */
    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }
}
