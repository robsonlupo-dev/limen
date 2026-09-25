<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Mensagem de catálogo pré-cadastrada (feat/catalog-message-templates). A
 * performer NÃO digita texto livre no alcance grátis ao catálogo de membros —
 * ela escolhe um destes modelos. Global (as mesmas para todas), editável no
 * admin. Ver a migration para o porquê de negócio.
 */
class ChatCatalogTemplate extends Model
{
    protected $fillable = [
        'body',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** Ativas, na ordem de exibição — o que a performer vê no seletor. */
    public function scopeActiveOrdered(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position')->orderBy('id');
    }

    /**
     * Renderiza o corpo com o apelido do membro no lugar de `{nome}`/`{apelido}`.
     * Sem apelido, o token some e a pontuação/espaço órfãos são limpos — nunca
     * "Oi, !" nem "Oi, Membro #0042" (o FanAlias cru não entra numa cantada). A
     * substituição é SEMPRE no servidor: o id/handle do membro nunca vai ao token.
     */
    public static function render(string $body, ?string $nickname): string
    {
        $name = trim((string) $nickname);

        if ($name !== '') {
            return trim(str_replace(['{nome}', '{apelido}'], $name, $body));
        }

        $out = str_replace(['{nome}', '{apelido}'], '', $body);
        $out = preg_replace('/\s{2,}/u', ' ', (string) $out);            // espaços duplos
        $out = preg_replace('/\s+([,.!?…])/u', '$1', (string) $out);      // espaço antes de pontuação
        $out = preg_replace('/,\s*([.!?…])/u', '$1', (string) $out);      // vírgula órfã antes de pontuação
        $out = preg_replace('/(^|[.!?…]\s*),\s*/u', '$1', (string) $out); // vírgula órfã no início/após pontuação

        return trim((string) $out);
    }
}
