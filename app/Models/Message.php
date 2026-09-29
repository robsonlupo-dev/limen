<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    // Retenção: ao vencer a carência do acesso, a mensagem é soft-deletada
    // (oculta na UI, retida no servidor p/ trilha de abuso/legal). Nunca
    // hard-delete — ver docs e a decisão de retenção do PO.
    use SoftDeletes;

    // Estados da mensagem de VOZ (feat/chat-voice-message). NULL = mensagem de
    // texto/presente. processing = subiu, ffmpeg rodando; ready = servível;
    // failed = não processou (o remetente reenvia).
    public const AUDIO_PROCESSING = 'processing';

    public const AUDIO_READY = 'ready';

    public const AUDIO_FAILED = 'failed';

    // Só o corpo vem de input do usuário. sender_id é setado pelo ChatService
    // (forceFill), nunca por mass assignment — fora do fillable p/ não forjar autor.
    protected $fillable = [
        'conversation_id',
        'body',
    ];

    // Caminho e hash do áudio NUNCA saem em serialização — o áudio é servido por
    // request autorizado, não por URL de disco (mesma disciplina da intro de voz).
    protected $hidden = [
        'audio_path',
        'audio_content_hash',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'redacted_at' => 'datetime',
            'ephemeral' => 'boolean',
            'revealed_at' => 'datetime',
            'audio_duration_seconds' => 'integer',
            'ppv_price_tokens' => 'integer',
        ];
    }

    /**
     * Mensagem PPV (conteúdo travado, Onda 4)? Marca pela PRESENÇA do preço — a mesma
     * convenção sem-coluna-de-tipo do áudio/presente. A peça vem do cofre da performer
     * (ppvContent); o direito do membro vive em content_unlocks, não aqui.
     */
    public function isPpv(): bool
    {
        return $this->ppv_price_tokens !== null;
    }

    /**
     * Mensagem EFÊMERA (modo vanish, Onda 2)? Carimbada no envio com o estado do
     * modo efêmero da conversa. Presente nunca é efêmero (é dinheiro).
     */
    public function isEphemeral(): bool
    {
        return (bool) $this->ephemeral;
    }

    /**
     * Efêmera JÁ REVELADA pelo destinatário (timer, Onda 3)? `revealed_at` é a fonte
     * única do consumo: revelar = consumir. Uma vez revelada, some da exibição nas
     * duas pontas em qualquer load seguinte (a contagem de X seg é só client-side).
     */
    public function isRevealed(): bool
    {
        return $this->revealed_at !== null;
    }

    /** Mensagem de voz? (marca pela presença do status de áudio.) */
    public function isAudio(): bool
    {
        return $this->audio_status !== null;
    }

    /**
     * O remetente "desfez o envio" (feat/chat-unsend-message)? É uma REDAÇÃO de
     * EXIBIÇÃO: o conteúdo some da tela das duas pontas, mas `body`/áudio seguem
     * no banco para a moderação. Nunca confundir com o soft-delete de retenção.
     */
    public function isRedacted(): bool
    {
        return $this->redacted_at !== null;
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    // Presente entregue no chat (feat/gift-from-profile). NULL na mensagem de
    // texto normal; presente aponta para o item do catálogo, usado só na
    // renderização (o dinheiro vive em gift_sends/token_ledger).
    public function gift(): BelongsTo
    {
        return $this->belongsTo(Gift::class);
    }

    // Story respondido (feat/story-reply-to-chat). NULL na mensagem normal; quando
    // presente, a mensagem é uma RESPOSTA a este story — a bolha mostra "Respondeu
    // ao story". nullOnDelete: o story efêmero some e a mensagem fica sem o ponteiro.
    public function replyToStory(): BelongsTo
    {
        return $this->belongsTo(PerformerStory::class, 'reply_to_story_id');
    }

    // Peça do cofre enviada travada como PPV (Onda 4). NULL na mensagem normal;
    // quando presente, a bolha é um PPV — a mídia é servida pela porta do conteúdo
    // (content.image/video) e só depois do desbloqueio. nullOnDelete: a peça apagada
    // deixa o ponteiro nulo e a bolha vira "indisponível".
    public function ppvContent(): BelongsTo
    {
        return $this->belongsTo(PerformerContent::class, 'ppv_content_id');
    }
}
