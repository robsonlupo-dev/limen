<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha da junção `performer_tag`. Não existe entidade "Tag" no sistema: o
 * slug é o próprio valor, e o conjunto válido vive em PerformerProfile::TAGS,
 * validado pelo Form Request.
 *
 * O model é fino de propósito — existe porque uma relação Eloquent precisa de
 * uma classe do outro lado, não porque a tag seja um agregado. Quem escreve é
 * PerformerProfile::syncTags(); ninguém deve instanciar isto direto.
 */
class PerformerTag extends Model
{
    protected $table = 'performer_tag';

    /** A junção não tem created_at/updated_at — a tag não tem história própria. */
    public $timestamps = false;

    protected $fillable = ['tag_slug'];

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }
}
