<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Slug abandonado por um rename de nome artístico (UAT fix, fase 1). Existe só
 * para a show 301-redirecionar um link antigo para o slug atual — ver
 * PerformerCatalogService::currentSlugForPrevious() e PerformerProfileService.
 *
 * Sem `updated_at`: uma linha nasce e nunca muda. Fora do backup/retenção comum
 * não é preciso — é o mapa mínimo (slug antigo → perfil) e some no Hard Delete
 * junto com o perfil (DeletionService::purgePreviousSlugs()).
 */
class PerformerProfilePreviousSlug extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['performer_profile_id', 'slug'];

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }
}
