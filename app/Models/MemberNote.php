<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota PRIVADA da performer sobre um membro (Sprint 11).
 *
 * A performer anota o que quiser sobre um FanAlias — detalhes de conversa,
 * preferências — para se lembrar depois. **O membro NUNCA vê esta nota**, e
 * nenhuma superfície do lado do membro a expõe: não há relação inversa em
 * `User` que sirva de porta para um `withCount`/`with`, a nota não entra em
 * `audit_logs`, não entra em denúncia (`Report`) e não viaja em resource
 * nenhum do membro. A dona única da regra é App\Services\MemberNoteService.
 *
 * Consequências para quem mexer aqui:
 *
 *  - **`content` é cifrado em repouso** (cast `encrypted` sobre a APP_KEY). É
 *    opinião pessoal sobre alguém: PII sensível que não pode ficar legível no
 *    banco nem vazar por log. Rotacionar a APP_KEY torna as notas ilegíveis —
 *    mesma regra do 2FA e da foto efêmera.
 *  - **`user_id` é `$hidden`.** O id cru do membro nunca chega ao front da
 *    performer — o que vai para a tela é o `FanAlias::handle`, derivado por par.
 *    Defesa em profundidade: se a model for serializada por engano para dentro
 *    de um prop, o id do membro não vai junto.
 *
 * FKs fora do `$fillable`: a linha só nasce/muda no MemberNoteService, a partir
 * de objetos `PerformerProfile`/`User` já resolvidos e do texto validado —
 * nunca de um array de request. Mesma disciplina de `favorites` e
 * `member_photo_access`.
 *
 * ── Ressalva de segurança (registrada no CLAUDE.md) ─────────────────────────
 * O `FanAlias` é estável por par, e a nota cola no MESMO pseudônimo que já
 * carrega gorjetas e a linha de seguidor. Somando lifestyle_tier + nota +
 * gorjetas, a performer monta um dossiê por FanAlias. É correlação DENTRO de um
 * perfil (o alias não cruza perfis, por construção do HMAC), e é o preço de dar
 * à performer memória sobre "o Membro #0042 de sempre" — decisão de produto.
 */
class MemberNote extends Model
{
    protected $fillable = [];

    protected $hidden = ['user_id'];

    protected function casts(): array
    {
        return [
            'content' => 'encrypted',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
