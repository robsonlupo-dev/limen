<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um hash conhecido de CSAM (lista local). A lista real vem de parceria com
 * NCMEC/IWF (CNPJ) e é importada por `csam:import-hashes`; sobe vazia por ora.
 *
 * NUNCA sai em resposta pública: é a lista de bloqueio, e expô-la ensinaria a
 * contorná-la. Só o CsamScanService a consulta.
 */
class CsamHash extends Model
{
    protected $fillable = ['hash', 'source', 'added_at'];

    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
        ];
    }
}
