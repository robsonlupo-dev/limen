<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * Uma foto da galeria de perfil do MEMBRO (feat/member-gallery-and-profile).
 *
 * Gêmea de PerformerVoiceIntro no ciclo de moderação (pending → approved/rejected
 * + reject_reason + moderated_by/at) e do avatar do membro no serving (disco
 * privado `local`, URL assinada chaveada num TOKEN OPACO). A regra de "quem vê"
 * (approved + dono com profile_visible) mora no MemberGalleryService/serving,
 * NUNCA aqui.
 *
 * $fillable vazio de propósito: nada nasce de array de request. path/token/
 * content_hash vêm do serviço; status/moderated_* por forceFill na moderação —
 * mesma disciplina de PerformerVoiceIntro e do avatar.
 */
class MemberGalleryPhoto extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** Teto de fotos ATIVAS (pending+approved) por membro. */
    public const MAX_ACTIVE = 4;

    protected $fillable = [];

    // path/token são o layout do serving; content_hash é prova, não conteúdo;
    // user_id é o id que o FanAlias esconde — nenhum sai em JSON.
    protected $hidden = ['path', 'token', 'content_hash', 'user_id'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
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

    /** Só o que pode ir ao ar no perfil/catálogo. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /** A fila de moderação: sanitizadas e aguardando um humano. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** Ativas = ocupam um dos 4 slots (pending ou approved). Rejeitada não conta. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /**
     * URL assinada e temporária dos bytes, ou null se não há arquivo/token.
     * Chaveada no `token` OPACO, NUNCA no id — o serving não expõe identificador
     * enumerável nem o member_id. Quem PODE receber esta URL é decidido no serviço
     * (o dono vê qualquer status; a performer só approved) — o model só a monta.
     */
    public function mediaUrl(): ?string
    {
        if ($this->path === '' || $this->path === null || ! $this->token) {
            return null;
        }

        return URL::temporarySignedRoute(
            'member.gallery.media',
            now()->addMinutes(60),
            ['token' => $this->token],
        );
    }
}
