<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Models\PerformerProfile;
use App\Models\StoryHighlightItem;
use App\Services\HighlightStore;
use Illuminate\Http\Response;

/**
 * Serving público dos bytes de um item de destaque (roadmap social, Onda 1a).
 *
 * Destaque é VITRINE PÚBLICA (só stories públicos entram — ver
 * StoryHighlightService), então a rota é pública, como a da galeria de fotos.
 * Os bytes saem sempre pela camada do Store (re-sniff de Content-Type no
 * servidor), nunca por URL de disco.
 *
 * Mas "público" não é "eterno": um destaque é MÍDIA PERMANENTE, então sem uma
 * checagem de que a performer continua de pé, os bytes de uma conta suspensa
 * ou banida por moderação seguiriam sendo servidos para sempre por esta rota —
 * justamente o oposto do que a moderação decidiu. É a mesma condição (e a
 * mesma razão) de `StoryVisibilityService::performerIsReachable()`, só que
 * aqui o conteúdo não expira sozinho, então a checagem é ainda mais crítica.
 */
class HighlightController extends Controller
{
    use ServesPhotoBytes;

    public function __construct(private HighlightStore $store) {}

    public function image(StoryHighlightItem $item): Response
    {
        $profile = $item->highlight?->performerProfile;

        abort_if($profile === null || ! $this->performerIsReachable($profile), 404);

        return $this->photoResponse($this->store->retrieve($item->media_path), 'highlight.jpg');
    }

    /**
     * Perfil encerrado (soft delete) ou conta fora de `active` — suspensa,
     * pendente, banida — não tem destaque servível. `withTrashed()` no usuário
     * porque encerramento de conta é soft delete (item 11 do CLAUDE.md).
     */
    private function performerIsReachable(PerformerProfile $profile): bool
    {
        if ($profile->trashed()) {
            return false;
        }

        $user = $profile->user()->withTrashed()->first();

        return $user !== null && ! $user->trashed() && $user->status === 'active';
    }
}
