<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma sessão de live pública da performer (Sprint 15, PR #139). Tabela criada no
 * #138. A live é GRÁTIS — nenhum débito/crédito passa por aqui; a receita vem de
 * gorjeta/presente (rotas próprias). `room_name` é o nome LiveKit imprevisível e
 * NÃO vaza em URL/prop/log (invariante do #138) — só viaja dentro do JWT curto.
 *
 * Dona única do ciclo de vida: App\Services\LiveSessionService.
 *
 * SEM member_id/relação de espectador de propósito: a live é 1:N e não existe
 * lista de "quem assistiu" (§2.7, espelho de story_views sem linhas). O
 * `viewer_count` é agregado histórico gravado no encerramento — nunca a fonte do
 * número exibido ao vivo (esse vem de listParticipants cacheado).
 */
class LiveSession extends Model
{
    protected $fillable = [
        'performer_profile_id', 'room_name', 'status', 'viewer_count',
        'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'viewer_count' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    /**
     * A live está PAUSADA (performer em chamada privada — feat/private-call-from-live)?
     * Sub-estado de uma live ATIVA: a sessão segue `status='live'`, então os viewers
     * não caem em 410; só veem o aviso "volta já" e o vídeo pausa.
     */
    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'live');
    }
}
