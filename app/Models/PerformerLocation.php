<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma localização declarada pela performer (Sprint 13). A performer pode ter até
 * PerformerProfile::MAX_LOCATIONS, uma delas primária.
 *
 * ── A assimetria estado/cidade é a mesma da localização única (Sprint 9A) ────
 * `state` (UF) é público — sai no resource, no card, no perfil e no filtro.
 * `city` é INTERNO: guardado porque a performer o digitou, nunca EXIBIDO.
 * Estado é grosso o bastante (27 unidades) para não localizar ninguém; município
 * nomeia um lugar, e num produto onde a performer depende de não ser encontrada
 * isso é outra categoria de dado. A porta pública é `PerformerProfile::locationStates()`,
 * que devolve só as UFs — `city` não passa por lá. Travado por teste.
 *
 * ── Filtro de cidade CONSENTIDO (item 4 da fila) ─────────────────────────────
 * `city` continua fora de todo resource — nunca é exibida. A única forma de ela
 * afetar algo público é o filtro de cidade, e SÓ para quem ligou o opt-in
 * `performer_profiles.findable_by_city` (default OFF). `city_normalized` é a
 * chave de busca acento-insensível que esse filtro consulta (ver
 * PerformerProfile::scopeInCity); é DERIVADA pelo Service a partir de `city`,
 * por isso fica FORA do `$fillable` — não é dado que a performer manda, é o
 * servidor espelhando o que gravou.
 *
 * As linhas só nascem pelo `PerformerLocationService` (delete+create sob lock),
 * nunca de um `create($request->all())`: `performer_profile_id`, `is_primary` e
 * `position` são autoridade do servidor. Por isso ficam FORA do `$fillable` —
 * mesma disciplina de `discrete_mode`, do 2FA e do `is_invite` do story.
 */
class PerformerLocation extends Model
{
    /**
     * Só o que a performer digita/escolhe por linha. `performer_profile_id`,
     * `is_primary` e `position` são derivados no Service — a primária é a linha
     * que a performer marcou, e a posição é a ordem em que ela as mandou.
     */
    protected $fillable = ['state', 'city'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }
}
