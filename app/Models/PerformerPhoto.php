<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma foto da galeria PÚBLICA do perfil da performer (Sprint 10).
 *
 * É conteúdo de vitrine, na mesma categoria de `avatar_path`/`cover_path` — não
 * dado sensível. Diferente da Foto Efêmera do membro, não é cifrada, não tem TTL
 * e não gera trilha de audiência: quem a vê é qualquer visitante do perfil
 * público, e não há "quem viu" a preservar.
 *
 * O ciclo de vida (cap de 6, ordem, upload higienizado, delete) mora no
 * PerformerPhotoService; este model é só a linha. `performer_profile_id` fica
 * FORA do $fillable (a foto nasce sempre pela relação `$profile->photos()`), na
 * mesma disciplina anti mass-assignment do resto do projeto.
 */
class PerformerPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'path',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }
}
