<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Intro de voz da performer (feat/voice-intro). UMA por performer (UNIQUE em
 * performer_profile_id). Higienizada por ffmpeg num job, depois moderada por
 * humano; só `approved` é servível no perfil. A regra de "quem toca" mora no
 * PerformerVoiceIntroService/serving, NUNCA aqui.
 *
 * $fillable vazio de propósito: nada nasce de array de request. path/content_hash
 * vêm do Store (job), status/duration do serviço, moderated_by/moderated_at por
 * forceFill na moderação — mesma disciplina de PerformerContent/is_private/2FA.
 */
class PerformerVoiceIntro extends Model
{
    // Nasce PROCESSING (job de ffmpeg). Sucesso do job → PENDING (aguarda humano).
    // Moderador → APPROVED/REJECTED. Falha do job → FAILED (regravar) — estado
    // técnico, distinto da recusa humana (REJECTED), como o vídeo do Sprint 16.
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [];

    // path é o layout do disco; content_hash é prova, não conteúdo — nenhum no JSON.
    protected $hidden = ['path', 'content_hash'];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Só a intro liberada para o perfil público. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /** A fila de moderação: sanitizadas e aguardando um humano. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }
}
