<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Trilha de CADA verificação de hash no upload (avatar/capa/conteúdo). `matched`
 * = bateu na lista de CSAM; `verified` = o serviço de hash estava disponível
 * (false = fail-open, upload passou SEM verificação — registrado de propósito).
 *
 * `$fillable` só com os campos da trilha; nasce no CsamScanService.
 */
class ContentHashCheck extends Model
{
    protected $fillable = [
        'context', 'content_id', 'user_id', 'hash', 'matched', 'verified', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'matched' => 'boolean',
            'verified' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }
}
